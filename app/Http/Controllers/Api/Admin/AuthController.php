<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use App\Http\Resources\Admin\UserResource;
use App\Models\Permission;
use App\Models\User;
use App\Support\Admin\ReservedAdminRole;
use App\Support\Auth\LoginLogRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(private LoginLogRecorder $loginLogRecorder) {}

    public function login(LoginRequest $request): JsonResponse
    {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->validated();

        $user = User::where('email', $credentials['email'])->first();
        if ($user !== null && ! $user->is_active) {
            $this->loginLogRecorder->record($request, 'admin', 'login', false, $credentials['email'], $user, 'Account disabled');

            return $this->error('Account disabled', Response::HTTP_FORBIDDEN);
        }

        $token = $this->guard()->attempt($credentials);
        if ($token === false) {
            $this->loginLogRecorder->record($request, 'admin', 'login', false, $credentials['email'], $user, 'Invalid credentials');

            return $this->error('Invalid credentials', Response::HTTP_UNAUTHORIZED);
        }

        $token = (string) $token;

        $this->recordLogin($request, $this->guard()->user());
        $this->loginLogRecorder->record($request, 'admin', 'login', true, $credentials['email'], $this->guard()->user());

        return $this->success($this->tokenPayload($token));
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('admin');

        return $this->success($this->identityPayload($user));
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $this->guard()->user();
        $token = $this->guard()->refresh();
        $this->loginLogRecorder->record($request, 'admin', 'refresh', true, $user?->email, $user);

        return $this->success($this->tokenPayload($token));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $this->guard()->user();
        $this->guard()->logout();
        $this->loginLogRecorder->record($request, 'admin', 'logout', true, $user?->email, $user);

        return $this->success(message: 'logged out');
    }

    private function guard(): JWTGuard
    {
        /** @var JWTGuard $guard */
        $guard = Auth::guard('admin');

        return $guard;
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, user: UserResource, permission_names: list<string>}
     */
    private function tokenPayload(string $token): array
    {
        /** @var User $user */
        $user = $this->guard()->user();

        return [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $this->tokenTtlSeconds(),
            ...$this->identityPayload($user),
        ];
    }

    private function tokenTtlSeconds(): int
    {
        return (int) $this->guard()->factory()->getTTL() * 60;
    }

    /**
     * @return array{user: UserResource, permission_names: list<string>}
     */
    private function identityPayload(User $user): array
    {
        /** @var list<string> $permissionNames */
        $permissionNames = $this->permissionNamesFor($user);

        return [
            'user' => UserResource::make($user->load('roles')),
            'permission_names' => $permissionNames,
        ];
    }

    /**
     * @return list<string>
     */
    private function permissionNamesFor(User $user): array
    {
        if (ReservedAdminRole::userIsSuperAdmin($user)) {
            return Permission::query()
                ->where('guard_name', 'admin')
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name')
                ->all();
        }

        return $user->getAllPermissions()
            ->filter(fn (mixed $permission): bool => $permission instanceof Permission
                && $permission->guard_name === 'admin'
                && (bool) $permission->getAttribute('is_active'))
            ->pluck('name')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function recordLogin(Request $request, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();
    }
}
