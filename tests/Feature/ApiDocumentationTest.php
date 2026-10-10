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
                    '/v1/tasks',
                    '/v1/tasks/{id}',
                    '/v1/tasks/{id}/complete',
                    '/v1/tasks/{id}/reopen',
                    '/v1/tasks/{id}/postpone',
                    '/v1/tasks/{id}/notes',
                    '/v1/tasks/{id}/timer/start',
                    '/v1/tasks/{id}/timer/pause',
                    '/v1/tasks/{id}/timer/resume',
                    '/v1/tasks/{id}/time-entries',
                    '/v1/timer/active',
                    '/v1/time/summary',
                    '/health',
                ],
                'components' => [
                    'securitySchemes' => ['bearerAuth'],
                    'schemas' => ['Project', 'ProjectInput', 'Task', 'TaskInput', 'ActivityEntry', 'TimeEntry', 'TimeSummary'],
                ],
            ])
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonPath('paths./v1/auth/me.get.security.0.bearerAuth', [])
            ->assertJsonPath('paths./v1/projects.get.security.0.bearerAuth', [])
            ->assertJsonPath('paths./v1/projects.get.responses.200.content.application/json.schema.properties.items.items.$ref', '#/components/schemas/Project')
            ->assertJsonPath('paths./v1/projects.get.responses.200.content.application/json.schema.properties.counts.properties.active.example', 5)
            ->assertJsonPath('paths./v1/projects.get.parameters.3.schema.default', 1)
            ->assertJsonPath('paths./v1/projects.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/ProjectInput')
            ->assertJsonPath('paths./v1/projects/{id}.patch.requestBody.content.application/json.schema.$ref', '#/components/schemas/ProjectInput')
            ->assertJsonPath('paths./v1/projects/{id}.patch.responses.422.content.application/json.schema.properties.code.example', 'NO_CHANGES')
            ->assertJsonPath('paths./v1/projects/{id}.delete.responses.200.content.application/json.schema.properties.message.example', 'Projeto removido.')
            ->assertJsonPath('paths./v1/projects/{id}/notes.post.requestBody.content.application/json.schema.required.0', 'text')
            ->assertJsonPath('paths./v1/tasks.get.security.0.bearerAuth', [])
            ->assertJsonPath('paths./v1/tasks.get.responses.200.content.application/json.schema.properties.items.items.$ref', '#/components/schemas/Task')
            ->assertJsonPath('paths./v1/tasks.get.responses.200.content.application/json.schema.properties.counts.properties.expired.example', 7)
            ->assertJsonPath('paths./v1/tasks.get.parameters.9.name', 'openOnly')
            ->assertJsonPath('paths./v1/tasks.get.parameters.12.schema.maximum', 100)
            ->assertJsonPath('paths./v1/tasks.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/TaskInput')
            ->assertJsonPath('paths./v1/tasks.post.responses.422.content.application/json.schema.properties.code.example', 'PAST_DUE_DATE')
            ->assertJsonPath('paths./v1/tasks/{id}.patch.requestBody.content.application/json.schema.$ref', '#/components/schemas/TaskInput')
            ->assertJsonPath('paths./v1/tasks/{id}.delete.responses.200.content.application/json.schema.properties.message.example', 'Tarefa removida.')
            ->assertJsonPath('paths./v1/tasks/{id}/postpone.post.requestBody.content.application/json.schema.required.0', 'dueDate')
            ->assertJsonPath('paths./v1/tasks/{id}/complete.post.responses.200.content.application/json.schema.properties.task.$ref', '#/components/schemas/Task')
            ->assertJsonPath('paths./v1/tasks/{id}/notes.post.requestBody.content.application/json.schema.required.0', 'text')
            ->assertJsonPath('paths./v1/tasks/{id}/timer/start.post.responses.200.content.application/json.schema.properties.task.$ref', '#/components/schemas/Task')
            ->assertJsonPath('paths./v1/tasks/{id}/timer/pause.post.responses.200.content.application/json.schema.properties.task.$ref', '#/components/schemas/Task')
            ->assertJsonPath('paths./v1/tasks/{id}/timer/resume.post.responses.200.content.application/json.schema.properties.task.$ref', '#/components/schemas/Task')
            ->assertJsonPath('paths./v1/tasks/{id}/time-entries.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/TimeEntry')
            ->assertJsonPath('paths./v1/timer/active.get.responses.200.content.application/json.schema.properties.task.$ref', '#/components/schemas/Task')
            ->assertJsonPath('paths./v1/time/summary.get.responses.200.content.application/json.schema.$ref', '#/components/schemas/TimeSummary')
            ->assertJsonPath('components.schemas.TimeEntry.properties.endedReason.enum.1', 'auto_pause');
    }

    public function test_swagger_ui_is_available_at_docs(): void
    {
        $response = $this->get('/docs');

        $response->assertStatus(200);
        $response->assertSee('Foco API Docs');
        $response->assertSee('/api/docs');
    }
}
