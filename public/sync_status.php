<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

define('BASE_PATH', dirname(__DIR__));

$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

use App\Core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Rotina legada desativada via HTTP.\n";
    exit;
}

try {
    $pdo = Database::pdo();

    $checks = [
        'os_cancelada_equip_nao_cancelado' => "
            SELECT COUNT(*)
              FROM ordem_servico o
              JOIN os_equipamento e ON e.os_id = o.id
             WHERE o.status = 'cancelado'
               AND e.status_equip != 'cancelado'
        ",
        'os_andamento_equip_aberta' => "
            SELECT COUNT(*)
              FROM ordem_servico o
              JOIN os_equipamento e ON e.os_id = o.id
             WHERE o.status = 'andamento'
               AND e.status_equip = 'aberta'
        ",
        'os_retirada_equip_nao_retirado' => "
            SELECT COUNT(*)
              FROM ordem_servico o
              JOIN os_equipamento e ON e.os_id = o.id
             WHERE o.status = 'retirado'
               AND e.status_equip NOT IN ('retirado', 'cancelado')
        ",
    ];

    echo "sync_status.php em modo seguro (somente leitura)\n";
    echo "Nenhum UPDATE sera executado.\n\n";

    foreach ($checks as $label => $sql) {
        $count = (int) $pdo->query($sql)->fetchColumn();
        echo str_pad($label, 34) . ": {$count}\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Erro: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
