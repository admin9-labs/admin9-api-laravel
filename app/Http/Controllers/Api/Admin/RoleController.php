<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\SyncRolePermissionsRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Resources\Admin\RoleResource;
use App\Models\Permission;
use App\Support\Admin\ReservedAdminRole;
use App\Support\Audit\AdminActivityRecorder;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Mitoop\Http\Exceptions\ClientSafeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function __construct(private AdminActivityRecorder $activityRecorder) {}

    /**
     * Return the complete bounded admin role catalog for assignment UIs.
     */
    public function index(): JsonResponse
    {
        return $this->success(RoleResource::collection(
            Role::query()->where('guard_name', 'admin')->with('permissions')->orderBy('id')->get()
        ));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        /** @var array<int, string> $permissions */
        $permissions = $request->validated('permissions', []);
        $shouldSyncPermissions = $request->has('permissions');

        $role = $this->runRoleWriteTransaction($permissions, function (Collection $permissions) use ($request, $shouldSyncPermissions): Role {
            $role = Role::query()->create([
                'name' => $request->validated('name'),
                'guard_name' => 'admin',
            ]);

            if ($shouldSyncPermissions) {
                $role->syncPermissions($permissions);
            }

            $role = $role->refresh()->load('permissions');
            $this->activityRecorder->record($role, 'created', [
                'attributes' => [
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'permissions' => $role->permissions->pluck('name')->values()->all(),
                ],
            ]);

            return $role;
        });

        return $this->success([
            'role' => RoleResource::make($role),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Role $role): JsonResponse
    {
        $this->abortIfNotAdminGuard($role);

        return $this->success([
            'role' => RoleResource::make($role->load('permissions')),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $this->abortIfNotAdminGuard($role);
        $this->abortIfReservedRole($role);

        /** @var array<int, string> $permissions */
        $permissions = $request->validated('permissions', []);
        $shouldSyncPermissions = $request->has('permissions');

        $role = $this->runRoleWriteTransaction($permissions, function (Collection $permissions) use ($request, $role, $shouldSyncPermissions): Role {
            $role = $this->lockRoleForUpdate($role);
            $role->update($request->safe(['name']));

            if ($shouldSyncPermissions) {
                $role->syncPermissions($permissions);
            }

            $role = $role->refresh()->load('permissions');
            $this->activityRecorder->record($role, 'updated', [
                'attributes' => [
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'permissions' => $role->permissions->pluck('name')->values()->all(),
                ],
            ]);

            return $role;
        });

        return $this->success([
            'role' => RoleResource::make($role),
        ]);
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $this->abortIfNotAdminGuard($role);
        $this->abortIfReservedRole($role);

        /** @var array<int, string> $permissions */
        $permissions = $request->validated('permissions');

        $role = $this->runRoleWriteTransaction($permissions, function (Collection $permissions) use ($role): Role {
            $role = $this->lockRoleForUpdate($role);
            $role->syncPermissions($permissions);
            $role = $role->refresh()->load('permissions');
            $this->activityRecorder->record($role, 'permissions_synced', [
                'attributes' => [
                    'permissions' => $role->permissions->pluck('name')->values()->all(),
                ],
            ]);

            return $role;
        });

        return $this->success([
            'role' => RoleResource::make($role),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Role $role): JsonResponse
    {
        $this->abortIfNotAdminGuard($role);
        $this->abortIfReservedRole($role);

        DB::transaction(function () use ($role): void {
            $role = $this->lockRoleForUpdate($role);
            $attributes = ['name' => $role->name, 'guard_name' => $role->guard_name];
            $role->delete();
            $this->activityRecorder->record($role, 'deleted', ['old' => $attributes]);
            DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
        }, 3);

        return $this->success(message: 'deleted');
    }

    /**
     * @param  array<int, string>  $permissions
     * @param  Closure(Collection<int, Permission>): Role  $callback
     */
    private function runRoleWriteTransaction(array $permissions, Closure $callback): Role
    {
        return DB::transaction(function () use ($permissions, $callback): Role {
            $selectedPermissions = Permission::query()
                ->admin()
                ->whereIn('name', $permissions)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($selectedPermissions->count() !== count(array_unique($permissions))) {
                throw new ClientSafeException(
                    'One or more selected permissions no longer exist.',
                    null,
                    422,
                );
            }

            $role = $callback($selectedPermissions);
            DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());

            return $role;
        }, 3);
    }

    private function lockRoleForUpdate(Role $role): Role
    {
        $role = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
        $this->abortIfNotAdminGuard($role);
        $this->abortIfReservedRole($role);

        return $role;
    }

    private function abortIfNotAdminGuard(Role $role): void
    {
        abort_if($role->guard_name !== 'admin', 404, 'Role not found');
    }

    private function abortIfReservedRole(Role $role): void
    {
        abort_if(ReservedAdminRole::isReserved($role), 422, 'Reserved roles cannot be modified.');
    }
}
