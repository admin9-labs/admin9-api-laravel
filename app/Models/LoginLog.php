<?php

namespace App\Models;

use App\Models\Traits\HasModelDefaults;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['guard', 'account', 'subject_type', 'subject_id', 'event', 'successful', 'failure_reason', 'ip_address', 'user_agent', 'request_id', 'context'])]
class LoginLog extends Model
{
    use HasModelDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'context' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, LoginLog>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<LoginLog>  $query
     * @return Builder<LoginLog>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }
}
