<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class FileDirectoryNotEmptyException extends HttpException
{
    public const ERROR_CODE = 'file_directory_not_empty';

    public function __construct()
    {
        parent::__construct(Response::HTTP_CONFLICT, 'The file directory must be empty before it can be deleted');
    }
}
