<?php

namespace App\Http\Resources;

use DateTimeInterface;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class PaginationAwareJsonResource extends JsonResource
{
    protected static function newCollection($resource): PaginationAwareResourceCollection
    {
        return new PaginationAwareResourceCollection($resource, static::class);
    }

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
