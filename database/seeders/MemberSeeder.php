<?php

namespace Database\Seeders;

use App\Models\Member;
use Illuminate\Database\Seeder;

class MemberSeeder extends Seeder
{
    private const DEFAULT_MEMBER_EMAIL = 'member@admin9.dev';

    private const DEFAULT_MEMBER_PASSWORD = 'Member-password-123';

    /**
     * Seed the local development member account.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Member::query()->firstOrCreate(
            ['email' => self::DEFAULT_MEMBER_EMAIL],
            [
                'name' => 'Member',
                'password' => self::DEFAULT_MEMBER_PASSWORD,
                'is_active' => true,
            ],
        );
    }
}
