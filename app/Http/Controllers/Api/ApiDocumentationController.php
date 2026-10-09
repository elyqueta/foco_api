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
                'description' => 'Endpoints actualmente disponíveis na API Foco. Autenticação por token Bearer (Sanctum) em tudo exceto `auth/login` e `health`.',
            ],
            'servers' => [
                ['url' => '/api', 'description' => 'Base do contrato (auth e definições)'],
                ['url' => '/api/v1', 'description' => 'Endpoints de sistema (health, documentação)'],
            ],
            'paths' => [
                '/auth/login' => [
                    'post' => [
                        'summary' => 'Login',
                        'description' => 'Autentica o utilizador e devolve um token Sanctum com expiração de 7 dias.',
                        'operationId' => 'login',
                        'tags' => ['Auth'],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['email', 'password'],
                                        'properties' => [
                                            'email' => ['type' => 'string', 'format' => 'email'],
                                            'password' => ['type' => 'string', 'format' => 'password', 'minLength' => 8],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Login realizado com sucesso.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'token' => ['type' => 'string'],
                                                'user' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'id' => ['type' => 'integer'],
                                                        'name' => ['type' => 'string'],
                                                        'email' => ['type' => 'string'],
                                                    ],
                                                ],
                                                'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '401' => [
                                'description' => 'Email ou palavra-passe incorretos.',
                            ],
                            '422' => [
                                'description' => 'Dados de entrada inválidos.',
                            ],
                            '429' => [
                                'description' => 'Demasiadas tentativas (5 por minuto por email/IP).',
                            ],
                        ],
                    ],
                ],
                '/auth/logout' => [
                    'post' => [
                        'summary' => 'Logout',
                        'description' => 'Revoga o token actual.',
                        'operationId' => 'logout',
                        'tags' => ['Auth'],
                        'security' => [['bearerAuth' => []]],
                        'responses' => [
                            '204' => ['description' => 'Sessão terminada.'],
                            '401' => ['description' => 'Token ausente ou inválido.'],
                        ],
                    ],
                ],
                '/auth/logout-all' => [
                    'post' => [
                        'summary' => 'Logout em todos os dispositivos',
                        'description' => 'Revoga todos os tokens do utilizador.',
                        'operationId' => 'logoutAll',
                        'tags' => ['Auth'],
                        'security' => [['bearerAuth' => []]],
                        'responses' => [
                            '204' => ['description' => 'Todos os tokens revogados.'],
                            '401' => ['description' => 'Token ausente ou inválido.'],
                        ],
                    ],
                ],
                '/auth/me' => [
                    'get' => [
                        'summary' => 'Utilizador autenticado',
                        'description' => 'Devolve os dados do utilizador associado ao token Sanctum.',
                        'operationId' => 'getAuthenticatedUser',
                        'tags' => ['Auth'],
                        'security' => [['bearerAuth' => []]],
                        'responses' => [
                            '200' => [
                                'description' => 'Utilizador autenticado.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'id' => ['type' => 'integer'],
                                                'name' => ['type' => 'string'],
                                                'email' => ['type' => 'string'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '401' => ['description' => 'Token ausente ou inválido.'],
                        ],
                    ],
                ],
                '/auth/password' => [
                    'patch' => [
                        'summary' => 'Alterar palavra-passe',
                        'description' => 'Altera a palavra-passe do utilizador e revoga os outros tokens.',
                        'operationId' => 'updatePassword',
                        'tags' => ['Auth'],
                        'security' => [['bearerAuth' => []]],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['currentPassword', 'password', 'passwordConfirmation'],
                                        'properties' => [
                                            'currentPassword' => ['type' => 'string'],
                                            'password' => ['type' => 'string', 'minLength' => 8],
                                            'passwordConfirmation' => ['type' => 'string', 'minLength' => 8],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '204' => ['description' => 'Palavra-passe alterada.'],
                            '422' => ['description' => 'Dados inválidos ou palavra-passe actual incorreta.'],
                        ],
                    ],
                ],
                '/settings' => [
                    'get' => [
                        'summary' => 'Definições do utilizador',
                        'description' => 'Devolve nome, tema, fuso horário e preferências de notificação.',
                        'operationId' => 'getSettings',
                        'tags' => ['Definições'],
                        'security' => [['bearerAuth' => []]],
                        'responses' => [
                            '200' => ['description' => 'Definições do utilizador.'],
                            '401' => ['description' => 'Token ausente ou inválido.'],
                        ],
                    ],
                    'patch' => [
                        'summary' => 'Actualizar definições',
                        'description' => 'Actualização parcial: `userName`, `theme`, `timezone`, `notifications`.',
                        'operationId' => 'updateSettings',
                        'tags' => ['Definições'],
                        'security' => [['bearerAuth' => []]],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'userName' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 60],
                                            'theme' => ['type' => 'string', 'enum' => ['light', 'dark']],
                                            'timezone' => ['type' => 'string', 'example' => 'Africa/Luanda'],
                                            'notifications' => ['type' => 'object'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Definições actualizadas.'],
                            '422' => ['description' => 'Dados inválidos.'],
                        ],
                    ],
                ],
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
