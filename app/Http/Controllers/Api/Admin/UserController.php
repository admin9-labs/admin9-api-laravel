<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Support\Admin\ReservedAdminRole;
use App\Support\Audit\SecurityActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(private SecurityActivityRecorder $activityRecorder) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        return $this->success(UserResource::collection(
            User::query()->with('roles')->orderByDesc('id')->paginate()
        ));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = DB::transaction(fn (): User => User::query()->create($request->validated()));

        return $this->success([
            'user' => UserResource::make($user->load('roles')),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user): JsonResponse
    {
        return $this->success([
            'user' => UserResource::make($user->load('roles')),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($request, $user, $validated): User {
            $activeSuperAdminIds = collect();

            if (array_key_exists('is_active', $validated) && ! (bool) $validated['is_active']) {
                $activeSuperAdminIds = ReservedAdminRole::activeSuperAdminIdsForUpdate();
            }

            $user = ReservedAdminRole::lockUserForUpdate($user);
            $this->assertReservedRoleHolderCanBeManaged($request, $user);

            if (array_key_exists('is_active', $validated) && ! (bool) $validated['is_active']) {
                $this->assertUserCanBeDisabled($request, $user, $activeSuperAdminIds);
            }

            $user->fill($validated);

            $authenticationMustBeInvalidated = $user->isDirty('email')
                || ($user->isDirty('is_active') && ! $user->is_active);

            if ($authenticationMustBeInvalidated) {
                $user->forceFill([
                    'auth_version' => $user->auth_version + 1,
                ]);
            }

            $user->save();

            return $user;
        }, attempts: 3);

        return $this->success([
            'user' => UserResource::make($user->refresh()->load('roles')),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        DB::transaction(function () use ($request, $user): void {
            $activeSuperAdminIds = ReservedAdminRole::activeSuperAdminIdsForUpdate();
            $user = ReservedAdminRole::lockUserForUpdate($user);

            $this->assertUserCanBeDeleted($request, $user, $activeSuperAdminIds);
            $user->delete();
        }, attempts: 3);

        return $this->success(message: 'deleted');
    }

    public function resetPassword(ResetUserPasswordRequest $request, User $user): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user('admin');
        $password = $request->validated('password');

        DB::transaction(function () use ($actor, $password, $request, $user): void {
            $user = ReservedAdminRole::lockUserForUpdate($user);

            if ($this->isCurrentAdmin($request, $user)) {
                throw ValidationException::withMessages([
                    'user' => ['Use the current-password change endpoint for your own account.'],
                ]);
            }

            $this->assertReservedRoleHolderCanBeManaged($request, $user);

            $user->forceFill([
                'password' => $password,
                'auth_version' => $user->auth_version + 1,
            ])->save();

            if ($actor instanceof User) {
                $this->activityRecorder->record($user, $actor, 'admin', 'password_reset');
            }
        });

        return $this->success(message: 'password reset');
    }

    /**
     * @param  Collection<int, int>  $activeSuperAdminIds
     */
    private function assertUserCanBeDisabled(UpdateUserRequest $request, User $user, Collection $activeSuperAdminIds): void
    {
        if ($this->isCurrentAdmin($request, $user)) {
            throw ValidationException::withMessages([
                'is_active' => ['You cannot disable your own admin account.'],
            ]);
        }

        if (ReservedAdminRole::isLastActiveSuperAdmin($user, $activeSuperAdminIds)) {
            throw ValidationException::withMessages([
                'is_active' => ['The last active super-admin cannot be disabled.'],
            ]);
        }
    }

    /**
     * @param  Collection<int, int>  $activeSuperAdminIds
     */
    private function assertUserCanBeDeleted(Request $request, User $user, Collection $activeSuperAdminIds): void
    {
        if ($this->isCurrentAdmin($request, $user)) {
            throw ValidationException::withMessages([
                'user' => ['You cannot delete your own admin account.'],
            ]);
        }

        $this->assertReservedRoleHolderCanBeManaged($request, $user);

        if (ReservedAdminRole::isLastActiveSuperAdmin($user, $activeSuperAdminIds)) {
            throw ValidationException::withMessages([
                'user' => ['The last active super-admin cannot be deleted.'],
            ]);
        }
    }

    private function isCurrentAdmin(Request $request, User $user): bool
    {
        return $request->user('admin') instanceof User
            && $request->user('admin')->is($user);
    }

    private function assertReservedRoleHolderCanBeManaged(Request $request, User $user): void
    {
        $actor = $request->user('admin');

        if (ReservedAdminRole::userHasReservedRole($user)
            && (! $actor instanceof User || ! ReservedAdminRole::userIsSuperAdmin($actor))) {
            throw ValidationException::withMessages([
                'user' => ['Only super-admin users may manage accounts with reserved admin roles.'],
            ]);
        }
    }
}
