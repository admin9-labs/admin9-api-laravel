<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Support\Auth\ChangePassword;
use App\Support\Auth\LoginLogRecorder;
use App\Support\Auth\RefreshJwtToken;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(
        private LoginLogRecorder $loginLogRecorder,
        private RefreshJwtToken $refreshJwtToken,
        private ChangePassword $changePasswordAction,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        /** @var array{account: string, password: string} $validated */
        $validated = $request->validated();
        $account = $validated['account'];
        $identifierField = filter_var($account, FILTER_VALIDATE_EMAIL) !== false ? 'email' : 'mobile';

        $member = Member::where($identifierField, $account)->first();

        if ($member !== null && ! $member->is_active) {
            $this->loginLogRecorder->record($request, 'member', 'login', false, $account, $member, 'Account disabled');

            return $this->error('Invalid credentials', Response::HTTP_UNAUTHORIZED);
        }

        $credentials = [$identifierField => $account, 'password' => $validated['password']];

        $token = $this->guard()->attempt($credentials);
        if ($token === false) {
            $this->loginLogRecorder->record($request, 'member', 'login', false, $account, $member, 'Invalid credentials');

            return $this->error('Invalid credentials', Response::HTTP_UNAUTHORIZED);
        }

        $token = (string) $token;
        /** @var Member $member */
        $member = $this->guard()->user();

        $this->recordLogin($request, $member);
        $this->loginLogRecorder->record($request, 'member', 'login', true, $account, $member);

        return $this->success($this->tokenPayload($token, $member));
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success([
            'member' => MemberResource::make($request->user('member')),
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->user('member');
        /** @var array{current_password: string, password: string} $validated */
        $validated = $request->validated();

        $this->changePasswordAction->handle($member, $validated['current_password'], $validated['password'], 'member');

        return $this->success(message: 'password changed');
    }

    /**
     * @throws AuthenticationException
     */
    public function refresh(Request $request): JsonResponse
    {
        $refreshed = $this->refreshJwtToken->handle($request, 'member');
        /** @var Member $member */
        $member = $refreshed['subject'];
        $this->loginLogRecorder->record($request, 'member', 'refresh', true, $member->email ?? $member->mobile, $member);

        return $this->success($this->tokenPayload($refreshed['token'], $member));
    }

    public function logout(Request $request): JsonResponse
    {
        $member = $this->guard()->user();
        $this->guard()->logout();
        $this->loginLogRecorder->record($request, 'member', 'logout', true, $member?->email ?? $member?->mobile, $member);

        return $this->success(message: 'logged out');
    }

    private function guard(): JWTGuard
    {
        /** @var JWTGuard $guard */
        $guard = Auth::guard('member');

        return $guard;
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, member: MemberResource}
     */
    private function tokenPayload(string $token, Member $member): array
    {
        return [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $this->tokenTtlSeconds(),
            'member' => MemberResource::make($member),
        ];
    }

    private function tokenTtlSeconds(): int
    {
        return (int) $this->guard()->factory()->getTTL() * 60;
    }

    private function recordLogin(Request $request, ?Member $member): void
    {
        if ($member === null) {
            return;
        }

        $member->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();
    }
}
