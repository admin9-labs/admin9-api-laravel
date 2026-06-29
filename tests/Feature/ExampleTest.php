<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_health_route_reports_successful_application_boot(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }
}
