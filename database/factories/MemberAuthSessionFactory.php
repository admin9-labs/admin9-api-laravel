<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\MemberAuthSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MemberAuthSession> */
class MemberAuthSessionFactory extends Factory
{
    public function definition(): array
    {
        return ['member_id' => Member::factory(), 'auth_version' => 1, 'expires_at' => now()->addMinutes((int) config('jwt.refresh_ttl')), 'revoked_at' => null];
    }
}
