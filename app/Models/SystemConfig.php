<?php

namespace App\Models;

use App\Models\Concerns\LogsAdminActivity;
use App\Models\Traits\HasModelDefaults;
use App\Support\Security\SensitiveDataSanitizer;
use Database\Factories\SystemConfigFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'key', 'value', 'type', 'config_group', 'description', 'is_public', 'is_active', 'sort'])]
class SystemConfig extends Model
{
    /** @use HasFactory<SystemConfigFactory> */
    use HasFactory, HasModelDefaults, LogsAdminActivity;

    public const TYPE_STRING = 'string';

    public const TYPE_TEXT = 'text';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_JSON = 'json';

    protected $attributes = [
        'type' => self::TYPE_STRING,
        'config_group' => 'default',
        'is_public' => false,
        'is_active' => true,
        'sort' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allowedTypes(): array
    {
        return [
            self::TYPE_STRING,
            self::TYPE_TEXT,
            self::TYPE_INTEGER,
            self::TYPE_BOOLEAN,
            self::TYPE_JSON,
        ];
    }

    public static function containsSensitiveConfiguration(mixed $value): bool
    {
        return SensitiveDataSanitizer::containsSensitiveTerm($value);
    }

    public function resolvedValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            self::TYPE_INTEGER => (int) $this->value,
            self::TYPE_BOOLEAN => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            self::TYPE_JSON => json_decode($this->value, true),
            default => $this->value,
        };
    }

    /**
     * @param  Builder<SystemConfig>  $query
     * @return Builder<SystemConfig>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<SystemConfig>  $query
     * @return Builder<SystemConfig>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }
}
