<?php

namespace App\Http\Filters;

use Mitoop\LaravelQueryBuilder\AbstractFilter;
use Mitoop\LaravelQueryBuilder\ValueResolvers\DateRange;

class ActivityLogFilter extends AbstractFilter
{
    protected array $allowedSorts = ['id', 'created_at'];

    protected function rules(): array
    {
        return [
            'log_name',
            'event',
            'subject_type',
            'subject_id',
            'causer_id',
            'created_at|between' => new DateRange,
        ];
    }
}
