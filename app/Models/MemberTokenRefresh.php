<?php

namespace App\Models;

use Database\Factories\MemberTokenRefreshFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

#[Guarded(['*'])]
#[Hidden(['source_jti_hash', 'successor_jti_hash', 'token'])]
class MemberTokenRefresh extends Model
{
    /** @use HasFactory<MemberTokenRefreshFactory> */
    use HasFactory, Prunable;

    public const RECOVERY_SECONDS = 30;

    protected function casts(): array
    {
        return ['auth_version' => 'integer', 'token' => 'encrypted', 'expires_at' => 'immutable_datetime', 'blacklisted_at' => 'immutable_datetime'];
    }

    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
