<?php

namespace App\Support\Auth;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\JWT;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use PHPOpenSourceSaver\JWTAuth\Token;

final class RefreshJwtToken
{
    public function __construct(
        private AuthManager $auth,
        private JWT $jwt,
    ) {}

    /**
     * @return array{token: string, subject: Authenticatable}
     */
    public function handle(Request $request, string $guardName): array
    {
        $guard = $this->auth->guard($guardName);

        if (! $guard instanceof JWTGuard) {
            throw new AuthenticationException(guards: [$guardName]);
        }

        $manager = $this->jwt->manager();
        $factory = $this->jwt->factory();

        try {
            $this->jwt->setRequest($request)->unsetToken()->parseToken();
            $token = $this->jwt->getToken();

            if (! $token instanceof Token) {
                throw new JWTException('Token could not be parsed from the request.');
            }

            $payload = $manager->setRefreshFlow()->decode($token);
            $provider = $guard->getProvider();

            if (! method_exists($provider, 'getModel')) {
                throw new AuthenticationException(guards: [$guardName]);
            }

            $providerClaim = sha1($provider->getModel());

            if ($payload->get('guard') !== $guardName || $payload->get('prv') !== $providerClaim) {
                throw new AuthenticationException(guards: [$guardName]);
            }

            $subject = $provider->retrieveById($payload->get('sub'));

            if (! $subject instanceof Authenticatable) {
                throw new AuthenticationException(guards: [$guardName]);
            }

            $authenticationVersion = $payload->get('auth_version');

            if (! is_int($authenticationVersion)
                || $authenticationVersion !== (int) data_get($subject, 'auth_version')) {
                throw new AuthenticationException(guards: [$guardName]);
            }

            if (! (bool) data_get($subject, 'is_active', true)) {
                throw new AccountInactiveException;
            }

            $refreshedToken = $manager
                ->customClaims([
                    'guard' => $guardName,
                    'prv' => $providerClaim,
                    'auth_version' => $authenticationVersion,
                ])
                ->refresh($token, resetClaims: true)
                ->get();

            $guard->setUser($subject);

            return [
                'token' => $refreshedToken,
                'subject' => $subject,
            ];
        } catch (JWTException) {
            throw new AuthenticationException(guards: [$guardName]);
        } finally {
            $manager->setRefreshFlow(false)->customClaims([]);
            $factory->setRefreshFlow(false)->customClaims([])->emptyClaims();
            $this->jwt->customClaims([])->unsetToken();
        }
    }
}
