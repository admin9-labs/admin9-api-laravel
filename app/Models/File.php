<?php

namespace App\Models;

use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['*'])]
class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory;

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const PENDING_UPLOAD_LEASE_MINUTES = 5;

    public const DELETION_CLAIM_TTL_MINUTES = 5;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'deletion_started_at' => 'immutable_datetime',
            'deletion_requested_at' => 'immutable_datetime',
            'deletion_requested_by' => 'integer',
        ];
    }

    /** @param Builder<File> $query */
    #[Scope]
    protected function pendingDeletionRecovery(Builder $query): void
    {
        $query->whereNotNull('deletion_token')->where(function (Builder $query): void {
            $query->whereNull('deletion_started_at')
                ->orWhere('deletion_started_at', '<=', now()->subMinutes(self::DELETION_CLAIM_TTL_MINUTES));
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
