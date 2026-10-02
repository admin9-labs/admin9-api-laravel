<?php

namespace App\Models;

use Database\Factories\MemberAuthSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['*'])]
class MemberAuthSession extends Model
{
    /** @use HasFactory<MemberAuthSessionFactory> */
    use HasFactory, HasUuids, Prunable;

    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now())->orWhereNotNull('revoked_at');
    }

    protected function casts(): array
    {
        return ['auth_version' => 'integer', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
