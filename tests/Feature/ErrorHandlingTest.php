<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\ActsAsUser;
use Tests\TestCase;

class ErrorHandlingTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    #[Test]
    public function inexistent_route_returns_route_not_found_with_path(): void
    {
        $this->getJson('/api/v1/rota-que-nao-existe')
            ->assertStatus(404)
            ->assertJsonPath('code', 'ROUTE_NOT_FOUND')
            ->assertJsonPath('message', 'A rota /api/v1/rota-que-nao-existe não foi encontrada.');
    }

    #[Test]
    public function inexistent_route_also_works_without_accept_header(): void
    {
        // Sem Accept: application/json (bruno/curl simples) continua JSON.
        $response = $this->get('/api/v1/rota-que-nao-existe');

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ROUTE_NOT_FOUND');
    }

    #[Test]
    public function wrong_method_returns_method_not_allowed(): void
    {
        // /api/v1/auth/login só aceita POST.
        $this->getJson('/api/v1/auth/login')
            ->assertStatus(405)
            ->assertJsonPath('code', 'METHOD_NOT_ALLOWED')
            ->assertJsonPath('message', 'O método GET não corresponde à rota /api/v1/auth/login. Métodos permitidos: POST.')
            ->assertHeader('Allow');
    }

    #[Test]
    public function malformed_json_body_returns_invalid_json(): void
    {
        $this->call(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{"email": "x@todo.ao",',
        )
            ->assertStatus(400)
            ->assertJsonPath('code', 'INVALID_JSON')
            ->assertJsonPath('message', 'O corpo do pedido não é um JSON válido.');
    }

    #[Test]
    public function database_failure_returns_database_error(): void
    {
        $response = $this->renderException(
            Request::create('/api/v1/categories', 'GET'),
            new QueryException('sqlite', 'select * from categories', [], new \RuntimeException('connection refused')),
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('DATABASE_ERROR', $response->getData()->code);
        $this->assertSame(
            'Não foi possível comunicar com a base de dados. Tenta novamente.',
            $response->getData()->message,
        );
    }

    #[Test]
    public function database_connection_failure_also_returns_database_error(): void
    {
        // Ligação falhada lança PDOException directamente (não QueryException).
        $response = $this->renderException(
            Request::create('/api/v1/categories', 'GET'),
            new PDOException('SQLSTATE[08006] Connection refused'),
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('DATABASE_ERROR', $response->getData()->code);
    }

    #[Test]
    public function unexpected_error_returns_actionable_internal_error(): void
    {
        // Simular produção (sem debug): a mensagem técnica não pode vazar.
        config(['app.debug' => false]);

        $response = $this->renderException(
            Request::create('/api/v1/settings', 'PATCH'),
            new \RuntimeException('segredo interno que não deve vazar'),
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('INTERNAL_ERROR', $response->getData()->code);
        $this->assertSame(
            'Ocorreu um erro inesperado. Tenta novamente; se persistir, contacta o suporte.',
            $response->getData()->message,
        );
        $this->assertStringNotContainsString('segredo interno', $response->getContent());
    }

    #[Test]
    public function unexpected_error_never_leaks_technical_details_even_in_debug(): void
    {
        // A resposta da API é sempre `{ message, code }` em PT — sem campo
        // debug nem mensagens do framework (a excepção completa vai para o log).
        config(['app.debug' => true]);

        $response = $this->renderException(
            Request::create('/api/v1/settings', 'PATCH'),
            new \RuntimeException('segredo interno que não deve vazar'),
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('INTERNAL_ERROR', $response->getData()->code);
        $this->assertSame(
            'Ocorreu um erro inesperado. Tenta novamente; se persistir, contacta o suporte.',
            $response->getData()->message,
        );
        $this->assertSame(['message', 'code'], array_keys((array) $response->getData()));
        $this->assertStringNotContainsString('segredo interno', $response->getContent());
        $this->assertStringNotContainsString('could not be found', $response->getContent());
    }

    #[Test]
    public function inexistent_resource_keeps_the_contract_message(): void
    {
        $this->actingAsUser();

        $this->deleteJson('/api/v1/categories/nao-existe')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Não encontrado.');
    }

    #[Test]
    public function web_routes_keep_default_error_pages(): void
    {
        // O padrão de JSON só se aplica a /api/* — a web mantém o HTML.
        $this->get('/rota-web-inexistente')->assertStatus(404);
    }

    private function renderException(Request $request, \Throwable $e): TestResponse
    {
        $response = $this->app->make(ExceptionHandler::class)->render($request, $e);

        return TestResponse::fromBaseResponse($response);
    }
}
