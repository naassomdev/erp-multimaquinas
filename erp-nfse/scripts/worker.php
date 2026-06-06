<?php
declare(strict_types=1);

/**
 * Worker de fila — process contínuo gerenciado pelo Supervisor.
 * Execução: php scripts/worker.php
 *           ou via Supervisor (recomendado, numprocs=2 para 2 vCPUs)
 *
 * Nunca execute este script dentro do document root (public/).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Jobs\EmitirNfseJob;
use App\Jobs\NotificarClienteJob;
use App\Queue\DatabaseQueue;
use App\Services\Fiscal\FiscalGuard;
use Dotenv\Dotenv;

// ── Carrega .env ──────────────────────────────────────────────────────────────
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo');

// ── Conexão PDO ───────────────────────────────────────────────────────────────
require dirname(__DIR__) . '/config/database.php'; // define $pdo

// ── Handlers registrados por tipo de job ─────────────────────────────────────
$queue    = new DatabaseQueue($pdo);
$handlers = [
    'emitir_nfse' => new EmitirNfseJob($pdo),
    'notificar_cliente' => new NotificarClienteJob($pdo),
];

$workerPid = getmypid();
$logPrefix = "[worker::{$workerPid}]";

echo "{$logPrefix} Iniciado em " . date('Y-m-d H:i:s') . PHP_EOL;

// ── Loop principal ────────────────────────────────────────────────────────────
while (true) {
    // Verifica se o processo deve ser encerrado graciosamente
    // (SIGTERM enviado pelo Supervisor ao fazer stop/restart)
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    $job = $queue->claim('default');

    if ($job === null) {
        sleep(5); // Fila vazia — aguarda antes de tentar novamente
        continue;
    }

    $tipo = $job['tipo'];
    echo "{$logPrefix} Job #{$job['id']} ({$tipo}) — tentativa {$job['tentativas']}" . PHP_EOL;

    if ($tipo === 'emitir_nfse' && !FiscalGuard::canRunWorker($pdo)) {
        $notaId = (int)($job['payload']['nota_id'] ?? 0);
        FiscalGuard::auditBlock($pdo, 'erp-nfse_worker_emitir_nfse', $notaId, (int)($job['payload']['operador_id'] ?? 0));
        $motivo = FiscalGuard::blockMessage($pdo, 'erp-nfse_worker_emitir_nfse');
        $queue->releaseBlocked((int)$job['id'], $motivo);
        echo "{$logPrefix} Job #{$job['id']} bloqueado por configuração fiscal." . PHP_EOL;
        continue;
    }

    if (!isset($handlers[$tipo])) {
        $queue->failed($job['id'], "Handler '{$tipo}' não registrado.", false);
        echo "{$logPrefix} Job #{$job['id']} descartado: handler não encontrado." . PHP_EOL;
        continue;
    }

    try {
        $handlers[$tipo]->handle($job['payload']);
        $queue->done($job['id']);
        echo "{$logPrefix} Job #{$job['id']} concluído com sucesso." . PHP_EOL;

    } catch (\Throwable $e) {
        $queue->failed($job['id'], $e->getMessage());
        echo "{$logPrefix} Job #{$job['id']} FALHOU: {$e->getMessage()}" . PHP_EOL;
        error_log("{$logPrefix} Job #{$job['id']} ({$tipo}): {$e->getMessage()}\n{$e->getTraceAsString()}");
    }
}
