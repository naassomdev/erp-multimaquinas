<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class ServicoTerceiroRepository
{
    /** @return array<int, array<string, mixed>> */
    public function listarPorEquipamento(string $osId, int $equipIdx): array
    {
        $sql = "SELECT *
                  FROM servicos_terceiros
                 WHERE os_id = :os AND equip_idx = :idx
                 ORDER BY FIELD(status, 'enviado', 'aguardando_envio', 'retornado', 'cancelado'),
                          criado_em DESC,
                          id DESC";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':os' => $osId, ':idx' => $equipIdx]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    public function listarPorOsAgrupado(string $osId): array
    {
        $sql = "SELECT *
                  FROM servicos_terceiros
                 WHERE os_id = :os
                 ORDER BY equip_idx ASC,
                          FIELD(status, 'enviado', 'aguardando_envio', 'retornado', 'cancelado'),
                          criado_em DESC,
                          id DESC";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':os' => $osId]);

        $porEquip = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $porEquip[(int) $row['equip_idx']][] = $row;
        }
        return $porEquip;
    }

    public function buscar(int $id): ?array
    {
        $sql = "SELECT * FROM servicos_terceiros WHERE id = :id LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function existeEnviado(string $osId, int $equipIdx): bool
    {
        $sql = "SELECT 1
                  FROM servicos_terceiros
                 WHERE os_id = :os
                   AND equip_idx = :idx
                   AND status = 'enviado'
                 LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':os' => $osId, ':idx' => $equipIdx]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array{
     *   os_id:string,
     *   equip_idx:int,
     *   tecnico_item_id?:int|null,
     *   tipo:string,
     *   fornecedor_nome?:string|null,
     *   status:string,
     *   saida_em?:string|null,
     *   previsao_retorno?:string|null,
     *   observacao?:string|null,
     *   criado_por?:int|null,
     *   atualizado_por?:int|null
     * } $dados
     */
    public function criar(array $dados): int
    {
        $sql = "INSERT INTO servicos_terceiros
                    (os_id, equip_idx, tecnico_item_id, tipo, fornecedor_nome, status,
                     saida_em, previsao_retorno, observacao, criado_por, atualizado_por, atualizado_em)
                VALUES
                    (:os, :idx, :item_id, :tipo, :fornecedor, :status,
                     :saida, :previsao, :observacao, :criado_por, :atualizado_por, NOW())";
        $pdo = Database::pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':os'             => $dados['os_id'],
            ':idx'            => (int) $dados['equip_idx'],
            ':item_id'        => $dados['tecnico_item_id'] ?? null,
            ':tipo'           => $dados['tipo'],
            ':fornecedor'     => $dados['fornecedor_nome'] ?? null,
            ':status'         => $dados['status'],
            ':saida'          => $dados['saida_em'] ?? null,
            ':previsao'       => $dados['previsao_retorno'] ?? null,
            ':observacao'     => $dados['observacao'] ?? null,
            ':criado_por'     => $dados['criado_por'] ?? null,
            ':atualizado_por' => $dados['atualizado_por'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function registrarRetorno(int $id, int $usuarioId, ?string $observacaoRetorno): void
    {
        $sql = "UPDATE servicos_terceiros
                   SET status = 'retornado',
                       retorno_em = NOW(),
                       observacao_retorno = :obs,
                       atualizado_por = :uid,
                       atualizado_em = NOW()
                 WHERE id = :id
                   AND status IN ('aguardando_envio', 'enviado')";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':id'  => $id,
            ':uid' => $usuarioId > 0 ? $usuarioId : null,
            ':obs' => $observacaoRetorno,
        ]);
    }

    public function cancelar(int $id, int $usuarioId, ?string $observacao): void
    {
        $sql = "UPDATE servicos_terceiros
                   SET status = 'cancelado',
                       observacao = CASE
                           WHEN :obs_check IS NULL OR :obs_check = '' THEN observacao
                           WHEN observacao IS NULL OR observacao = '' THEN :obs_set
                           ELSE CONCAT(observacao, CHAR(10), :obs_append)
                       END,
                       atualizado_por = :uid,
                       atualizado_em = NOW()
                 WHERE id = :id
                   AND status IN ('aguardando_envio', 'enviado')";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':id'  => $id,
            ':uid' => $usuarioId > 0 ? $usuarioId : null,
            ':obs_check' => $observacao,
            ':obs_set' => $observacao,
            ':obs_append' => $observacao,
        ]);
    }
}
