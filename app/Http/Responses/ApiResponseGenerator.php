<?php

namespace App\Http\Responses;

use App\Exceptions\FileDeleteFailedException;
use App\Exceptions\FileDirectoryNotEmptyException;
use App\Exceptions\ManagedSystemSettingException;
use App\Support\ApiRouting;
use App\Support\Auth\AccountInactiveException;
use Illuminate\Http\JsonResponse;
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
}
