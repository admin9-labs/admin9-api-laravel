<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MediaDeleteFailedException extends HttpException
{
    public const ERROR_CODE = 'media_delete_failed';

    public function __construct()
    {
        parent::__construct(Response::HTTP_SERVICE_UNAVAILABLE, 'Media file could not be deleted');
    }
}
