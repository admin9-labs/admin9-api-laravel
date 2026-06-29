<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\SyncRolePermissionsRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Resources\Admin\RoleResource;
use App\Support\Admin\ReservedAdminRole;
use App\Support\Audit\AdminActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

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
        $permissions = $request->validated('permissions', []);
        $shouldSyncPermissions = $request->has('permissions');

        $role = DB::transaction(function () use ($request, $permissions, $shouldSyncPermissions): Role {
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
                    'permissions' => $permissions,
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

        $permissions = $request->validated('permissions', []);
        $shouldSyncPermissions = $request->has('permissions');

        $role = DB::transaction(function () use ($request, $role, $permissions, $shouldSyncPermissions): Role {
            $role->update($request->safe(['name']));

            if ($shouldSyncPermissions) {
                $role->syncPermissions($permissions);
            }

            $role = $role->refresh()->load('permissions');
            $this->activityRecorder->record($role, 'updated', [
                'attributes' => [
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'permissions' => $permissions,
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

        $role = DB::transaction(function () use ($request, $role): Role {
            $role->syncPermissions($request->validated('permissions'));
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
            $attributes = ['name' => $role->name, 'guard_name' => $role->guard_name];
            $role->delete();
            $this->activityRecorder->record($role, 'deleted', ['old' => $attributes]);
        });

        return $this->success(message: 'deleted');
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
