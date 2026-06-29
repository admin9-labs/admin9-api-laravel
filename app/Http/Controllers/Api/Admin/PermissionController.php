<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePermissionRequest;
use App\Http\Requests\Admin\UpdatePermissionRequest;
use App\Http\Resources\Admin\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class PermissionController extends Controller
{
    private const ADMIN_GUARD = 'admin';

    /**
     * Return the complete bounded RBAC permission catalog for configuration UIs.
     */
    public function index(): JsonResponse
    {
        $permissions = Permission::query()
            ->where('guard_name', self::ADMIN_GUARD)
            ->orderBy('group')
            ->orderBy('sort')
            ->orderBy('name')
            ->get();

        return $this->success(PermissionResource::collection($permissions));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePermissionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $permission = DB::transaction(function () use ($validated): Permission {
            $permission = Permission::query()->create($this->permissionAttributes($validated, creating: true));

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $permission;
        });

        return $this->success([
            'permission' => PermissionResource::make($permission->refresh()),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Permission $permission): JsonResponse
    {
        $this->abortIfNotAdminGuard($permission);

        return $this->success([
            'permission' => PermissionResource::make($permission),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePermissionRequest $request, Permission $permission): JsonResponse
    {
        $this->abortIfNotAdminGuard($permission);
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $permission): void {
            $attributes = $this->permissionAttributes($validated, creating: false);

            if ($this->isSystemPermission($permission) && isset($attributes['name']) && $attributes['name'] !== $permission->name) {
                throw ValidationException::withMessages([
                    'name' => ['System permissions cannot be renamed.'],
                ]);
            }

            if ($attributes !== []) {
                $permission->update($attributes);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });

        return $this->success([
            'permission' => PermissionResource::make($permission->refresh()),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Permission $permission): JsonResponse
    {
        $this->abortIfNotAdminGuard($permission);

        if ($this->isSystemPermission($permission)) {
            return $this->error('System permissions cannot be deleted.', 422);
        }

        if ($permission->roles()->exists() || $permission->users()->exists()) {
            return $this->error('Assigned permissions cannot be deleted.', 422);
        }

        DB::transaction(function () use ($permission): void {
            $permission->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });

        return $this->success(message: 'deleted');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function permissionAttributes(array $validated, bool $creating): array
    {
        $attributes = Arr::only($validated, [
            'name',
            'display_name',
            'group',
            'description',
            'sort',
            'is_active',
        ]);

        $attributes['guard_name'] = self::ADMIN_GUARD;

        if ($creating) {
            $attributes['is_system'] = false;
        }

        return $attributes;
    }

    private function isSystemPermission(Permission $permission): bool
    {
        return (bool) $permission->getAttribute('is_system');
    }

    private function abortIfNotAdminGuard(Permission $permission): void
    {
        abort_if($permission->guard_name !== self::ADMIN_GUARD, 404, 'Permission not found');
    }
}
