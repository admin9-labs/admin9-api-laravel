<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMenuRequest;
use App\Http\Requests\Admin\UpdateMenuRequest;
use App\Http\Resources\Admin\MenuResource;
use App\Models\Menu;
use App\Models\Permission;
use App\Support\Admin\AdminPermissionChecker;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MenuController extends Controller
{
    public function __construct(private AdminPermissionChecker $permissionChecker) {}

    /**
     * Return the complete bounded admin menu catalog for management UIs.
     */
    public function index(): JsonResponse
    {
        return $this->success(MenuResource::collection(
            Menu::query()->with(['children', 'permission'])->ordered()->get()
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
            ->with('permission')
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
        $menu = DB::transaction(
            fn (): Menu => Menu::query()->create($this->menuAttributes($request->validated()))
        );

        return $this->success([
            'menu' => MenuResource::make($menu->load(['children', 'permission'])),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Menu $menu): JsonResponse
    {
        return $this->success([
            'menu' => MenuResource::make($menu->load(['children', 'permission'])),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMenuRequest $request, Menu $menu): JsonResponse
    {
        DB::transaction(function () use ($request, $menu): void {
            $menu->update($this->menuAttributes($request->validated()));
        });

        return $this->success([
            'menu' => MenuResource::make($menu->refresh()->load(['children', 'permission'])),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Menu $menu): JsonResponse
    {
        if ($menu->children()->exists()) {
            return $this->error('Menus with child menus cannot be deleted.', 422);
        }

        DB::transaction(function () use ($menu): void {
            $menu->delete();
        });

        return $this->success(message: 'deleted');
    }

    private function canViewMenu(Menu $menu, ?Authenticatable $user): bool
    {
        if ($menu->permission === null) {
            return true;
        }

        return $user !== null && $this->permissionChecker->canAccessPermission($user, $menu->permission);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function menuAttributes(array $attributes): array
    {
        $hasPermissionId = array_key_exists('permission_id', $attributes);

        unset($attributes['permission_name']);

        if (! $hasPermissionId) {
            return $attributes;
        }

        $permissionId = $attributes['permission_id'];
        if ($permissionId === null) {
            $attributes['permission_name'] = null;

            return $attributes;
        }

        $attributes['permission_name'] = Permission::query()
            ->admin()
            ->whereKey((int) $permissionId)
            ->value('name');

        return $attributes;
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
