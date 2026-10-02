<?php

namespace App\Models;

use Database\Factories\FileDirectoryFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['*'])]
class FileDirectory extends Model
{
    /** @use HasFactory<FileDirectoryFactory> */
    use HasFactory;

    /** @return BelongsTo<FileDirectory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<FileDirectory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<File, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'directory_id');
    }
}
