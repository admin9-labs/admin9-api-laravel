<?php

namespace App\Models;

use App\Models\Concerns\LogsAdminActivity;
use App\Models\Traits\HasModelDefaults;
use Database\Factories\DictionaryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dictionary_type_id', 'name', 'code', 'value', 'description', 'meta', 'sort', 'is_active'])]
class DictionaryItem extends Model
{
    /** @use HasFactory<DictionaryItemFactory> */
    use HasFactory, HasModelDefaults, LogsAdminActivity;

    protected $attributes = [
        'sort' => 0,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<DictionaryType, DictionaryItem>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(DictionaryType::class, 'dictionary_type_id');
    }

    /**
     * @param  Builder<DictionaryItem>  $query
     * @return Builder<DictionaryItem>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<DictionaryItem>  $query
     * @return Builder<DictionaryItem>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }
}
