<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use Throwable;

final class AuditoriaService
{
    public const ACAO_INSERT = 'INSERT';
    public const ACAO_UPDATE = 'UPDATE';
    public const ACAO_DELETE = 'DELETE';

    /**
     * Registra mutação na tabela logs_auditoria.
     * Falhas internas são suprimidas — auditoria não pode quebrar o fluxo principal.
     *
     * @param array<string, mixed> $dados
     */
    public function registrar(
        string $tabela,
        string $registroId,
        string $acao,
        array  $dados = [],
    ): void {
        try {
            $sql = "INSERT INTO logs_auditoria
                       (usuario_id, filial_id, tabela, registro_id, acao, dados_json)
                    VALUES
                       (:uid, :fid, :tab, :rid, :acao, :dados)";
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute([
                ':uid'   => Auth::id() ?? 0,
                ':fid'   => (int) ($_SESSION['filial_ativa'] ?? 1),
                ':tab'   => $tabela,
                ':rid'   => $registroId,
                ':acao'  => strtoupper($acao),
                ':dados' => empty($dados)
                    ? null
                    : json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            error_log('[AuditoriaService] falhou: ' . $e->getMessage());
        }
    }
}
