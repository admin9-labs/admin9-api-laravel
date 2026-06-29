<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Support\Admin\ReservedAdminRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
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

        $this->assertUserCanBeUpdated($request, $user, $validated);

        DB::transaction(function () use ($validated, $user): void {
            $user->update($validated);
        });

        return $this->success([
            'user' => UserResource::make($user->refresh()->load('roles')),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->assertUserCanBeDeleted($request, $user);

        DB::transaction(function () use ($user): void {
            $user->delete();
        });

        return $this->success(message: 'deleted');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertUserCanBeUpdated(UpdateUserRequest $request, User $user, array $validated): void
    {
        if (! array_key_exists('is_active', $validated) || (bool) $validated['is_active']) {
            return;
        }

        if ($this->isCurrentAdmin($request, $user)) {
            throw ValidationException::withMessages([
                'is_active' => ['You cannot disable your own admin account.'],
            ]);
        }

        if ($this->isLastActiveSuperAdmin($user)) {
            throw ValidationException::withMessages([
                'is_active' => ['The last active super-admin cannot be disabled.'],
            ]);
        }
    }

    private function assertUserCanBeDeleted(Request $request, User $user): void
    {
        if ($this->isCurrentAdmin($request, $user)) {
            throw ValidationException::withMessages([
                'user' => ['You cannot delete your own admin account.'],
            ]);
        }

        if ($this->isLastActiveSuperAdmin($user)) {
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

    private function isLastActiveSuperAdmin(User $user): bool
    {
        return $user->is_active
            && ReservedAdminRole::userIsSuperAdmin($user)
            && ReservedAdminRole::activeSuperAdminCount() <= 1;
    }
}
