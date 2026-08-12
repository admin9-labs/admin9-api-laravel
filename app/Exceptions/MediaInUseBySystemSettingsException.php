<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MediaInUseBySystemSettingsException extends HttpException
{
    public const ERROR_CODE = 'media_in_use_by_system_settings';

    public function __construct()
    {
        parent::__construct(Response::HTTP_CONFLICT, 'Media is in use by system settings');
    }
}
