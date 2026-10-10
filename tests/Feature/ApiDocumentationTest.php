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
                    '/v1/auth/profile',
                    '/v1/settings',
                    '/v1/categories',
                    '/v1/categories/{name}',
                    '/v1/projects',
                    '/v1/projects/{id}',
                    '/v1/projects/{id}/notes',
                    '/health',
                ],
                'components' => [
                    'securitySchemes' => ['bearerAuth'],
                    'schemas' => ['Project', 'ProjectInput', 'ActivityEntry'],
                ],
            ])
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonPath('paths./v1/auth/me.get.security.0.bearerAuth', [])
            ->assertJsonPath('paths./v1/projects.get.security.0.bearerAuth', [])
            ->assertJsonPath('paths./v1/projects.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Project')
            ->assertJsonPath('paths./v1/projects.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/ProjectInput')
            ->assertJsonPath('paths./v1/projects/{id}.patch.requestBody.content.application/json.schema.$ref', '#/components/schemas/ProjectInput')
            ->assertJsonPath('paths./v1/projects/{id}.patch.responses.422.content.application/json.schema.properties.code.example', 'NO_CHANGES')
            ->assertJsonPath('paths./v1/projects/{id}.delete.responses.200.content.application/json.schema.properties.message.example', 'Projeto removido.')
            ->assertJsonPath('paths./v1/projects/{id}/notes.post.requestBody.content.application/json.schema.required.0', 'text');
    }

    public function test_swagger_ui_is_available_at_docs(): void
    {
        $response = $this->get('/docs');

        $response->assertStatus(200);
        $response->assertSee('Foco API Docs');
        $response->assertSee('/api/docs');
    }
}
