<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class FileDeleteFailedException extends HttpException
{
    public const ERROR_CODE = 'file_delete_failed';

    public function __construct()
    {
        parent::__construct(Response::HTTP_SERVICE_UNAVAILABLE, 'File could not be deleted');
    }
}
