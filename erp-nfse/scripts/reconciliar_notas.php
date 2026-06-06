<?php
declare(strict_types=1);

/**
 * Cron de reconciliação — re-enfileira notas fiscais pendentes que perderam
 * seu job (ex: worker morreu antes de processar, enqueue falhou após commit).
 *
 * Executar a cada 15 minutos:
 *   A cada 15 minutos: php /var/www/erp/scripts/reconciliar_notas.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Queue\DatabaseQueue;
use App\Services\Fiscal\FiscalGuard;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo');

require dirname(__DIR__) . '/config/database.php'; // define $pdo

if (!FiscalGuard::canRunWorker($pdo)) {
    FiscalGuard::auditBlock($pdo, 'erp-nfse_reconciliar_emitir_nfse', null, 0);
    echo '[' . date('Y-m-d H:i:s') . '] Reconciliação fiscal bloqueada por configuração.' . PHP_EOL;
    $pdo = null;
    exit(0);
}

$queue   = new DatabaseQueue($pdo);
$logTs   = date('Y-m-d H:i:s');
$reenfileirados = 0;

try {
    /*
     * Seleciona notas com status 'pendente' criadas há mais de 10 minutos
     * E que não possuem um job ativo na fila (pending ou processing).
     *
     * A subconsulta usa JSON_UNQUOTE + JSON_EXTRACT para comparar o nota_id
     * dentro do payload JSON — disponível no MySQL 5.7+ e MariaDB 10.2+.
     */
    $st = $pdo->query(
        "SELECT id
         FROM notas_fiscais
         WHERE status = 'pendente'
           AND criado_em < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
           AND id NOT IN (
               SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.nota_id')) AS UNSIGNED)
               FROM jobs
               WHERE tipo   = 'emitir_nfse'
                 AND status IN ('pending', 'processing')
           )
         LIMIT 50"
    );

    foreach ($st->fetchAll() as $row) {
        $notaId = (int)$row['id'];
        $queue->enqueue('emitir_nfse', ['nota_id' => $notaId]);
        echo "[{$logTs}] Re-enfileirado: nota #{$notaId}" . PHP_EOL;
        $reenfileirados++;
    }

    echo "[{$logTs}] Reconciliação concluída. Re-enfileirados: {$reenfileirados}" . PHP_EOL;

} catch (\Throwable $e) {
    echo "[{$logTs}] ERRO na reconciliação: {$e->getMessage()}" . PHP_EOL;
    error_log("[reconciliar_notas] {$e->getMessage()}\n{$e->getTraceAsString()}");
    exit(1);
} finally {
    $pdo = null;
}
