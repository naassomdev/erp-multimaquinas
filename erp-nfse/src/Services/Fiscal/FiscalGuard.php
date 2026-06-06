<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use PDO;

final class FiscalGuard
{
    public static function canTransmitReal(PDO $pdo): bool
    {
        $cfg = self::settings($pdo);
        return ($cfg['nfse_enabled'] ?? '0') === '1'
            && ($cfg['nfse_write_enabled'] ?? '0') === '1'
            && filter_var((string)($cfg['nfse_real_enabled'] ?? 'false'), FILTER_VALIDATE_BOOLEAN)
            && in_array(($cfg['nfse_ambiente'] ?? 'homologacao'), ['homologacao', 'producao'], true);
    }

    public static function canRunWorker(PDO $pdo): bool
    {
        $cfg = self::settings($pdo);
        return self::canTransmitReal($pdo)
            && ($cfg['nfse_exigir_conferencia_manual'] ?? '1') !== '1';
    }

    public static function blockMessage(PDO $pdo, string $action): string
    {
        $cfg = self::settings($pdo);
        $flags = [
            'nfse_enabled=' . ($cfg['nfse_enabled'] ?? '0'),
            'nfse_write_enabled=' . ($cfg['nfse_write_enabled'] ?? '0'),
            'nfse_real_enabled=' . ($cfg['nfse_real_enabled'] ?? 'false'),
            'nfse_exigir_conferencia_manual=' . ($cfg['nfse_exigir_conferencia_manual'] ?? '1'),
        ];

        return 'Integração fiscal antiga bloqueada por configuração em ' . $action . ' (' . implode(', ', $flags) . ').';
    }

    public static function auditBlock(PDO $pdo, string $action, ?int $notaId, ?int $usuarioId = null): void
    {
        $cfg = self::settings($pdo);
        $payload = [
            'acao_tentada' => $action,
            'motivo' => self::blockMessage($pdo, $action),
            'flags' => [
                'nfse_enabled' => $cfg['nfse_enabled'] ?? '0',
                'nfse_write_enabled' => $cfg['nfse_write_enabled'] ?? '0',
                'nfse_real_enabled' => $cfg['nfse_real_enabled'] ?? 'false',
                'nfse_exigir_conferencia_manual' => $cfg['nfse_exigir_conferencia_manual'] ?? '1',
            ],
        ];

        try {
            $st = $pdo->prepare(
                "INSERT INTO logs_auditoria
                    (usuario_id, filial_id, tabela, registro_id, acao, dados_json)
                 VALUES (?, 1, 'notas_fiscais', ?, 'NFSE_BLOQUEIO', ?)"
            );
            $st->execute([
                $usuarioId ?? 0,
                (string)($notaId ?? 0),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $e) {
            error_log('[FiscalGuard] falha ao auditar bloqueio: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string,string>
     */
    private static function settings(PDO $pdo): array
    {
        try {
            $st = $pdo->query(
                "SELECT chave, valor
                   FROM configuracoes
                  WHERE chave IN (
                    'nfse_enabled',
                    'nfse_write_enabled',
                    'nfse_real_enabled',
                    'nfse_ambiente',
                    'nfse_exigir_conferencia_manual'
                  )"
            );
            return $st ? $st->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
