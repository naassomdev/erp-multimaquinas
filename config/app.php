<?php
declare(strict_types=1);

return [
    'name'     => $_ENV['APP_NAME']     ?? 'ERP Multimáquinas',
    'env'      => $_ENV['APP_ENV']      ?? 'production',
    'debug'    => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url'      => $_ENV['APP_URL']      ?? 'http://localhost',
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo',

    'session' => [
        'name'     => $_ENV['SESSION_NAME']     ?? 'erp_session',
        'lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 10800),
    ],
];
