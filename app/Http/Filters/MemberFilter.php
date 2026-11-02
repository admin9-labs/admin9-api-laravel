<?php

namespace App\Http\Filters;

use Mitoop\LaravelQueryBuilder\AbstractFilter;
use Mitoop\LaravelQueryBuilder\ValueResolvers\LikeAny;

class MemberFilter extends AbstractFilter
{
    protected function rules(): array
    {
        return [
            'search|like_any' => new LikeAny(['name', 'email', 'mobile']),
            'is_active',
        ];
    }

    protected function sorts(): array
    {
        return ['id' => 'desc'];
    }
}
