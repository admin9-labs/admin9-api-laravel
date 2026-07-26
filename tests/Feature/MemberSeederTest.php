<?php

namespace Tests\Feature;

use App\Models\Member;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MemberSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const DEFAULT_MEMBER_EMAIL = 'member@admin9.dev';

    private const DEFAULT_MEMBER_PASSWORD = 'Member-password-123';

    public function test_member_seeder_creates_login_account_idempotently(): void
    {
        Artisan::call('db:seed', ['--class' => MemberSeeder::class]);
        Artisan::call('db:seed', ['--class' => MemberSeeder::class]);

        $member = Member::query()->where('email', self::DEFAULT_MEMBER_EMAIL)->firstOrFail();

        $this->assertSame(1, Member::query()->where('email', self::DEFAULT_MEMBER_EMAIL)->count());
        $this->assertSame('Member', $member->name);
        $this->assertTrue($member->is_active);
        $this->assertTrue(Hash::check(self::DEFAULT_MEMBER_PASSWORD, $member->password));

        $this->postJson('/api/auth/login', [
            'account' => self::DEFAULT_MEMBER_EMAIL,
            'password' => self::DEFAULT_MEMBER_PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.member.email', self::DEFAULT_MEMBER_EMAIL);
    }

    public function test_member_seeder_preserves_an_existing_member(): void
    {
        $member = Member::factory()->create([
            'name' => 'Existing Member',
            'email' => self::DEFAULT_MEMBER_EMAIL,
            'mobile' => '13900000000',
            'password' => 'Existing-password-123',
            'is_active' => false,
        ]);

        Artisan::call('db:seed', ['--class' => MemberSeeder::class]);

        $member->refresh();

        $this->assertSame(1, Member::query()->where('email', self::DEFAULT_MEMBER_EMAIL)->count());
        $this->assertSame('Existing Member', $member->name);
        $this->assertSame('13900000000', $member->mobile);
        $this->assertFalse($member->is_active);
        $this->assertTrue(Hash::check('Existing-password-123', $member->password));
    }

    #[DataProvider('nonDevelopmentEnvironmentProvider')]
    public function test_member_seeder_does_not_create_default_member_outside_local_or_testing(string $environment): void
    {
        $this->app->detectEnvironment(fn (): string => $environment);

        Artisan::call('db:seed', ['--class' => MemberSeeder::class, '--force' => true]);

        $this->assertFalse(Member::query()->where('email', self::DEFAULT_MEMBER_EMAIL)->exists());
    }

    public function test_database_seeder_includes_default_member_in_testing(): void
    {
        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class]);

        $this->assertTrue(Member::query()->where('email', self::DEFAULT_MEMBER_EMAIL)->exists());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonDevelopmentEnvironmentProvider(): array
    {
        return [
            'staging' => ['staging'],
            'production' => ['production'],
        ];
    }
}
