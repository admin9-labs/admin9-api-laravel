<?php

namespace App\Http\Resources;

use DateTimeInterface;
use Mitoop\Http\Resources\Resource;

abstract class PaginationAwareJsonResource extends Resource
{
    protected function dateTimeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }
}
