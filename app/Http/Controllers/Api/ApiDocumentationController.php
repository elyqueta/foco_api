<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;

class ApiDocumentationController
{
    public function openApi(): JsonResponse
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('app.name', 'Foco API').' — v1',
                'version' => trim(file_get_contents(base_path('VERSION'))),
                'description' => 'Endpoints da **versão v1** da API Foco. A versão aparece no URL (`/api/v1/*`) e na estrutura do código (`routes/api/v1.php` + namespaces `App\Http\*\V1`). Endpoints de sistema (`health`, documentação) ficam sem versão em `/api/*`. Uma v2 futura terá a sua própria especificação em `/api/v2/docs.json`. **Padrão da API:** endpoints de atualização que não mudam nenhum valor não atualizam e respondem `422 { "code": "NO_CHANGES", "message": "Nenhuma alteração detetada." }` em vez de sucesso. Autenticação por token Bearer (Sanctum) em tudo exceto `auth/login`, `auth/register` e `health`.',
            ],
            'servers' => [
                ['url' => '/api', 'description' => 'Base da API: rotas de domínio em /api/v1/*, sistema em /api/*'],
            ],
            'paths' => [
                '/v1/auth/login' => [
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
                '/v1/auth/register' => [
                    'post' => [
                        'summary' => 'Registar utilizador',
                        'description' => 'Auto-registo público. Cria a conta, provisiona as 3 categorias padrão (elimináveis, sem projetos nem tarefas) e devolve o token — o utilizador fica autenticado.',
                        'operationId' => 'register',
                        'tags' => ['Auth'],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['name', 'email', 'password', 'passwordConfirmation'],
                                        'properties' => [
                                            'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 60],
                                            'email' => ['type' => 'string', 'format' => 'email'],
                                            'password' => ['type' => 'string', 'format' => 'password', 'minLength' => 8],
                                            'passwordConfirmation' => ['type' => 'string', 'format' => 'password', 'minLength' => 8],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'Utilizador criado e autenticado.',
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
                            '422' => [
                                'description' => 'Dados inválidos ou email já registado.',
                            ],
                            '429' => [
                                'description' => 'Demasiadas tentativas (5 por minuto por IP).',
                            ],
                        ],
                    ],
                ],
                '/v1/auth/logout' => [
                    'post' => [
                        'summary' => 'Logout',
                        'description' => 'Revoga o token actual.',
                        'operationId' => 'logout',
                        'tags' => ['Auth'],
                        'security' => [['bearerAuth' => []]],
                        'responses' => [
                            '200' => [
                                'description' => 'Sessão terminada.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'message' => ['type' => 'string', 'example' => 'Sessão terminada.'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '401' => ['description' => 'Token ausente ou inválido.'],
                        ],
                    ],
                ],
                '/v1/auth/logout-all' => [
                    'post' => [
                        'summary' => 'Logout em todos os dispositivos',
                        'description' => 'Revoga todos os tokens do utilizador.',
                        'operationId' => 'logoutAll',
                        'tags' => ['Auth'],
                        'security' => [['bearerAuth' => []]],
                        'responses' => [
                            '200' => [
                                'description' => 'Sessão terminada em todos os dispositivos.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'message' => ['type' => 'string', 'example' => 'Sessão terminada em todos os dispositivos.'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '401' => ['description' => 'Token ausente ou inválido.'],
                        ],
                    ],
                ],
                '/v1/auth/me' => [
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
                '/v1/auth/password' => [
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
                            '200' => [
                                'description' => 'Palavra-passe alterada.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'message' => ['type' => 'string', 'example' => 'Palavra-passe alterada.'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '422' => [
                                'description' => 'Palavra-passe atual incorreta ou nenhuma alteração detetada.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'message' => ['type' => 'string', 'example' => 'Nenhuma alteração detetada.'],
                                                'code' => ['type' => 'string', 'example' => 'NO_CHANGES'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '/v1/auth/profile' => [
                    'patch' => [
                        'summary' => 'Atualizar nome e/ou email',
                        'description' => 'Altera o nome e/ou o email do utilizador autenticado. Envie pelo menos um dos dois. Mudar o email exige `currentPassword` e cada mudança fica registada em `email_change_logs` (email antigo, novo, utilizador, data e IP).',
                        'operationId' => 'updateProfile',
                        'tags' => ['Auth'],
                        'security' => [['bearerAuth' => []]],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 60],
                                            'email' => ['type' => 'string', 'format' => 'email'],
                                            'currentPassword' => ['type' => 'string', 'description' => 'Obrigatório ao mudar o email.'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Perfil atualizado.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'message' => ['type' => 'string', 'example' => 'Perfil atualizado.'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '401' => ['description' => 'Token ausente ou inválido.'],
                            '422' => [
                                'description' => 'Dados inválidos, email já registado, palavra-passe atual incorreta ou nenhuma alteração detetada.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'message' => ['type' => 'string', 'example' => 'Nenhuma alteração detetada.'],
                                                'code' => ['type' => 'string', 'example' => 'NO_CHANGES'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '/v1/settings' => [
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
                            '422' => [
                                'description' => 'Dados inválidos ou nenhuma alteração detetada (code NO_CHANGES).',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'message' => ['type' => 'string', 'example' => 'Nenhuma alteração detetada.'],
                                                'code' => ['type' => 'string', 'example' => 'NO_CHANGES'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '/v1/categories' => [
                    'get' => [
                        'summary' => 'Listar categorias',
                        'description' => 'Categorias do utilizador autenticado, padrão primeiro e depois ordem alfabética, com contagens de tarefas e projetos.',
                        'operationId' => 'listCategories',
                        'tags' => ['Categorias'],
                        'security' => [['bearerAuth' => []]],
                        'responses' => [
                            '200' => [
                                'description' => 'Lista de categorias.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'name' => ['type' => 'string', 'example' => 'Estudos'],
                                                    'isDefault' => ['type' => 'boolean'],
                                                    'tasksCount' => ['type' => 'integer'],
                                                    'projectsCount' => ['type' => 'integer'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '401' => ['description' => 'Token ausente ou inválido.'],
                        ],
                    ],
                    'post' => [
                        'summary' => 'Criar categoria',
                        'description' => 'Cria uma categoria personalizada. Nome: 2–60 caracteres, sem duplicados (comparação insensível a maiúsculas e espaços).',
                        'operationId' => 'createCategory',
                        'tags' => ['Categorias'],
                        'security' => [['bearerAuth' => []]],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['name'],
                                        'properties' => [
                                            'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 60, 'example' => 'Estudos'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'Categoria criada.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'name' => ['type' => 'string'],
                                                'isDefault' => ['type' => 'boolean', 'example' => false],
                                                'tasksCount' => ['type' => 'integer', 'example' => 0],
                                                'projectsCount' => ['type' => 'integer', 'example' => 0],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '401' => ['description' => 'Token ausente ou inválido.'],
                            '422' => ['description' => 'Nome inválido ou já existente.'],
                        ],
                    ],
                ],
                '/v1/categories/{name}' => [
                    'delete' => [
                        'summary' => 'Remover categoria',
                        'description' => 'Remove uma categoria personalizada. As tarefas e projetos do utilizador nessa categoria passam para `professional`. Categorias padrão não podem ser removidas.',
                        'operationId' => 'deleteCategory',
                        'tags' => ['Categorias'],
                        'security' => [['bearerAuth' => []]],
                        'parameters' => [
                            [
                                'name' => 'name',
                                'in' => 'path',
                                'required' => true,
                                'description' => 'Nome da categoria (URL-encoded; resolvido independentemente de maiúsculas/espaços).',
                                'schema' => ['type' => 'string', 'example' => 'Estudos'],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Categoria removida.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'message' => ['type' => 'string', 'example' => 'Categoria removida.'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '401' => ['description' => 'Token ausente ou inválido.'],
                            '404' => ['description' => 'Categoria inexistente.'],
                            '422' => ['description' => 'Categoria padrão (code CATEGORY_PROTECTED).'],
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
