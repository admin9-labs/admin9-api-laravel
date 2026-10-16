<?php

namespace App\Support\OpenApi;

use Dedoc\Scramble\Support\Generator\OpenApi;
use stdClass;

final class DocumentPruner
{
    public function prune(OpenApi $document): void
    {
        $serialized = json_decode(json_encode($document->toArray(), JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        $root = clone $serialized;
        unset($root->components);
        $references = [];
        $this->collectReferences($root, $serialized, $references);

        foreach (['schemas', 'responses'] as $group) {
            foreach ($document->components->{$group} as $name => $component) {
                $serializedName = $document->components->uniqueSchemaName($name);
                $reference = '#/components/'.$group.'/'.str_replace(['~', '/'], ['~0', '~1'], $serializedName);

                if (! isset($references[$reference])) {
                    unset($document->components->{$group}[$name]);
                }
            }
        }
    }

    /** @param array<string, true> $references */
    private function collectReferences(mixed $value, stdClass $document, array &$references): void
    {
        if (! is_array($value) && ! $value instanceof stdClass) {
            return;
        }

        foreach ($value as $key => $child) {
            if ($key === '$ref' && is_string($child) && str_starts_with($child, '#/') && ! isset($references[$child])) {
                $references[$child] = true;
                $this->collectReferences($this->resolve($document, $child), $document, $references);
            } else {
                $this->collectReferences($child, $document, $references);
            }
        }
    }

    private function resolve(stdClass $document, string $reference): mixed
    {
        $value = $document;

        foreach (explode('/', substr($reference, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            $value = is_array($value) ? $value[$segment] : $value->{$segment};
        }

        return $value;
    }
}
