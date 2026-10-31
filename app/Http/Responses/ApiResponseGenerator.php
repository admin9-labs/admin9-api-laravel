<?php

namespace App\Http\Responses;

use App\Exceptions\FileDeleteFailedException;
use App\Exceptions\FileDirectoryNotEmptyException;
use App\Exceptions\ManagedSystemSettingException;
use App\Support\ApiRouting;
use App\Support\Auth\AccountInactiveException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;
use Mitoop\Http\EnvelopeFactory;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ApiResponseGenerator extends EnvelopeFactory
{
    public function fromHttpException(HttpExceptionInterface $exception): JsonResponse
    {
        $status = $exception->getStatusCode();
        $payload = $this->errorPayload($exception->getMessage(), $status, new stdClass);
        $errorCode = match (true) {
            $exception instanceof AccountInactiveException => AccountInactiveException::ERROR_CODE,
            $exception instanceof FileDeleteFailedException => FileDeleteFailedException::ERROR_CODE,
            $exception instanceof FileDirectoryNotEmptyException => FileDirectoryNotEmptyException::ERROR_CODE,
            $exception instanceof ManagedSystemSettingException => ManagedSystemSettingException::ERROR_CODE,
            default => null,
        };

        if ($errorCode !== null && ApiRouting::matches(request())) {
            $payload['error_code'] = $errorCode;
        }

        return $this->send($payload, $status)->withHeaders($exception->getHeaders());
    }

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
        return $this->addRequestId(parent::errorPayload($message, $code, $errors));
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
