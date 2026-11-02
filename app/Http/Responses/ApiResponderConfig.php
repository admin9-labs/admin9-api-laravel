<?php

namespace App\Http\Responses;

use App\Support\ApiRouting;
use Illuminate\Support\Facades\Context;
use Mitoop\Http\ResponderConfig;

class ApiResponderConfig extends ResponderConfig
{
    /**
     * @param  array<array-key, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function mergedExtra(array $overrides = []): array
    {
        $extra = parent::mergedExtra($overrides);

        if (ApiRouting::matches(request()) && Context::has('request_id')) {
            $extra['request_id'] = Context::get('request_id');
        }

        return $extra;
    }
}
