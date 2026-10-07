<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ApiDocumentationController
{
    public function ui(): View
    {
        return view('docs');
    }

    public function openApi(): JsonResponse
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('app.name', 'Foco API'),
                'version' => trim(file_get_contents(base_path('VERSION'))),
                'description' => 'Endpoints actualmente disponíveis na API Foco.',
            ],
            'servers' => [
                ['url' => '/api'],
            ],
            'paths' => [
                '/health' => [
                    'get' => [
                        'summary' => 'Health check',
                        'description' => 'Verifica se a API está activa e se consegue ligar-se à base de dados.',
                        'operationId' => 'getHealth',
                        'responses' => [
                            '200' => [
                                'description' => 'API saudável',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'status' => ['type' => 'string', 'example' => 'ok'],
                                                'app' => ['type' => 'string', 'example' => 'Foco API'],
                                                'db' => ['type' => 'boolean', 'example' => true],
                                                'time' => ['type' => 'string', 'format' => 'date-time'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '503' => [
                                'description' => 'API activa, mas sem ligação à base de dados.',
                            ],
                        ],
                    ],
                ],
                '/' => [
                    'get' => [
                        'summary' => 'Status da API',
                        'operationId' => 'getApiStatus',
                        'responses' => [
                            '200' => [
                                'description' => 'Resposta simples',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'name' => ['type' => 'string', 'example' => 'Foco API'],
                                                'status' => ['type' => 'string', 'example' => 'ok'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '/user' => [
                    'get' => [
                        'summary' => 'Utilizador autenticado',
                        'description' => 'Devolve o utilizador associado ao token Sanctum.',
                        'operationId' => 'getAuthenticatedUser',
                        'security' => [
                            ['bearerAuth' => []],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Utilizador autenticado.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['type' => 'object'],
                                    ],
                                ],
                            ],
                            '401' => [
                                'description' => 'Token ausente ou inválido.',
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                    ],
                ],
            ],
        ];

        return response()->json($spec, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
