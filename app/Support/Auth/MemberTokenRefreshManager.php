<?php

namespace App\Support\Auth;

use App\Models\Member;
use App\Models\MemberAuthSession;
use App\Models\MemberTokenRefresh;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\JWT;
use PHPOpenSourceSaver\JWTAuth\Payload;
use PHPOpenSourceSaver\JWTAuth\Token;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

final class MemberTokenRefreshManager
{
    public function __construct(private JWT $jwt, private MemberSessionManager $sessions) {}

    public function handle(Member $member, Payload $source, Token $sourceToken): string
    {
        $hash = $this->jtiHash($source->get('jti'));
        $sessionId = $this->sessionId($source);
        $record = $this->sessions->withSession($member, $sessionId, function (Member $current, MemberAuthSession $session) use ($hash, $source, $sourceToken): MemberTokenRefresh {
            $existing = MemberTokenRefresh::query()->where('source_jti_hash', $hash)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->member_auth_session_id !== $session->id
                    || $existing->auth_version !== $current->auth_version || ! $existing->expires_at->isFuture()) {
                    throw new AuthenticationException(guards: ['member']);
                }
                $this->validateSuccessor($existing, $current);

                return $existing;
            }

            $this->jwt->manager()->decode($sourceToken);
            $claims = array_merge(
                array_intersect_key($source->toArray(), array_flip(config('jwt.persistent_claims', []))),
                ['sid' => $session->id, 'iat' => config('jwt.refresh_iat') ? now()->timestamp : $source->get('iat')],
            );
            $token = $this->jwt->customClaims($claims)->fromUser($current);
            try {
                $successor = $this->jwt->manager()->setRefreshFlow(false)->decode(new Token($token));
            } finally {
                $this->jwt->manager()->setRefreshFlow();
            }
            $this->sessions->refreshed($session);

            return MemberTokenRefresh::query()->forceCreate([
                'member_auth_session_id' => $session->id, 'auth_version' => $current->auth_version,
                'source_jti_hash' => $hash, 'successor_jti_hash' => $this->jtiHash($successor->get('jti')),
                'token' => $token, 'expires_at' => now()->addSeconds(MemberTokenRefresh::RECOVERY_SECONDS),
            ]);
        }, (int) $source->get('iat'));

        try {
            $this->jwt->manager()->invalidate($sourceToken);
            $revoked = $this->jwt->manager()->getBlacklist()->has($source);
        } catch (JWTException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ServiceUnavailableHttpException(1, 'Token revocation is unavailable. Please retry.', $exception);
        }
        if (! $revoked) {
            throw new ServiceUnavailableHttpException(1, 'Token revocation is unavailable. Please retry.');
        }
        DB::transaction(function () use ($record): void {
            $current = MemberTokenRefresh::query()->lockForUpdate()->findOrFail($record->id);
            if ($current->blacklisted_at === null) {
                $current->forceFill(['blacklisted_at' => now()])->save();
            }
        }, attempts: 3);

        $member->refresh();
        if (! $member->is_active || $member->auth_version !== $record->auth_version) {
            throw new AuthenticationException(guards: ['member']);
        }
        $this->sessions->validate($member, $record->member_auth_session_id);
        $this->validateSuccessor($record, $member);

        return $record->token;
    }

    public function sessionId(Payload $payload): ?string
    {
        return $this->sessions->sessionId($payload)
            ?? MemberTokenRefresh::query()->where('source_jti_hash', $this->jtiHash($payload->get('jti')))->value('member_auth_session_id');
    }

    private function validateSuccessor(MemberTokenRefresh $record, Member $member): void
    {
        if (MemberTokenRefresh::query()->where('source_jti_hash', $record->successor_jti_hash)->exists()) {
            throw new AuthenticationException(guards: ['member']);
        }
        try {
            $payload = $this->jwt->manager()->setRefreshFlow(false)->decode(new Token($record->token));
            if ($payload->get('guard') !== 'member' || $payload->get('prv') !== sha1($member::class)
                || (string) $payload->get('sub') !== (string) $member->id
                || $payload->get('auth_version') !== $member->auth_version
                || $payload->get('sid') !== $record->member_auth_session_id) {
                throw new AuthenticationException(guards: ['member']);
            }
        } finally {
            $this->jwt->manager()->setRefreshFlow();
        }
    }

    private function jtiHash(mixed $jti): string
    {
        if (! is_string($jti) || $jti === '') {
            throw new AuthenticationException(guards: ['member']);
        }

        return hash_hmac('sha256', 'member-refresh:'.$jti, (string) config('app.key'));
    }
}
