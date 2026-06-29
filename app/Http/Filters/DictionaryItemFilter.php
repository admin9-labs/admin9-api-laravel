<?php

namespace App\Http\Filters;

use Mitoop\LaravelQueryBuilder\AbstractFilter;
use Mitoop\LaravelQueryBuilder\ValueResolvers\Like;
use Mitoop\LaravelQueryBuilder\ValueResolvers\LikeAny;

class DictionaryItemFilter extends AbstractFilter
{
    protected array $allowedSorts = ['id', 'name', 'code', 'value', 'sort', 'created_at', 'updated_at'];

    protected function rules(): array
    {
        return [
            'dictionary_type_id',
            'type_code:type$code',
            'code',
            'name|like' => new Like,
            'value|like' => new Like,
            'is_active',
            'keyword|like_any' => new LikeAny(['name', 'code', 'value', 'description']),
        ];
    }
}
