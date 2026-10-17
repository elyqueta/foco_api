<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend (links dos emails)
    |--------------------------------------------------------------------------
    | Base do front para onde apontam os botões dos emails ("Abrir no Foco" →
    | /tarefas/{id}, /projetos/{id}; rodapé → /definicoes).
    */
    'frontend' => [
        'url' => env('FRONTEND_URL', 'http://localhost:4200'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agendador externo (tick)
    |--------------------------------------------------------------------------
    | `POST /api/internal/scheduler/tick` só corre com o cabeçalho
    | `X-Cron-Secret` igual a `CRON_SECRET` (comparado com `hash_equals`).
    | Com o segredo vazio o endpoint responde 503 — nunca aceita um tick sem
    | segredo configurado.
    */
    'cron' => [
        'secret' => env('CRON_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticks e emails
    |--------------------------------------------------------------------------
    | `time_budget`: o tick deixa de processar novos itens passados estes
    | segundos (agendadores externos têm timeout curto); o resto fica para o
    | tick seguinte — tudo é idempotente. `mail_daily_limit`: tecto diário de
    | emails por utilizador, contado em `notification_dispatch_log`
    | (`channel=email`, `status=sent`, dia UTC) — o plano gratuito do Resend
    | tem limites diários/mensais.
    */
    'scheduler' => [
        'time_budget' => env('TICK_TIME_BUDGET', 25),
        'mail_daily_limit' => env('MAIL_DAILY_LIMIT', 90),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
