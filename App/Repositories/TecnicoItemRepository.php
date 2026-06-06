<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class TecnicoItemRepository
{
    public function buscarPorId(int $id): ?array
    {
        $sql = "SELECT ti.id, ti.os_id, ti.equip_idx, ti.codigo, ti.produto_id, ti.descricao,
                       ti.qtd, ti.valor_unit, ti.valor_total, ti.ativo,
                       ti.origem_orcamento_item_id, ti.created_at,
                       p.controla_estoque
                  FROM tecnico_itens ti
             LEFT JOIN produtos p ON p.id = ti.produto_id
                 WHERE ti.id = :id
                 LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listarPorEquipamento(string $osId, int $equipIdx, int $limit = 200): array
    {
        $sql = "SELECT ti.id, ti.os_id, ti.equip_idx, ti.codigo, ti.produto_id, ti.descricao,
                       ti.qtd, ti.valor_unit, ti.valor_total, ti.ativo,
                       ti.origem_orcamento_item_id, ti.created_at,
                       p.controla_estoque
                  FROM tecnico_itens ti
             LEFT JOIN produtos p ON p.id = ti.produto_id
                 WHERE ti.os_id = :os AND ti.equip_idx = :idx
                   AND ti.ativo = 1
                 ORDER BY ti.created_at ASC
                 LIMIT :lim";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':os',  $osId);
        $stmt->bindValue(':idx', $equipIdx, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,    PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista itens de um equipamento específico que têm produto_id vinculado —
     * usado para verificação de estoque antes de promover para montagem.
     */
    public function listarComProdutoPorEquipamento(string $osId, int $equipIdx): array
    {
        $sql = "SELECT id, produto_id, codigo, descricao, qtd
                  FROM tecnico_itens
                 WHERE os_id = :os AND equip_idx = :idx AND produto_id IS NOT NULL
                   AND ativo = 1
                 ORDER BY id ASC";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':os',  $osId);
        $stmt->bindValue(':idx', $equipIdx, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista todos os itens de uma OS (todos equipamentos), incluindo itens manuais
     * sem produto_id. Usado para geração de necessidades_compra.
     */
    public function listarTodosPorOs(string $osId): array
    {
        $sql = "SELECT id, os_id, equip_idx, produto_id, codigo, descricao, qtd
                  FROM tecnico_itens
                 WHERE os_id = :os
                   AND ativo = 1
                 ORDER BY equip_idx ASC, id ASC";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':os' => $osId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista todos os itens de uma OS (todos equipamentos), apenas os que têm produto_id
     * vinculado — usado para baixa de estoque na retirada.
     */
    public function listarItensComProdutoPorOs(string $osId): array
    {
        $sql = "SELECT id, os_id, equip_idx, produto_id, codigo, descricao, qtd
                  FROM tecnico_itens
                 WHERE os_id = :os AND produto_id IS NOT NULL
                   AND ativo = 1
                 ORDER BY id ASC";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':os' => $osId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array{os_id:string, equip_idx:int, codigo?:string, produto_id?:int|null, descricao:string, qtd?:float, valor_unit?:float} $dados
     */
    public function criar(array $dados): int
    {
        $qtd       = (float) ($dados['qtd']        ?? 1);
        $valorUnit = (float) ($dados['valor_unit'] ?? 0);
        $valorTot  = $qtd * $valorUnit;
        $produtoId = isset($dados['produto_id']) && (int) $dados['produto_id'] > 0
            ? (int) $dados['produto_id']
            : null;

        $sql = "INSERT INTO tecnico_itens
                  (os_id, equip_idx, codigo, produto_id, descricao, qtd, valor_unit, valor_total,
                   ativo, origem_orcamento_item_id)
                VALUES
                  (:os, :idx, :codigo, :produto_id, :descricao, :qtd, :vu, :vt,
                   :ativo, :origem_orcamento_item_id)";
        $pdo = Database::pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':os',         $dados['os_id']);
        $stmt->bindValue(':idx',        (int) $dados['equip_idx'], PDO::PARAM_INT);
        $stmt->bindValue(':codigo',     trim((string) ($dados['codigo'] ?? '')));
        $stmt->bindValue(':produto_id', $produtoId, $produtoId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':descricao',  trim((string) $dados['descricao']));
        $stmt->bindValue(':qtd',        $qtd);
        $stmt->bindValue(':vu',         $valorUnit);
        $stmt->bindValue(':vt',         $valorTot);
        $stmt->bindValue(':ativo',      isset($dados['ativo']) ? (int) $dados['ativo'] : 1, PDO::PARAM_INT);
        $origemOrcItemId = isset($dados['origem_orcamento_item_id']) && (int) $dados['origem_orcamento_item_id'] > 0
            ? (int) $dados['origem_orcamento_item_id']
            : null;
        $stmt->bindValue(
            ':origem_orcamento_item_id',
            $origemOrcItemId,
            $origemOrcItemId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $stmt->execute();
        return (int) $pdo->lastInsertId();
    }

    public function listarPorEquipamentoIncluindoInativos(string $osId, int $equipIdx): array
    {
        $sql = "SELECT id, os_id, equip_idx, codigo, produto_id, descricao,
                       qtd, valor_unit, valor_total, ativo,
                       origem_orcamento_item_id, created_at
                  FROM tecnico_itens
                 WHERE os_id = :os AND equip_idx = :idx
                 ORDER BY id ASC";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':os', $osId);
        $stmt->bindValue(':idx', $equipIdx, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array{codigo:string, produto_id:int|null, descricao:string, qtd:float, valor_unit:float, valor_total:float, origem_orcamento_item_id:int} $dados
     */
    public function atualizarPeloOrcamento(int $id, array $dados): void
    {
        $produtoId = isset($dados['produto_id']) && (int) $dados['produto_id'] > 0
            ? (int) $dados['produto_id']
            : null;

        $sql = "UPDATE tecnico_itens
                   SET codigo = :codigo,
                       produto_id = :produto_id,
                       descricao = :descricao,
                       qtd = :qtd,
                       valor_unit = :vu,
                       valor_total = :vt,
                       ativo = 1,
                       origem_orcamento_item_id = :origem_orcamento_item_id,
                       atualizado_por_orcamento_em = NOW(),
                       removido_orcamento_em = NULL
                 WHERE id = :id
                 LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':codigo', trim((string) $dados['codigo']));
        $stmt->bindValue(':produto_id', $produtoId, $produtoId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':descricao', trim((string) $dados['descricao']));
        $stmt->bindValue(':qtd', (float) $dados['qtd']);
        $stmt->bindValue(':vu', (float) $dados['valor_unit']);
        $stmt->bindValue(':vt', (float) $dados['valor_total']);
        $stmt->bindValue(':origem_orcamento_item_id', (int) $dados['origem_orcamento_item_id'], PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function desativarPeloOrcamento(int $id): void
    {
        $sql = "UPDATE tecnico_itens
                   SET ativo = 0,
                       atualizado_por_orcamento_em = NOW(),
                       removido_orcamento_em = NOW()
                 WHERE id = :id
                 LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':id' => $id]);
    }

    public function excluir(int $id): void
    {
        $sql = "DELETE FROM tecnico_itens WHERE id = :id LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':id' => $id]);
    }
}
