<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class ClientSafeException extends Exception
{
    public function __construct(
        string $message,
        ?Throwable $previous = null,
        public readonly ?int $errorCode = null,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

}
