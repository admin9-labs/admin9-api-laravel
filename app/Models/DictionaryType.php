<?php

namespace App\Models;

use App\Models\Concerns\LogsAdminActivity;
use App\Models\Traits\HasModelDefaults;
use Database\Factories\DictionaryTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'description', 'sort', 'is_active'])]
class DictionaryType extends Model
{
    /** @use HasFactory<DictionaryTypeFactory> */
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
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<DictionaryItem>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DictionaryItem::class)->ordered();
    }

    /**
     * @param  Builder<DictionaryType>  $query
     * @return Builder<DictionaryType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<DictionaryType>  $query
     * @return Builder<DictionaryType>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }
}
