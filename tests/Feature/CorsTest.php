<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cors.allowed_origins', ['http://localhost:4200']);
    }

    public function test_preflight_allows_configured_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:4200',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/health');

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');
    }
}
