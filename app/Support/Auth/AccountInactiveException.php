<?php

namespace App\Support\Auth;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AccountInactiveException extends HttpException
{
    public const ERROR_CODE = 'account_inactive';

    public function __construct()
    {
        parent::__construct(Response::HTTP_FORBIDDEN, 'Account disabled');
    }
}
