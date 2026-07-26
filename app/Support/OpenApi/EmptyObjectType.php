<?php

namespace App\Support\OpenApi;

use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use stdClass;

final class EmptyObjectType extends ObjectType
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'properties' => new stdClass,
            'additionalProperties' => false,
            'maxProperties' => 0,
        ];
    }
}
