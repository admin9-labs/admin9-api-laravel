<?php

namespace App\Http\Responses;

use App\Support\ApiRouting;
use Illuminate\Support\Facades\Context;
use Mitoop\Http\EnvelopeFactory;
use stdClass;

class ApiResponseGenerator extends EnvelopeFactory
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $extra
     * @return array<string, mixed>
     */
    protected function successPayload(mixed $data, string $message, int $code, array $meta, ?array $extra = null): array
    {
        return $this->addRequestId(parent::successPayload($data, $message, $code, $meta, $extra));
    }

    /**
     * @return array<string, mixed>
     */
    protected function errorPayload(string $message, int $code, mixed $errors): array
    {
        $payload = parent::errorPayload($message, $code, $errors);
        $payload['data'] = new stdClass;

        if ($code !== 422 || ! is_array($errors) || array_is_list($errors)) {
            $payload['errors'] = new stdClass;
        }

        return $this->addRequestId($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function addRequestId(array $payload): array
    {
        if (ApiRouting::matches(request()) && Context::has('request_id')) {
            $payload['request_id'] = Context::get('request_id');
        }

        return $payload;
    }
}
