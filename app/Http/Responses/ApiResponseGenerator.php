<?php

namespace App\Http\Responses;

use App\Http\Resources\PaginationAwareResourceCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;
use Mitoop\Http\ResponseGenerator;
use stdClass;

class ApiResponseGenerator extends ResponseGenerator
{
    /**
     * @return array{0: mixed, 1: array<string, mixed>}
     */
    protected function resolvePagination(mixed $data): array
    {
        if ($data instanceof PaginationAwareResourceCollection && $data->paginator() !== null) {
            return parent::resolvePagination($data->paginator());
        }

        return parent::resolvePagination($data);
    }

    protected function mergeExtra(array $payload): array
    {
        $payload = parent::mergeExtra($payload);

        if (request()->is('api', 'api/*') && Context::has('request_id')) {
            $payload['request_id'] = Context::get('request_id');
        }

        return $payload;
    }

    protected function prepareErrorPayload(string $message, int $code, mixed $errors): array
    {
        $payload = parent::prepareErrorPayload($message, $code, $errors);
        $payload['data'] = new stdClass;

        if ($code !== 422 || ! is_array($errors) || array_is_list($errors)) {
            $payload['errors'] = new stdClass;
        }

        return $payload;
    }

    protected function toJson(array $payload): JsonResponse
    {
        $response = parent::toJson($payload);
        $status = $this->statusFromPayload($payload);

        if ($status !== null) {
            $response->setStatusCode($status);
        }

        return $response;
    }

    private function statusFromPayload(array $payload): ?int
    {
        if (! request()->is('api', 'api/*') || ($payload['success'] ?? null) !== false) {
            return null;
        }

        $code = $payload['code'] ?? null;

        if (! self::isHttpErrorCode($code)) {
            return null;
        }

        return $code;
    }

    public static function isHttpErrorCode(mixed $code): bool
    {
        return is_int($code) && $code >= 400 && $code <= 599;
    }
}
