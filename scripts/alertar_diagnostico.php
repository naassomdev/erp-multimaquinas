<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/vendor/autoload.php';

use App\Core\Database;
use App\Repositories\NotificacaoTecnicoRepository;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo');

$pdo = Database::pdo();

// Ler prazo configurado (padrão: 20 dias)
$cfgStmt = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'alerta_dias_os_sem_diagnostico'");
$cfgStmt->execute();
$row = $cfgStmt->fetch(PDO::FETCH_ASSOC);
$diasLimite = $row ? max(1, (int) $row['valor']) : 20;

echo "[alertar_diagnostico] Prazo: {$diasLimite} dias | Início: " . date('Y-m-d H:i:s') . PHP_EOL;

// Equipamentos abertos/andamento sem diagnóstico há mais de N dias
$sql = "
    SELECT oe.os_id,
           oe.ordem_idx,
           oe.nome                                          AS equip_nome,
           DATEDIFF(NOW(), oe.status_equip_em)              AS dias_corridos
    FROM os_equipamento oe
    WHERE oe.status_equip IN ('aberta', 'andamento')
      AND oe.diagnostico_concluido_em IS NULL
      AND oe.status_equip_em <= NOW() - INTERVAL :dias DAY
    ORDER BY dias_corridos DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':dias' => $diasLimite]);
$equipamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($equipamentos)) {
    echo "[alertar_diagnostico] Nenhum equipamento acima do prazo." . PHP_EOL;
    exit(0);
}

$notifRepo = new NotificacaoTecnicoRepository();
$criados   = 0;
$ignorados = 0;

foreach ($equipamentos as $equip) {
    $osId     = (string) $equip['os_id'];
    $equipIdx = (int)    $equip['ordem_idx'];
    $dias     = (int)    $equip['dias_corridos'];
    $nome     = (string) $equip['equip_nome'];

    // Dedupe: não notificar se já existe alerta de diagnóstico para este equip nas últimas 23h
    $dedup = $pdo->prepare("
        SELECT COUNT(*) FROM notificacoes_tecnico
        WHERE os_id = :os AND equip_idx = :idx AND tipo = 'diagnostico'
          AND created_at >= NOW() - INTERVAL 23 HOUR
    ");
    $dedup->execute([':os' => $osId, ':idx' => $equipIdx]);
    if ((int) $dedup->fetchColumn() > 0) {
        $ignorados++;
        continue;
    }

    $mensagem = "Equipamento \"{$nome}\" (OS {$osId}) aguarda diagnóstico há {$dias} dias corridos.";

    try {
        $notifRepo->criar($osId, $equipIdx, 'diagnostico', $mensagem, 'oficina');
        echo "[OK] OS {$osId} equip #{$equipIdx} — {$dias} dias — notificado." . PHP_EOL;
        $criados++;
    } catch (Throwable $e) {
        echo "[ERRO] OS {$osId} equip #{$equipIdx}: " . $e->getMessage() . PHP_EOL;
    }
}

echo "[alertar_diagnostico] Criadas: {$criados} | Ignoradas (recentes): {$ignorados}" . PHP_EOL;
