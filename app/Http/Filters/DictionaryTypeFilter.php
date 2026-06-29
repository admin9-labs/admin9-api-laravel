<?php

namespace App\Http\Filters;

use Mitoop\LaravelQueryBuilder\AbstractFilter;
use Mitoop\LaravelQueryBuilder\ValueResolvers\Like;
use Mitoop\LaravelQueryBuilder\ValueResolvers\LikeAny;

class DictionaryTypeFilter extends AbstractFilter
{
    protected array $allowedSorts = ['id', 'name', 'code', 'sort', 'created_at', 'updated_at'];

    protected function rules(): array
    {
        return [
            'code',
            'name|like' => new Like,
            'is_active',
            'keyword|like_any' => new LikeAny(['name', 'code', 'description']),
        ];
    }
}
