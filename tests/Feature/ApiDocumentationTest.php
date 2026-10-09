<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_openapi_json_is_available_at_docs_json_route(): void
    {
        $this->get('/api/v1/docs.json')
            ->assertOk()
            ->assertJsonStructure([
                'openapi',
                'info' => ['title', 'version'],
                'servers',
                'paths' => [
                    '/',
                    '/health',
                    '/auth/login',
                    '/auth/logout',
                    '/auth/logout-all',
                    '/auth/me',
                    '/auth/password',
                    '/settings',
                ],
                'components' => ['securitySchemes' => ['bearerAuth']],
            ])
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonPath('paths./auth/me.get.security.0.bearerAuth', []);
    }

    public function test_swagger_ui_is_available_at_docs(): void
    {
        $response = $this->get('/docs');

        $response->assertStatus(200);
        $response->assertSee('Foco API Docs');
        $response->assertSee('/api/v1/docs');
    }
}
