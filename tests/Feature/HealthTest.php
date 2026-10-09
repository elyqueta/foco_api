<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->get('/api/v1/health');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'app',
            'db',
            'time',
        ]);
        $response->assertJson([
            'app' => 'Foco API',
        ]);
    }
}
