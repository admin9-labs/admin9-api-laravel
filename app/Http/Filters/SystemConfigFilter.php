<?php

namespace App\Http\Filters;

use Mitoop\LaravelQueryBuilder\AbstractFilter;
use Mitoop\LaravelQueryBuilder\ValueResolvers\Like;
use Mitoop\LaravelQueryBuilder\ValueResolvers\LikeAny;

class SystemConfigFilter extends AbstractFilter
{
    protected array $allowedSorts = ['id', 'name', 'key', 'type', 'config_group', 'sort', 'created_at', 'updated_at'];

    protected function rules(): array
    {
        return [
            'key',
            'name|like' => new Like,
            'config_group',
            'type',
            'is_public',
            'is_active',
            'keyword|like_any' => new LikeAny(['name', 'key', 'description']),
        ];
    }
}
