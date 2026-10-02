<?php

namespace Database\Factories;

use App\Models\MemberAuthSession;
use App\Models\MemberTokenRefresh;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MemberTokenRefresh> */
class MemberTokenRefreshFactory extends Factory
{
    public function definition(): array
    {
        return [
            'member_auth_session_id' => MemberAuthSession::factory(), 'auth_version' => 1,
            'source_jti_hash' => hash('sha256', fake()->unique()->uuid()),
            'successor_jti_hash' => hash('sha256', fake()->unique()->uuid()),
            'token' => 'unused-test-token', 'expires_at' => now()->addSeconds(MemberTokenRefresh::RECOVERY_SECONDS),
        ];
    }
}
