<?php
declare(strict_types=1);

// ── Error reporting ────────────────────────────────────────────────
// Erros fatais ainda aparecem na tela (capturados pelo try/catch abaixo).
// Warnings/Deprecated/Notice vão para o log do PHP — nunca para o output —
// para evitar vazar HTML no meio de respostas JSON da API.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors',     '1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_NOTICE & ~E_USER_NOTICE);

// Define a raiz do projeto
define('BASE_PATH', dirname(__DIR__));

// 1. Carrega o Composer (Autoload)
require_once BASE_PATH . '/vendor/autoload.php';

try {
    // 2. Carrega as variáveis de ambiente (.env)
    $dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
    $dotenv->safeLoad();

    // 3. Inicia a sessão
    session_start();

    // Etapa 7A.4:
    // Qualquer sincronização OS -> equipamento foi removida do bootstrap.
    // Status de equipamento só podem ser alterados pelos serviços oficiais.

    if (file_exists(__DIR__ . '/migrate_mo.php')) {
        require_once __DIR__ . '/migrate_mo.php';
        unlink(__DIR__ . '/migrate_mo.php');
    }

    // 4. Instancia e roda a aplicação principal
    $app = new App\Core\Application(BASE_PATH);
    $app->run();

} catch (\Throwable $e) {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $expectsJson = str_starts_with($path, '/api/')
        || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

    http_response_code(500);

    if ($expectsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'Algo deu errado. Tente novamente em instantes.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo App\Core\View::render('errors/http', [
        'statusCode' => 500,
        'title' => 'Erro no sistema',
        'message' => 'Algo deu errado. Tente novamente em instantes.',
        'backUrl' => '/',
    ], '');
}
