<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_openapi_json_is_available_at_both_api_docs_routes(): void
    {
        foreach (['/api/docs', '/api/docs.json'] as $route) {
            $this->get($route)
                ->assertOk()
                ->assertJsonStructure([
                    'openapi',
                    'info' => ['title', 'version'],
                    'servers',
                    'paths' => ['/', '/health', '/user'],
                    'components' => ['securitySchemes' => ['bearerAuth']],
                ])
                ->assertJsonPath('openapi', '3.0.3')
                ->assertJsonPath('paths./user.get.security.0.bearerAuth', []);
        }
    }

    public function test_swagger_ui_is_available_at_docs(): void
    {
        $response = $this->get('/docs');

        $response->assertStatus(200);
        $response->assertSee('Foco API Docs');
        $response->assertSee('/api/docs');
    }
}
