<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMenuRequest;
use App\Http\Requests\Admin\UpdateMenuRequest;
use App\Http\Resources\Admin\MenuResource;
use App\Models\Menu;
use App\Support\Admin\AdminPermissionChecker;
use App\Support\Admin\MenuHierarchyValidator;
use App\Support\Audit\AdminActivityRecorder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MenuController extends Controller
{
    public function __construct(
        private AdminPermissionChecker $permissionChecker,
        private AdminActivityRecorder $activityRecorder,
        private MenuHierarchyValidator $hierarchyValidator,
    ) {}

    /**
     * Return the complete bounded admin menu catalog for management UIs.
     */
    public function index(): JsonResponse
    {
        return $this->success(MenuResource::collection(
            Menu::query()->with(['children.permissions', 'permissions'])->ordered()->get()
        ));
    }

    /**
     * Return the complete bounded visible menu tree for admin shell navigation.
     */
    public function tree(Request $request): JsonResponse
    {
        $menus = Menu::query()
            ->active()
            ->visible()
            ->navigation()
            ->ordered()
            ->with('permissions')
            ->get();
        $user = $request->user('admin');

        $filteredMenus = $menus->filter(
            fn (Menu $menu): bool => $this->canViewMenu($menu, $user)
        );

        return $this->success(MenuResource::collection($this->toTree($filteredMenus)));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMenuRequest $request): JsonResponse
    {
        $validated = $request->validated();
        /** @var array<int, int> $permissionIds */
        $permissionIds = Arr::pull($validated, 'permission_ids', []);

        $menu = DB::transaction(function () use ($validated, $permissionIds): Menu {
            $parentId = $validated['parent_id'] ?? null;
            $parent = $parentId === null
                ? null
                : Menu::query()->whereKey($parentId)->lockForUpdate()->first();

            if ($parentId !== null && ! $parent instanceof Menu) {
                throw ValidationException::withMessages([
                    'parent_id' => [__('validation.exists', ['attribute' => 'parent id'])],
                ]);
            }

            $snapshot = $parent instanceof Menu ? $this->menuSnapshot(collect([$parent])) : [];
            $this->assertValidHierarchy(
                $snapshot,
                null,
                (string) ($validated['type'] ?? Menu::TYPE_PAGE),
                $parentId === null ? null : (int) $parentId,
            );

            $menu = Menu::query()->create($validated);
            $menu->permissions()->sync($permissionIds);
            $menu->load('permissions');

            if ($permissionIds !== []) {
                $this->recordPermissionSync($menu, ['permission_ids' => [], 'permission_names' => []]);
            }

            return $menu;
        });

        return $this->success([
            'menu' => MenuResource::make($menu->load('children.permissions')),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Menu $menu): JsonResponse
    {
        return $this->success([
            'menu' => MenuResource::make($menu->load(['children.permissions', 'permissions'])),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMenuRequest $request, Menu $menu): JsonResponse
    {
        $validated = $request->validated();
        $shouldSyncPermissions = $request->exists('permission_ids');
        /** @var array<int, int> $permissionIds */
        $permissionIds = Arr::pull($validated, 'permission_ids', []);

        $menu = DB::transaction(function () use ($menu, $permissionIds, $shouldSyncPermissions, $validated): Menu {
            if (array_key_exists('parent_id', $validated) || array_key_exists('type', $validated)) {
                $lockedMenus = $this->lockMenuGraph();
                $snapshot = $this->menuSnapshot($lockedMenus);
                $menu = $lockedMenus->firstWhere('id', $menu->getKey());

                if (! $menu instanceof Menu) {
                    abort(404);
                }

                $parentId = array_key_exists('parent_id', $validated)
                    ? ($validated['parent_id'] === null ? null : (int) $validated['parent_id'])
                    : ($menu->parent_id === null ? null : (int) $menu->parent_id);

                $this->assertValidHierarchy(
                    $snapshot,
                    (int) $menu->getKey(),
                    (string) ($validated['type'] ?? $menu->type),
                    $parentId,
                );
            } else {
                $menu = Menu::query()->whereKey($menu->getKey())->lockForUpdate()->firstOrFail();
            }

            $menu->load('permissions');
            $oldPermissions = $this->permissionAuditSnapshot($menu);
            $menu->update($validated);

            if ($shouldSyncPermissions) {
                $menu->permissions()->sync($permissionIds);
                $menu->load('permissions');

                if ($oldPermissions !== $this->permissionAuditSnapshot($menu)) {
                    $this->recordPermissionSync($menu, $oldPermissions);
                }
            }

            return $menu->refresh()->load(['children.permissions', 'permissions']);
        }, 5);

        return $this->success([
            'menu' => MenuResource::make($menu),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Menu $menu): JsonResponse
    {
        $deleted = DB::transaction(function () use ($menu): bool {
            $lockedMenus = $this->lockMenuGraph();
            $lockedMenu = $lockedMenus->firstWhere('id', $menu->getKey());

            if (! $lockedMenu instanceof Menu) {
                return true;
            }

            if ($lockedMenus->contains(
                fn (Menu $candidate): bool => (int) $candidate->parent_id === (int) $lockedMenu->getKey()
            )) {
                return false;
            }

            $seedKey = $lockedMenu->getAttribute('seed_key');

            if (is_string($seedKey) && $seedKey !== '') {
                DB::table('menu_seed_tombstones')->insertOrIgnore([
                    'seed_key' => $seedKey,
                    'deleted_at' => now(),
                ]);
            }

            $lockedMenu->delete();

            return true;
        }, 5);

        if (! $deleted) {
            return $this->error('Menus with child menus cannot be deleted.', 422);
        }

        return $this->success(message: 'deleted');
    }

    /**
     * Lock menu rows in one deterministic order for every graph mutation.
     *
     * @return Collection<int, Menu>
     */
    private function lockMenuGraph(): Collection
    {
        return Menu::query()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, Menu>  $menus
     * @return array<int, array{id: int, parent_id: ?int, type: string}>
     */
    private function menuSnapshot(Collection $menus): array
    {
        return $menus->mapWithKeys(static fn (Menu $lockedMenu): array => [
            (int) $lockedMenu->getKey() => [
                'id' => (int) $lockedMenu->getKey(),
                'parent_id' => $lockedMenu->parent_id === null ? null : (int) $lockedMenu->parent_id,
                'type' => (string) $lockedMenu->type,
            ],
        ])->all();
    }

    /**
     * @param  array<int, array{id: int, parent_id: ?int, type: string}>  $menusById
     */
    private function assertValidHierarchy(array $menusById, ?int $menuId, string $type, ?int $parentId): void
    {
        $errors = $this->hierarchyValidator->errors($menusById, $menuId, $type, $parentId);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function canViewMenu(Menu $menu, ?Authenticatable $user): bool
    {
        if ($menu->permissions->isEmpty()) {
            return true;
        }

        return $user !== null && $this->permissionChecker->canAccessAnyPermission($user, $menu->permissions);
    }

    /**
     * @return array{permission_ids: array<int, int>, permission_names: array<int, string>}
     */
    private function permissionAuditSnapshot(Menu $menu): array
    {
        $permissions = $menu->permissions->sortBy('id')->values();

        return [
            'permission_ids' => $permissions->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'permission_names' => $permissions->pluck('name')->all(),
        ];
    }

    /**
     * @param  array{permission_ids: array<int, int>, permission_names: array<int, string>}  $oldPermissions
     */
    private function recordPermissionSync(Menu $menu, array $oldPermissions): void
    {
        $this->activityRecorder->record($menu, 'permissions_synced', [
            'old' => $oldPermissions,
            'attributes' => $this->permissionAuditSnapshot($menu),
        ]);
    }

    /**
     * @param  Collection<int, Menu>  $menus
     * @return Collection<int, Menu>
     */
    private function toTree(Collection $menus): Collection
    {
        $menusByParent = $menus->groupBy('parent_id');

        return $this->attachChildren($menusByParent, null);
    }

    /**
     * @param  Collection<int|string, Collection<int, Menu>>  $menusByParent
     * @return Collection<int, Menu>
     */
    private function attachChildren(Collection $menusByParent, ?int $parentId): Collection
    {
        return $menusByParent->get($parentId, collect())
            ->values()
            ->each(function (Menu $menu) use ($menusByParent): void {
                $menu->setRelation('children', $this->attachChildren($menusByParent, $menu->id));
            });
    }
}
