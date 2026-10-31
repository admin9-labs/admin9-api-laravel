<?php

namespace App\Support\OpenApi;

use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use stdClass;

final class EmptyObjectType extends ObjectType
{
    public function serializeAs31(): mixed
    {
        return $this->strictSchema(parent::serializeAs31());
    }

    public function serializeAs32(): mixed
    {
        return $this->strictSchema(parent::serializeAs32());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->strictSchema(parent::toArray());
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function strictSchema(array $schema): array
    {
        return [
            ...$schema,
            'properties' => new stdClass,
            'additionalProperties' => false,
            'maxProperties' => 0,
        ];
    }
}
