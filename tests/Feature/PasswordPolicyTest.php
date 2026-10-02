<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use App\Support\ApiRouting;
use App\Support\Security\PasswordPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('unsupportedBcryptPasswordProvider')]
    public function test_password_changes_reject_values_bcrypt_cannot_preserve(string $guard, string $password): void
    {
        $account = $guard === 'admin' ? User::factory()->create() : Member::factory()->create();
        $token = Auth::guard($guard)->login($account);
        $path = $guard === 'admin' ? '/admin/auth/password' : '/auth/password';

        $this->putJson(ApiRouting::path($path), [
            'current_password' => 'password',
            'password' => $password,
            'password_confirmation' => $password,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertSame(1, $account->refresh()->auth_version);
        $this->assertTrue(Hash::check('password', $account->password));
    }

    #[DataProvider('maximumBcryptPasswordProvider')]
    public function test_maximum_bcrypt_password_can_be_changed_and_used_to_login(string $guard, string $password): void
    {
        $account = $guard === 'admin' ? User::factory()->create() : Member::factory()->create();
        $token = Auth::guard($guard)->login($account);
        $prefix = $guard === 'admin' ? '/admin/auth' : '/auth';

        $this->putJson(ApiRouting::path($prefix.'/password'), [
            'current_password' => 'password',
            'password' => $password,
            'password_confirmation' => $password,
        ], ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->postJson(ApiRouting::path($prefix.'/login'), [
            $guard === 'admin' ? 'email' : 'account' => $account->email,
            'password' => $password,
        ])->assertOk();
    }

    public function test_argon_password_policy_does_not_inherit_bcrypt_byte_limit(): void
    {
        config(['hashing.driver' => 'argon2id']);

        $this->assertFalse(Validator::make(
            ['password' => str_repeat('密', 30)],
            ['password' => PasswordPolicy::rules()],
        )->fails());
    }

    public function test_password_policy_respects_a_stricter_configured_bcrypt_byte_limit(): void
    {
        config(['hashing.bcrypt.limit' => 64]);

        $this->assertTrue(Validator::make(
            ['password' => str_repeat('a', 65)],
            ['password' => PasswordPolicy::rules()],
        )->fails());
        $this->assertFalse(Validator::make(
            ['password' => str_repeat('a', 64)],
            ['password' => PasswordPolicy::rules()],
        )->fails());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unsupportedBcryptPasswordProvider(): array
    {
        $cases = [];

        foreach (['admin', 'member'] as $guard) {
            $cases[$guard.' ascii over 72 bytes'] = [$guard, str_repeat('a', 73)];
            $cases[$guard.' unicode over 72 bytes'] = [$guard, str_repeat('密', 25)];
            $cases[$guard.' null byte'] = [$guard, "valid\0password"];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function maximumBcryptPasswordProvider(): array
    {
        return [
            'admin ascii' => ['admin', str_repeat('a', 72)],
            'admin unicode' => ['admin', str_repeat('密', 24)],
            'member ascii' => ['member', str_repeat('a', 72)],
            'member unicode' => ['member', str_repeat('密', 24)],
        ];
    }
}
