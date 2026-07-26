<?php

namespace App\Support;

use App\Exceptions\MediaDeleteFailedException;
use App\Support\Auth\AccountInactiveException;
use App\Support\OpenApi\EmptyObjectType;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response as OpenApiResponse;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ApiErrorOpenApiContract
{
    /**
     * @var array<int, string>
     */
    private const RESPONSE_NAMES = [
        Response::HTTP_UNAUTHORIZED => 'ApiUnauthorizedResponse',
        Response::HTTP_FORBIDDEN => 'ApiForbiddenResponse',
        Response::HTTP_NOT_FOUND => 'ApiNotFoundResponse',
        Response::HTTP_REQUEST_ENTITY_TOO_LARGE => 'ApiContentTooLargeResponse',
        Response::HTTP_UNPROCESSABLE_ENTITY => 'ApiValidationErrorResponse',
        Response::HTTP_TOO_MANY_REQUESTS => 'ApiRateLimitResponse',
        Response::HTTP_INTERNAL_SERVER_ERROR => 'ApiServerErrorResponse',
        Response::HTTP_SERVICE_UNAVAILABLE => 'ApiServiceUnavailableResponse',
    ];

    public function transformDocument(OpenApi $document): void
    {
        $responseReferences = $this->registerResponses($document);

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                $route = $operation->operationId === null
                    ? null
                    : RouteFacade::getRoutes()->getByName($operation->operationId);

                if (! $route instanceof Route) {
                    continue;
                }

                $existingResponseCodes = collect($operation->responses)
                    ->map(fn (OpenApiResponse|Reference $response): int|string|null => $this->responseCode($response))
                    ->filter()
                    ->all();
                $responseCodes = [Response::HTTP_INTERNAL_SERVER_ERROR];

                if ($this->requiresAuthenticationResponse($route)) {
                    $responseCodes[] = Response::HTTP_UNAUTHORIZED;
                }

                if ($this->requiresForbiddenResponse($route)) {
                    $responseCodes[] = Response::HTTP_FORBIDDEN;
                }

                if (in_array(Response::HTTP_NOT_FOUND, $existingResponseCodes, true)) {
                    $responseCodes[] = Response::HTTP_NOT_FOUND;
                }

                if (in_array(Response::HTTP_UNPROCESSABLE_ENTITY, $existingResponseCodes, true)) {
                    $responseCodes[] = Response::HTTP_UNPROCESSABLE_ENTITY;
                }

                if (in_array(strtoupper($operation->method), ['POST', 'PUT', 'PATCH'], true)) {
                    $responseCodes[] = Response::HTTP_REQUEST_ENTITY_TOO_LARGE;
                }

                if ($this->routeHasMiddlewarePrefix($route, 'throttle:')) {
                    $responseCodes[] = Response::HTTP_TOO_MANY_REQUESTS;
                }

                if ($route->getName() === 'admin.media.destroy') {
                    $responseCodes[] = Response::HTTP_SERVICE_UNAVAILABLE;
                }

                foreach (array_unique($responseCodes) as $responseCode) {
                    $this->replaceResponse($operation, $responseCode, $responseReferences[$responseCode]);
                }

                $this->addRequestIdHeaderToResponses($operation);
            }
        }

        foreach ([AuthenticationException::class, ModelNotFoundException::class, ValidationException::class] as $exception) {
            $document->components->removeResponse('\\'.$exception);
        }
    }

    /**
     * @return array<int, Reference>
     */
    private function registerResponses(OpenApi $document): array
    {
        $references = [];

        foreach (self::RESPONSE_NAMES as $status => $name) {
            $reference = new Reference('responses', $name, $document->components);
            $document->components->add($reference, $this->errorResponse($status));
            $references[$status] = $reference;
        }

        return $references;
    }

    private function errorResponse(int $status): OpenApiResponse
    {
        $errors = $status === Response::HTTP_UNPROCESSABLE_ENTITY
            ? (new ObjectType)->additionalProperties((new ArrayType)->setItems(new StringType))
            : new EmptyObjectType;
        $envelope = (new ObjectType)
            ->addProperty('success', (new BooleanType)->enum([false]))
            ->addProperty('code', (new IntegerType)->const($status))
            ->addProperty('message', new StringType)
            ->addProperty('data', new EmptyObjectType)
            ->addProperty('errors', $errors)
            ->addProperty('request_id', (new StringType)->format('uuid'))
            ->setRequired(['success', 'code', 'message', 'data', 'errors', 'request_id']);

        if ($status === Response::HTTP_FORBIDDEN) {
            $envelope->addProperty(
                'error_code',
                (new StringType)->enum([AccountInactiveException::ERROR_CODE]),
            );
        }

        if ($status === Response::HTTP_SERVICE_UNAVAILABLE) {
            $envelope
                ->addProperty(
                    'error_code',
                    (new StringType)->enum([MediaDeleteFailedException::ERROR_CODE]),
                )
                ->setRequired(['success', 'code', 'message', 'data', 'errors', 'request_id', 'error_code']);
        }

        $response = OpenApiResponse::make($status)
            ->setDescription(Response::$statusTexts[$status])
            ->setContent('application/json', Schema::fromType($envelope))
            ->addHeader('X-Request-Id', $this->requestIdHeader());

        if ($status === Response::HTTP_TOO_MANY_REQUESTS) {
            $response->setHeaders([
                'X-Request-Id' => $this->requestIdHeader(),
                'Retry-After' => $this->integerHeader('Seconds until the client may retry.'),
                'X-RateLimit-Limit' => $this->integerHeader('Maximum requests allowed in the current window.'),
                'X-RateLimit-Remaining' => $this->integerHeader('Requests remaining in the current window.'),
                'X-RateLimit-Reset' => $this->integerHeader('Unix timestamp when the current window resets.'),
            ]);
        }

        return $response;
    }

    private function requiresAuthenticationResponse(Route $route): bool
    {
        return in_array($route->getName(), [
            'member.auth.login',
            'member.auth.refresh',
            'admin.auth.login',
            'admin.auth.refresh',
        ], true) || $this->routeHasMiddlewarePrefix($route, 'auth:');
    }

    private function requiresForbiddenResponse(Route $route): bool
    {
        return in_array($route->getName(), ['member.auth.refresh', 'admin.auth.refresh'], true)
            || $this->routeHasMiddlewarePrefix($route, 'account.active:')
            || $this->routeHasMiddlewarePrefix($route, 'permission:');
    }

    private function routeHasMiddlewarePrefix(Route $route, string $prefix): bool
    {
        return collect($route->gatherMiddleware())
            ->contains(fn (string $middleware): bool => str_starts_with($middleware, $prefix));
    }

    private function replaceResponse(Operation $operation, int $status, Reference $reference): void
    {
        $operation->responses = collect($operation->responses)
            ->reject(fn (OpenApiResponse|Reference $response): bool => $this->responseCode($response) === $status)
            ->values()
            ->all();
        $operation->addResponse($reference);
    }

    private function addRequestIdHeaderToResponses(Operation $operation): void
    {
        foreach ($operation->responses as $response) {
            $resolvedResponse = $response instanceof Reference ? $response->resolve() : $response;
            $resolvedResponse->addHeader('X-Request-Id', $this->requestIdHeader());
        }
    }

    private function responseCode(OpenApiResponse|Reference $response): int|string|null
    {
        return $response instanceof Reference ? $response->resolve()->code : $response->code;
    }

    private function requestIdHeader(): Header
    {
        return new Header(
            description: 'Request correlation identifier. Matches the response body request_id.',
            schema: Schema::fromType((new StringType)->format('uuid')),
        );
    }

    private function integerHeader(string $description): Header
    {
        return new Header(
            description: $description,
            schema: Schema::fromType(new IntegerType),
        );
    }
}
