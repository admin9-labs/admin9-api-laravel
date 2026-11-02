<?php

namespace App\Http\Filters;

use Mitoop\LaravelQueryBuilder\AbstractFilter;
use Mitoop\LaravelQueryBuilder\ValueResolvers\Like;

class FileFilter extends AbstractFilter
{
    protected function rules(): array
    {
        return [
            'search:name|like' => new Like,
            'type',
            'types:type|in',
            'ungrouped:directory_id|is_null',
            'directory_id',
        ];
    }

    protected function sorts(): array
    {
        return ['id' => 'desc'];
    }
}
