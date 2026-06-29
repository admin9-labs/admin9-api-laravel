<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncUserRolesRequest;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Support\Admin\ReservedAdminRole;
use App\Support\Audit\AdminActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserRoleController extends Controller
{
    public function __construct(private AdminActivityRecorder $activityRecorder) {}

    public function update(SyncUserRolesRequest $request, User $user): JsonResponse
    {
        $roles = $request->validated('roles');
        $actor = $request->user('admin');

        if (! $actor instanceof User || ! ReservedAdminRole::userIsSuperAdmin($actor)) {
            $this->assertReservedRolesUnchanged($user, $roles);
        }

        $this->assertLastSuperAdminKeepsRole($user, $roles);

        $user = DB::transaction(function () use ($roles, $user): User {
            $user->syncRoles($roles);
            $user = $user->refresh()->load('roles');
            $this->activityRecorder->record($user, 'roles_synced', [
                'attributes' => [
                    'roles' => $user->roles->pluck('name')->values()->all(),
                ],
            ]);

            return $user;
        });

        return $this->success([
            'user' => UserResource::make($user),
        ]);
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function assertReservedRolesUnchanged(User $user, array $roles): void
    {
        $currentReservedRoles = $user->roles()
            ->where('guard_name', 'admin')
            ->whereIn('name', ReservedAdminRole::names())
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
        $nextReservedRoles = collect($roles)
            ->intersect(ReservedAdminRole::names())
            ->sort()
            ->values()
            ->all();

        if ($currentReservedRoles !== $nextReservedRoles) {
            throw ValidationException::withMessages([
                'roles' => ['Only super-admin users may grant or remove reserved admin roles.'],
            ]);
        }
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function assertLastSuperAdminKeepsRole(User $user, array $roles): void
    {
        if (! $user->is_active || ! ReservedAdminRole::userIsSuperAdmin($user) || in_array(ReservedAdminRole::SUPER_ADMIN, $roles, true)) {
            return;
        }

        if (ReservedAdminRole::activeSuperAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'roles' => ['The last active super-admin cannot lose the super-admin role.'],
            ]);
        }
    }
}
