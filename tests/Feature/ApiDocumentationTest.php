<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_openapi_json_is_available_at_docs_json_route(): void
    {
        $this->get('/api/docs.json')
            ->assertOk()
            ->assertJsonStructure([
                'openapi',
                'info' => ['title', 'version'],
                'servers',
                'paths' => [
                    '/',
                    '/health',
                    '/v1/auth/login',
                    '/v1/auth/register',
                    '/v1/auth/logout',
                    '/v1/auth/logout-all',
                    '/v1/auth/me',
                    '/v1/auth/password',
                    '/v1/settings',
                    '/v1/categories',
                    '/v1/categories/{name}',
                    '/health',
                ],
                'components' => ['securitySchemes' => ['bearerAuth']],
            ])
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonPath('paths./v1/auth/me.get.security.0.bearerAuth', []);
    }

    public function test_swagger_ui_is_available_at_docs(): void
    {
        $response = $this->get('/docs');

        $response->assertStatus(200);
        $response->assertSee('Foco API Docs');
        $response->assertSee('/api/docs');
    }
}
