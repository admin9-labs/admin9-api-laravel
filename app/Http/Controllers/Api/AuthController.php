<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Support\Auth\ChangePassword;
use App\Support\Auth\LoginLogRecorder;
use App\Support\Auth\MemberSessionManager;
use App\Support\Auth\MemberTokenRefreshManager;
use App\Support\Auth\RefreshJwtToken;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use PHPOpenSourceSaver\JWTAuth\JWT;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use PHPOpenSourceSaver\JWTAuth\Token;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(
        private LoginLogRecorder $loginLogRecorder,
        private RefreshJwtToken $refreshJwtToken,
        private ChangePassword $changePasswordAction,
        private MemberSessionManager $sessions,
        private JWT $jwt,
        private MemberTokenRefreshManager $refreshes,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        /** @var array{account: string, password: string} $validated */
        $validated = $request->validated();
        $account = $validated['account'];
        $identifierField = Validator::make(['account' => $account], ['account' => ['email']])->passes() ? 'email' : 'mobile';

        $member = Member::where($identifierField, $account)->first();

        if ($member !== null && ! $member->is_active) {
            $this->loginLogRecorder->record($request, 'member', 'login', false, $account, $member, 'Account disabled');

            return $this->error('Invalid credentials', Response::HTTP_UNAUTHORIZED);
        }

        $credentials = [$identifierField => $account, 'password' => $validated['password']];

        $started = $member === null ? null : $this->sessions->login($member, $credentials);
        if ($started === null) {
            $this->loginLogRecorder->record($request, 'member', 'login', false, $account, $member, 'Invalid credentials');

            return $this->error('Invalid credentials', Response::HTTP_UNAUTHORIZED);
        }

        $token = $started['token'];
        $member = $started['member'];

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
     * Refresh a member token or recover its committed replacement within 30 seconds.
     *
     * Retries return the same replacement with its remaining expires_in and do not
     * extend the replacement or session lifetime. Recovery ends on revocation or
     * when the replacement is rotated.
     *
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
        $this->sessions->logout($member, $this->refreshes->sessionId($this->guard()->getPayload()));
        $this->loginLogRecorder->record($request, 'member', 'logout', true, $member?->email ?? $member?->mobile, $member);

        return $this->success(message: 'logged out');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->sessions->logoutAll($request->user('member'));

        return $this->success(message: 'all sessions logged out');
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
            'expires_in' => $this->tokenRemainingSeconds($token),
            'member' => MemberResource::make($member),
        ];
    }

    private function tokenRemainingSeconds(string $token): int
    {
        return max(0, (int) $this->jwt->manager()->setRefreshFlow(false)->decode(new Token($token))->get('exp') - now()->timestamp);
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
