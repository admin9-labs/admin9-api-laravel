<?php

namespace App\Models;

use App\Models\Concerns\LogsAdminActivity;
use App\Models\Traits\HasModelDefaults;
use Database\Factories\MenuFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['parent_id', 'name', 'code', 'path', 'component', 'icon', 'type', 'permission_name', 'permission_id', 'sort', 'is_visible', 'is_active'])]
class Menu extends Model
{
    /** @use HasFactory<MenuFactory> */
    use HasFactory, HasModelDefaults, LogsAdminActivity;

    public const TYPE_DIRECTORY = 'directory';

    public const TYPE_PAGE = 'page';

    public const TYPE_BUTTON = 'button';

    protected $attributes = [
        'type' => self::TYPE_PAGE,
        'sort' => 0,
        'is_visible' => true,
        'is_active' => true,
    ];

    /**
     * @return array<int, string>
     */
    public static function allowedTypes(): array
    {
        return [
            self::TYPE_DIRECTORY,
            self::TYPE_PAGE,
            self::TYPE_BUTTON,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permission_id' => 'integer',
            'is_visible' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Menu, Menu>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Permission, Menu>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /**
     * @return HasMany<Menu>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->ordered();
    }

    /**
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    /**
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
     */
    public function scopeNavigation(Builder $query): Builder
    {
        return $query->whereIn('type', [self::TYPE_DIRECTORY, self::TYPE_PAGE]);
    }

    /**
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }
}
