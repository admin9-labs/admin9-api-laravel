<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ManagedSystemSettingException extends HttpException
{
    public const ERROR_CODE = 'managed_system_setting_immutable';

    public function __construct()
    {
        parent::__construct(Response::HTTP_CONFLICT, 'Managed system settings can only be changed through the system settings API');
    }
}
