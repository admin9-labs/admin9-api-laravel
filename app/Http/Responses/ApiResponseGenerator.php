<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Context;
use Mitoop\Http\ResponseGenerator;

class ApiResponseGenerator extends ResponseGenerator
{
    protected function mergeExtra(array $payload): array
    {
        $payload = parent::mergeExtra($payload);

        if (request()->is('api/*') && Context::has('request_id')) {
            $payload['request_id'] = Context::get('request_id');
        }

        return $payload;
    }
}
