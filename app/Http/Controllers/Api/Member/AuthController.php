<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\LoginRequest;
use App\Http\Resources\Member\MemberResource;
use App\Models\Member;
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
        /** @var array{account: string, password: string} $validated */
        $validated = $request->validated();
        $account = $validated['account'];

        $member = Member::where('email', $account)
            ->orWhere('mobile', $account)
            ->first();

        if ($member !== null && ! $member->is_active) {
            $this->loginLogRecorder->record($request, 'member', 'login', false, $account, $member, 'Account disabled');

            return $this->deny('Account disabled')->setStatusCode(Response::HTTP_FORBIDDEN);
        }

        $credentials = filter_var($account, FILTER_VALIDATE_EMAIL)
            ? ['email' => $account, 'password' => $validated['password']]
            : ['mobile' => $account, 'password' => $validated['password']];

        $token = $this->guard()->attempt($credentials);
        if ($token === false) {
            $this->loginLogRecorder->record($request, 'member', 'login', false, $account, $member, 'Invalid credentials');

            return $this->deny('Invalid credentials')->setStatusCode(Response::HTTP_UNAUTHORIZED);
        }

        $this->recordLogin($request, $this->guard()->user());
        $this->loginLogRecorder->record($request, 'member', 'login', true, $account, $this->guard()->user());

        return $this->success($this->tokenPayload($token));
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success([
            'member' => MemberResource::make($request->user('member')),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $member = $this->guard()->user();
        $token = $this->guard()->refresh();
        $this->loginLogRecorder->record($request, 'member', 'refresh', true, $member?->email ?? $member?->mobile, $member);

        return $this->success($this->tokenPayload($token));
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
     * @return array{access_token: string, token_type: string, expires_in: int|null, member: MemberResource}
     */
    private function tokenPayload(string $token): array
    {
        return [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $this->guard()->factory()->getTTL() * 60,
            'member' => MemberResource::make($this->guard()->user()),
        ];
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
