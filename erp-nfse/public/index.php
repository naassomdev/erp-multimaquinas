<?php
declare(strict_types=1);

/**
 * Front Controller — único ponto de entrada exposto ao nginx.
 * O nginx deve apontar o document root para esta pasta (public/).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\Fiscal\FiscalGuard;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

header('Content-Type: application/json; charset=utf-8');

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ── Roteamento básico ─────────────────────────────────────────────────────────

// Health check (monitoramento / load balancer)
if ($uri === '/health' && $method === 'GET') {
    echo json_encode(['ok' => true, 'ts' => time(), 'env' => getenv('APP_ENV') ?: 'production']);
    exit;
}

// Status do certificado digital (requer autenticação em produção)
if ($uri === '/admin/cert/status' && $method === 'GET') {
    try {
        $certManager = new App\Services\Fiscal\CertificateManager(
            (string)(getenv('CERT_PATH')     ?: ''),
            (string)(getenv('CERT_PASSWORD') ?: '')
        );
        echo json_encode($certManager->validarValidade(), JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// Stats da fila de jobs (requer autenticação em produção)
if ($uri === '/admin/queue/stats' && $method === 'GET') {
    try {
        require dirname(__DIR__) . '/config/database.php';
        $queue = new App\Queue\DatabaseQueue($pdo);
        echo json_encode(['ok' => true, 'stats' => $queue->stats()], JSON_UNESCAPED_UNICODE);
        $pdo = null;
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// Concluir OS via API (exemplo de endpoint principal)
if ($uri === '/api/os/concluir' && $method === 'POST') {
    try {
        $body = json_decode((string)file_get_contents('php://input'), true);
        $osId       = (int)($body['os_id']       ?? 0);
        $operadorId = (int)($body['operador_id'] ?? 0);

        if ($osId <= 0 || $operadorId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'erro' => 'os_id e operador_id são obrigatórios.']);
            exit;
        }

        require dirname(__DIR__) . '/config/database.php';
        if (!FiscalGuard::canRunWorker($pdo)) {
            FiscalGuard::auditBlock($pdo, 'erp-nfse_api_os_concluir', null, $operadorId);
            http_response_code(423);
            echo json_encode(['ok' => false, 'erro' => FiscalGuard::blockMessage($pdo, 'erp-nfse_api_os_concluir')], JSON_UNESCAPED_UNICODE);
            $pdo = null;
            exit;
        }
        $service  = new App\Services\OS\OsService($pdo, new App\Queue\DatabaseQueue($pdo));
        $resultado = $service->concluirOS($osId, $operadorId);
        $pdo = null;

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);

    } catch (\DomainException $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
        error_log('[API] /api/os/concluir: ' . $e->getMessage());
    }
    exit;
}

// Rota não encontrada
http_response_code(404);
echo json_encode(['ok' => false, 'erro' => 'Rota não encontrada.']);
