<?php

namespace App\Support\Auth;

use App\Models\Member;
use App\Models\MemberAuthSession;
use App\Support\Audit\SecurityActivityRecorder;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Payload;

final class MemberSessionManager
{
    public function __construct(private SecurityActivityRecorder $activityRecorder) {}

    /** @param array<string, mixed> $credentials
     * @return array{token: string, member: Member}|null
     */
    public function login(Member $member, array $credentials): ?array
    {
        return DB::transaction(function () use ($member, $credentials): ?array {
            $member = Member::query()->lockForUpdate()->findOrFail($member->getKey());
            $guard = Auth::guard('member');
            if (! $member->is_active || ! $guard->getProvider()->validateCredentials($member, $credentials)) {
                return null;
            }
            $session = $this->create($member);

            return ['token' => $guard->claims(['sid' => $session->id])->login($member), 'member' => $member];
        }, attempts: 3);
    }

    public function create(Member $member, ?int $issuedAt = null): MemberAuthSession
    {
        return MemberAuthSession::query()->forceCreate([
            'member_id' => $member->id, 'auth_version' => $member->auth_version,
            'expires_at' => (config('jwt.refresh_iat') || $issuedAt === null ? now() : now()->setTimestamp($issuedAt))->addMinutes((int) config('jwt.refresh_ttl')),
        ]);
    }

    public function sessionId(Payload $payload): ?string
    {
        $claims = $payload->toArray();
        if (! array_key_exists('sid', $claims)) {
            return null;
        }
        if (! is_string($claims['sid']) || ! Str::isUuid($claims['sid'])) {
            throw new AuthenticationException(guards: ['member']);
        }

        return $claims['sid'];
    }

    public function validate(Member $member, ?string $sessionId): void
    {
        if ($sessionId === null) {
            return;
        }
        if (! Str::isUuid($sessionId)
            || ! MemberAuthSession::query()->whereKey($sessionId)->where('member_id', $member->id)
                ->where('auth_version', $member->auth_version)->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
            throw new AuthenticationException(guards: ['member']);
        }
    }

    /** @template TResult
     * @param  Closure(Member, MemberAuthSession): TResult  $callback
     * @return TResult
     */
    public function withSession(Member $member, ?string $sessionId, Closure $callback, ?int $issuedAt = null): mixed
    {
        return DB::transaction(function () use ($member, $sessionId, $callback, $issuedAt): mixed {
            $current = Member::query()->lockForUpdate()->findOrFail($member->id);
            if (! $current->is_active || $current->auth_version !== $member->auth_version) {
                throw new AuthenticationException(guards: ['member']);
            }
            if ($sessionId === null) {
                $session = $this->create($current, $issuedAt);
                if (! $session->expires_at->isFuture()) {
                    throw new AuthenticationException(guards: ['member']);
                }
            } else {
                $this->validate($current, $sessionId);
                $session = MemberAuthSession::query()->lockForUpdate()->findOrFail($sessionId);
                if ($session->revoked_at !== null || ! $session->expires_at->isFuture()) {
                    throw new AuthenticationException(guards: ['member']);
                }
            }

            return $callback($current, $session);
        }, attempts: 3);
    }

    public function refreshed(MemberAuthSession $session): void
    {
        if (config('jwt.refresh_iat')) {
            $session->forceFill(['expires_at' => now()->addMinutes((int) config('jwt.refresh_ttl'))])->save();
        }
    }

    public function logout(Member $member, ?string $sessionId): void
    {
        if ($sessionId !== null) {
            $this->withSession($member, $sessionId, function (Member $member, MemberAuthSession $session): void {
                $session->forceFill(['revoked_at' => now()])->save();
            });
        }
        Auth::guard('member')->logout();
    }

    public function logoutAll(Member $member): void
    {
        DB::transaction(function () use ($member): void {
            $current = Member::query()->lockForUpdate()->findOrFail($member->id);
            $current->increment('auth_version');
            MemberAuthSession::query()->where('member_id', $member->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->activityRecorder->record($current, $current, 'member', 'member_sessions_invalidated');
        }, attempts: 3);
    }
}
