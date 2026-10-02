<?php

namespace App\Support\OpenApi;

use stdClass;

final class DocumentPartitioner
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /** @return array{admin: stdClass, client: stdClass} */
    public function partition(stdClass $document): array
    {
        return ['admin' => $this->forAudience($document, true), 'client' => $this->forAudience($document, false)];
    }

    private function forAudience(stdClass $document, bool $admin): stdClass
    {
        $result = clone $document;
        $result->paths = new stdClass;
        $tags = [];

        foreach ($document->paths as $path => $item) {
            $selected = clone $item;
            $hasOperations = false;

            foreach (self::METHODS as $method) {
                if (! isset($item->{$method})) {
                    continue;
                }

                $operation = $item->{$method};
                if (isset($operation->{'$ref'})) {
                    $operation = $this->resolve($document, $operation->{'$ref'});
                }
                $operationId = $operation->operationId ?? ($method === 'patch' ? $item->put->operationId : null);
                $shared = $operationId === 'system-settings.public';
                if (! $shared && str_starts_with($operationId, 'admin.') !== $admin) {
                    unset($selected->{$method});

                    continue;
                }

                $hasOperations = true;
                $tags = [...$tags, ...($operation->tags ?? [])];
            }

            if ($hasOperations) {
                $result->paths->{$path} = $selected;
            }
        }

        $result->tags = array_values(array_filter($document->tags ?? [], static fn (stdClass $tag): bool => in_array($tag->name, $tags, true)));
        unset($result->components);
        $references = [];
        $this->collectReferences($result, $document, $references);
        $result->components = new stdClass;

        foreach ($document->components as $group => $components) {
            $selected = array_filter((array) $components, static fn (string $name): bool => $group === 'securitySchemes' || isset($references['#/components/'.$group.'/'.str_replace(['~', '/'], ['~0', '~1'], $name)]), ARRAY_FILTER_USE_KEY);
            if ($selected !== []) {
                $result->components->{$group} = (object) $selected;
            }
        }

        return $result;
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
