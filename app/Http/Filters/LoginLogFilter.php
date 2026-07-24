<?php

namespace App\Http\Filters;

use Mitoop\LaravelQueryBuilder\AbstractFilter;
use Mitoop\LaravelQueryBuilder\ValueResolvers\DateRange;
use Mitoop\LaravelQueryBuilder\ValueResolvers\Like;

class LoginLogFilter extends AbstractFilter
{
    protected array $allowedSorts = ['id', 'created_at'];

    protected function rules(): array
    {
        return [
            'guard',
            'event',
            'successful',
            'account|like' => new Like,
            'subject_id',
            'ip_address',
            'created_at|between' => new DateRange,
        ];
    }
}
