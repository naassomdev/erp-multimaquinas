<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';

use App\Core\Database;
use App\Jobs\EmitirNfseJob;
use App\Jobs\NotificarClienteJob;
use App\Queue\DatabaseQueue;
use App\Queue\JobBlockedException;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo');

$pdo = Database::pdo();

$queue = new DatabaseQueue($pdo);
$handlers = [
    'emitir_nfse'       => new EmitirNfseJob($pdo),
    'notificar_cliente' => new NotificarClienteJob($pdo),
];

$workerPid = getmypid();
$logPrefix = "[worker::{$workerPid}]";

echo "{$logPrefix} Iniciado em " . date('Y-m-d H:i:s') . PHP_EOL;

while (true) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    $job = $queue->claim('default');

    if ($job === null) {
        sleep(5);
        continue;
    }

    $tipo = $job['tipo'];
    echo "{$logPrefix} Job #{$job['id']} ({$tipo}) — tentativa {$job['tentativas']}" . PHP_EOL;

    if (!isset($handlers[$tipo])) {
        $queue->failed($job['id'], "Handler '{$tipo}' não registrado.", false);
        echo "{$logPrefix} Job #{$job['id']} descartado: handler não encontrado." . PHP_EOL;
        continue;
    }

    try {
        $payload = $job['payload'];
        $payload['_job_id'] = (int)$job['id'];
        $handlers[$tipo]->handle($payload);
        $queue->done((int)$job['id']);
        echo "{$logPrefix} Job #{$job['id']} concluído com sucesso." . PHP_EOL;
    } catch (JobBlockedException $e) {
        $queue->releaseBlocked((int)$job['id'], $e->getMessage());
        echo "{$logPrefix} Job #{$job['id']} bloqueado por configuração: {$e->getMessage()}" . PHP_EOL;
    } catch (\Throwable $e) {
        $queue->failed((int)$job['id'], $e->getMessage());
        echo "{$logPrefix} Job #{$job['id']} FALHOU: {$e->getMessage()}" . PHP_EOL;
        error_log("{$logPrefix} Job #{$job['id']} ({$tipo}): {$e->getMessage()}\n{$e->getTraceAsString()}");
    }
}
