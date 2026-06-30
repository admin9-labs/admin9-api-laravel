<?php

namespace App\Support\OpenApi;

use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\MethodReturnTypeExtension;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Symfony\Component\HttpFoundation\Response;

class BusinessResponseInferExtension implements MethodReturnTypeExtension
{
    /**
     * @var array<int, int>
     */
    private const DOCUMENTED_ERROR_STATUSES = [
        Response::HTTP_UNAUTHORIZED => Response::HTTP_UNAUTHORIZED,
        Response::HTTP_FORBIDDEN => Response::HTTP_FORBIDDEN,
        Response::HTTP_NOT_FOUND => Response::HTTP_NOT_FOUND,
        Response::HTTP_REQUEST_ENTITY_TOO_LARGE => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        Response::HTTP_UNPROCESSABLE_ENTITY => Response::HTTP_UNPROCESSABLE_ENTITY,
    ];

    private static ?string $cachedTrait = null;

    private static bool $traitResolved = false;

    public function shouldHandle(ObjectType $type): bool
    {
        if (! self::$traitResolved) {
            $trait = config('scramble-extensions.response.trait');
            self::$cachedTrait = is_string($trait) && trait_exists($trait) ? $trait : null;
            self::$traitResolved = true;
        }

        if (self::$cachedTrait === null || ! class_exists($type->name)) {
            return false;
        }

        return in_array(self::$cachedTrait, class_uses_recursive($type->name));
    }

    public function getMethodReturnType(MethodCallEvent $event): ?Type
    {
        if (! in_array($event->name, ['success', 'error', 'deny'], true)) {
            return null;
        }

        $status = match ($event->name) {
            'success' => Response::HTTP_OK,
            'deny' => Response::HTTP_FORBIDDEN,
            default => $this->errorStatus($event),
        };

        if ($event->name !== 'success') {
            return $this->jsonResponse(new ObjectType('stdClass'), $status);
        }

        $dataType = $event->getArg('data', 0, new ObjectType('stdClass'));

        if ($dataType instanceof Generic && (
            $dataType->isInstanceOf(LengthAwarePaginator::class)
            || $dataType->isInstanceOf(Paginator::class)
        )) {
            return $dataType;
        }

        return $this->jsonResponse($dataType, $status);
    }

    private function errorStatus(MethodCallEvent $event): int
    {
        $code = $event->getArg('code', 1, new LiteralIntegerType(Response::HTTP_OK));

        if (! $code instanceof LiteralIntegerType) {
            return Response::HTTP_OK;
        }

        return self::DOCUMENTED_ERROR_STATUSES[$code->value] ?? Response::HTTP_OK;
    }

    private function jsonResponse(Type $dataType, int $status): Generic
    {
        return new Generic(JsonResponse::class, [
            $dataType,
            new LiteralIntegerType($status),
            new KeyedArrayType,
        ]);
    }
}
