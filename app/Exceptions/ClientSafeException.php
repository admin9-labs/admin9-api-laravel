<?php

namespace App\Exceptions;

use Exception;
use Mitoop\Http\ResponderConfig;
use Throwable;

class ClientSafeException extends Exception
{
    protected int $errorCode;

    protected ?int $status;

    public function __construct(string $message, ?Throwable $previous = null, ?int $errorCode = null, ?int $status = null)
    {
        $this->errorCode = $errorCode ?? app(ResponderConfig::class)->error();
        $this->status = $status;

        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }
}
