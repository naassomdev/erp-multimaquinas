<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;
use PDOException;

final class NecessidadeCompraRepository
{
    /**
     * Lista necessidades pendentes (peças sob encomenda esperando compra).
     * Inclui dados da OS e do produto para o painel do Admin.
     */
    public function listarPendentes(int $limit = 100): array
    {
        $sql = "SELECT n.id, n.os_id, n.equip_idx, n.produto_id, n.tecnico_item_id,
                       n.codigo, n.descricao, n.qtd, n.status, n.chave_nfe,
                       n.criado_em, n.atualizado_em,
                       os.nome_cliente, os.telefone, os.status AS os_status,
                       p.descricao AS produto_descricao, p.estoque_qty
                  FROM necessidades_compra n
             LEFT JOIN ordem_servico os ON os.id = n.os_id
             LEFT JOIN produtos      p  ON p.id  = n.produto_id
                 WHERE n.status = 'pendente'
              ORDER BY n.criado_em ASC
                 LIMIT :lim";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Verifica se já existe uma necessidade pendente para um produto numa OS.
     * Uso legado — para produtos cadastrados (produto_id IS NOT NULL).
     */
    public function existePendente(string $osId, int $produtoId): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id FROM necessidades_compra
              WHERE os_id = ? AND produto_id = ? AND status IN ('pendente','comprado')
              LIMIT 1"
        );
        $stmt->execute([$osId, $produtoId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Verifica se já existe uma necessidade (pendente ou comprada) para um item técnico específico.
     * Chave primária de idempotência — evita duplicar necessidade ao reaprovar.
     */
    public function existePendentePorTecnicoItem(int $tecnicoItemId): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id FROM necessidades_compra
              WHERE tecnico_item_id = ? AND status IN ('pendente','comprado')
              LIMIT 1"
        );
        $stmt->execute([$tecnicoItemId]);
        return (bool) $stmt->fetchColumn();
    }

    public function buscarAtivaPorTecnicoItem(int $tecnicoItemId): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT *
               FROM necessidades_compra
              WHERE tecnico_item_id = ?
                AND status IN ('pendente','comprado')
              ORDER BY id DESC
              LIMIT 1"
        );
        $stmt->execute([$tecnicoItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<int, array<string, mixed>> $itens
     * @return array<int, array<string, mixed>>
     */
    public function anexarStatusAosItens(array $itens): array
    {
        $ids = [];
        foreach ($itens as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if (empty($ids)) {
            return $itens;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT n.id, n.tecnico_item_id, n.status, n.criado_em, n.atualizado_em
               FROM necessidades_compra n
               JOIN (
                    SELECT tecnico_item_id, MAX(id) AS max_id
                      FROM necessidades_compra
                     WHERE tecnico_item_id IN ({$placeholders})
                     GROUP BY tecnico_item_id
               ) ult ON ult.max_id = n.id"
        );
        $stmt->execute(array_values($ids));

        $porItem = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $porItem[(int) $row['tecnico_item_id']] = $row;
        }

        foreach ($itens as &$item) {
            $itemId = (int) ($item['id'] ?? 0);
            $nec = $porItem[$itemId] ?? null;
            $item['necessidade_compra'] = $nec !== null ? [
                'id' => (int) $nec['id'],
                'status' => (string) $nec['status'],
                'criado_em' => (string) $nec['criado_em'],
                'atualizado_em' => (string) $nec['atualizado_em'],
            ] : null;
        }
        unset($item);

        return $itens;
    }

    /**
     * Conta necessidades com status 'pendente' para um equipamento específico.
     * Usado para badge virtual "Peças pendentes" nas views.
     */
    public function contarPendentesPorEquip(string $osId, int $equipIdx): int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM necessidades_compra
              WHERE os_id = ? AND equip_idx = ? AND status = 'pendente'"
        );
        $stmt->execute([$osId, $equipIdx]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{os_id:string, equip_idx:int, produto_id:int|null, tecnico_item_id?:int|null, codigo?:string, descricao:string, qtd:float} $dados
     */
    public function criar(array $dados): int
    {
        $sql = "INSERT INTO necessidades_compra
                  (os_id, equip_idx, produto_id, tecnico_item_id,
                   codigo, descricao, qtd, status, criado_em)
                VALUES (:os, :idx, :produto, :item, :codigo, :descricao, :qtd, 'pendente', NOW())";
        $pdo = Database::pdo();
        $stmt = $pdo->prepare($sql);

        $produtoId = isset($dados['produto_id']) && (int) $dados['produto_id'] > 0
            ? (int) $dados['produto_id']
            : null;
        $itemId = isset($dados['tecnico_item_id']) && (int) $dados['tecnico_item_id'] > 0
            ? (int) $dados['tecnico_item_id']
            : null;

        $stmt->bindValue(':os',        $dados['os_id']);
        $stmt->bindValue(':idx',       (int) $dados['equip_idx'], PDO::PARAM_INT);
        $stmt->bindValue(':produto',   $produtoId, $produtoId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':item',      $itemId, $itemId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':codigo',    (string) ($dados['codigo'] ?? ''));
        $stmt->bindValue(':descricao', (string) $dados['descricao']);
        $stmt->bindValue(':qtd',       (float)  ($dados['qtd'] ?? 1));
        $stmt->execute();
        return (int) $pdo->lastInsertId();
    }

    /**
     * Cria necessidade pendente para um item técnico mantendo idempotência forte.
     *
     * @param array{os_id:string, equip_idx:int, produto_id:int|null, tecnico_item_id:int, codigo?:string, descricao:string, qtd:float} $dados
     * @return array{created:bool, necessidade:array<string,mixed>}
     */
    public function criarIdempotentePorTecnicoItem(array $dados): array
    {
        $tecnicoItemId = (int) ($dados['tecnico_item_id'] ?? 0);
        if ($tecnicoItemId <= 0) {
            throw new \InvalidArgumentException('tecnico_item_id obrigatório.');
        }

        $existente = $this->buscarAtivaPorTecnicoItem($tecnicoItemId);
        if ($existente !== null) {
            return ['created' => false, 'necessidade' => $existente];
        }

        try {
            $id = $this->criar($dados);
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '23000') {
                $existente = $this->buscarAtivaPorTecnicoItem($tecnicoItemId);
                if ($existente !== null) {
                    return ['created' => false, 'necessidade' => $existente];
                }
            }
            throw $e;
        }

        $necessidade = $this->buscarPorId($id);
        if ($necessidade === null) {
            throw new \RuntimeException('Necessidade criada, mas não localizada.');
        }

        return ['created' => true, 'necessidade' => $necessidade];
    }

    /**
     * Marca como comprado todas as pendências do produto cuja NF-e chegou.
     * Retorna o número de registros atualizados.
     */
    public function marcarCompradoPorProduto(int $produtoId, string $chaveNfe): int
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE necessidades_compra
                SET status = 'comprado', chave_nfe = ?
              WHERE produto_id = ? AND status = 'pendente'"
        );
        $stmt->execute([$chaveNfe, $produtoId]);
        return $stmt->rowCount();
    }

    /**
     * Retorna as OS que tinham produto sob encomenda atendido pela NF-e.
     * Útil para notificar o técnico que a peça chegou.
     */
    public function osDoProduto(int $produtoId, string $chaveNfe): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT DISTINCT os_id, equip_idx
               FROM necessidades_compra
              WHERE produto_id = ? AND chave_nfe = ?"
        );
        $stmt->execute([$produtoId, $chaveNfe]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cancelar(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE necessidades_compra SET status = 'cancelado' WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
    }

    /**
     * Cancela necessidades de compra que correspondem a peças agora fornecidas
     * pelo cliente. Não remove histórico e não mexe em status já cancelado.
     *
     * @param array<int, array<string, mixed>> $orcItens
     */
    public function cancelarRelacionadasAoFornecimentoCliente(string $osId, int $equipIdx, array $orcItens): int
    {
        $codigos = [];
        $descricoes = [];
        foreach ($orcItens as $item) {
            $codigo = trim((string) ($item['codigo'] ?? ''));
            $descricao = trim(preg_replace('/\s+/', ' ', (string) ($item['descricao'] ?? '')) ?? '');
            if ($codigo !== '') {
                $codigos[$codigo] = true;
            }
            if ($descricao !== '') {
                $descricoes[$descricao] = true;
            }
        }

        $codigos = array_keys($codigos);
        $descricoes = array_keys($descricoes);
        if (empty($codigos) && empty($descricoes)) {
            return 0;
        }

        $params = [
            ':os' => $osId,
            ':idx' => $equipIdx,
        ];
        $matchParts = [];

        if (!empty($codigos)) {
            $phs = [];
            foreach ($codigos as $i => $codigo) {
                $ph = ':codigo_' . $i;
                $phs[] = $ph;
                $params[$ph] = $codigo;
            }
            $matchParts[] = 'TRIM(codigo) IN (' . implode(',', $phs) . ')';
        }

        if (!empty($descricoes)) {
            $phs = [];
            foreach ($descricoes as $i => $descricao) {
                $ph = ':descricao_' . $i;
                $phs[] = $ph;
                $params[$ph] = $descricao;
            }
            $matchParts[] = 'TRIM(descricao) IN (' . implode(',', $phs) . ')';
        }

        $sql = "UPDATE necessidades_compra
                   SET status = 'cancelado',
                       atualizado_em = NOW()
                 WHERE os_id = :os
                   AND equip_idx = :idx
                   AND status IN ('pendente','comprado')
                   AND (" . implode(' OR ', $matchParts) . ")";

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function contarPendentes(): int
    {
        $stmt = Database::pdo()->query(
            "SELECT COUNT(*) FROM necessidades_compra WHERE status = 'pendente'"
        );
        return (int) $stmt->fetchColumn();
    }

    /**
     * KPIs para o painel: contagens por status e tipo.
     * @return array{pendente:int, comprado:int, cancelado:int, manual_pendente:int, cadastrado_pendente:int}
     */
    public function kpis(): array
    {
        $pdo = Database::pdo();

        $stmt = $pdo->query(
            "SELECT
               SUM(n.status = 'pendente')  AS pendente,
               SUM(n.status = 'comprado')  AS comprado,
               SUM(n.status = 'cancelado') AS cancelado,
               SUM(n.status = 'pendente' AND n.produto_id IS NULL)     AS manual_pendente,
               SUM(n.status = 'pendente' AND n.produto_id IS NOT NULL) AS cadastrado_pendente,
               SUM(n.status = 'comprado' AND em.id IS NOT NULL)        AS comprado_com_entrada,
               SUM(n.status = 'comprado' AND em.id IS NULL)            AS comprado_sem_entrada
             FROM necessidades_compra n
             LEFT JOIN estoque_movimentacoes em
               ON em.origem_tipo = 'necessidade_compra'
              AND em.origem_id   = CAST(n.id AS CHAR) COLLATE utf8mb4_unicode_ci"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'pendente'              => (int) ($row['pendente']              ?? 0),
            'comprado'              => (int) ($row['comprado']              ?? 0),
            'cancelado'             => (int) ($row['cancelado']             ?? 0),
            'manual_pendente'       => (int) ($row['manual_pendente']       ?? 0),
            'cadastrado_pendente'   => (int) ($row['cadastrado_pendente']   ?? 0),
            'comprado_com_entrada'  => (int) ($row['comprado_com_entrada']  ?? 0),
            'comprado_sem_entrada'  => (int) ($row['comprado_sem_entrada']  ?? 0),
        ];
    }

    /**
     * Listagem com filtros para o painel administrativo.
     * @param array{status?:string, os_id?:string, q?:string, tipo?:string, fabricante?:string, agrupar?:string} $filtros
     * @return array<int, array<string, mixed>>
     */
    public function listarComFiltros(array $filtros, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filtros); // extraJoins ignorado — JOINs já existem abaixo

        // Quando agrupar=1, ordena por fabricante resolvido primeiro (para os headers de grupo na view)
        $agrupar = ($filtros['agrupar'] ?? '') === '1';
        $orderBy = $agrupar
            ? "COALESCE(NULLIF(TRIM(p.marca),''), NULLIF(TRIM(eq.fabricante),''), 'ZZZ_SEM_FABRICANTE'),
                  FIELD(n.status,'pendente','comprado','cancelado'),
                  n.criado_em DESC"
            : "FIELD(n.status,'pendente','comprado','cancelado'),
                  n.criado_em DESC";

        $sql = "SELECT n.id, n.os_id, n.equip_idx, n.produto_id, n.tecnico_item_id,
                       n.codigo, n.descricao, n.qtd, n.status, n.chave_nfe,
                       n.criado_em, n.atualizado_em,
                       os.nome_cliente, os.telefone, os.status AS os_status,
                       eq.nome AS equip_nome, eq.status_equip, eq.fabricante AS equip_fabricante,
                       p.descricao AS produto_descricao_cat, p.estoque_qty, p.marca AS produto_marca,
                       IF(em.id IS NOT NULL, 1, 0) AS entrada_registrada
                  FROM necessidades_compra n
             LEFT JOIN ordem_servico  os ON os.id  = n.os_id
             LEFT JOIN clientes        c  ON c.id   = os.cliente_id
             LEFT JOIN os_equipamento eq ON eq.os_id = n.os_id AND eq.ordem_idx = n.equip_idx
             LEFT JOIN produtos       p  ON p.id   = n.produto_id
             LEFT JOIN estoque_movimentacoes em
                    ON em.origem_tipo = 'necessidade_compra'
                   AND em.origem_id   = CAST(n.id AS CHAR) COLLATE utf8mb4_unicode_ci
                {$where}
              ORDER BY {$orderBy}
                 LIMIT :lim OFFSET :off";

        $pdo  = Database::pdo();
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array{status?:string, os_id?:string, q?:string, tipo?:string, fabricante?:string} $filtros
     */
    public function contarComFiltros(array $filtros): int
    {
        [$where, $params, $extraJoins] = $this->buildWhere($filtros);
        $sql  = "SELECT COUNT(*) FROM necessidades_compra n {$extraJoins} {$where}";
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM necessidades_compra WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Vincula uma necessidade manual a um produto cadastrado sem baixar estoque.
     *
     * Quando a necessidade veio de um item técnico, mantém o item e a necessidade
     * sincronizados para que a revalidação de estoque use o mesmo produto.
     *
     * @return array<string, mixed>
     */
    public function vincularProduto(int $id, int $produtoId): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "SELECT id, os_id, equip_idx, produto_id, tecnico_item_id, status
                   FROM necessidades_compra
                  WHERE id = ?
                  LIMIT 1
                  FOR UPDATE"
            );
            $stmt->execute([$id]);
            $necessidade = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($necessidade === false) {
                throw new \RuntimeException('Necessidade não encontrada.');
            }

            if ((string) $necessidade['status'] === 'cancelado') {
                throw new \RuntimeException('Necessidade cancelada não pode ser vinculada.');
            }

            $stmtProduto = $pdo->prepare(
                "SELECT id, codigo, descricao, estoque_qty, controla_estoque
                   FROM produtos
                  WHERE id = ?
                  LIMIT 1"
            );
            $stmtProduto->execute([$produtoId]);
            $produto = $stmtProduto->fetch(PDO::FETCH_ASSOC);
            if ($produto === false) {
                throw new \RuntimeException('Produto não encontrado no estoque.');
            }

            $pdo->prepare(
                "UPDATE necessidades_compra
                    SET produto_id = ?, atualizado_em = NOW()
                  WHERE id = ?
                  LIMIT 1"
            )->execute([$produtoId, $id]);

            $tecnicoItemId = (int) ($necessidade['tecnico_item_id'] ?? 0);
            if ($tecnicoItemId > 0) {
                $pdo->prepare(
                    "UPDATE tecnico_itens
                        SET produto_id = ?
                      WHERE id = ?
                        AND os_id = ?
                        AND equip_idx = ?
                      LIMIT 1"
                )->execute([
                    $produtoId,
                    $tecnicoItemId,
                    (string) $necessidade['os_id'],
                    (int) $necessidade['equip_idx'],
                ]);
            }

            $pdo->commit();

            return [
                'necessidade_id' => $id,
                'produto_id' => $produtoId,
                'tecnico_item_id' => $tecnicoItemId > 0 ? $tecnicoItemId : null,
                'produto' => $produto,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Atualiza o status de uma necessidade de compra.
     * Valores válidos: 'pendente', 'comprado', 'cancelado'.
     */
    public function atualizarStatus(int $id, string $novoStatus): void
    {
        $validos = ['pendente', 'comprado', 'cancelado'];
        if (!in_array($novoStatus, $validos, true)) {
            throw new \InvalidArgumentException("Status inválido: {$novoStatus}");
        }
        $stmt = Database::pdo()->prepare(
            "UPDATE necessidades_compra SET status = ? WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$novoStatus, $id]);
    }

    /**
     * Retorna necessidades que ainda bloqueiam a montagem.
     *
     * Uma necessidade antiga não bloqueia se o produto cadastrado já tem estoque
     * atual suficiente. A baixa física continua acontecendo somente na retirada.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarBloqueantesPorEquip(string $osId, int $equipIdx): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT n.id, n.os_id, n.equip_idx,
                    COALESCE(n.produto_id, ti.produto_id) AS produto_id,
                    n.produto_id AS necessidade_produto_id,
                    ti.produto_id AS item_produto_id,
                    n.tecnico_item_id,
                    n.codigo, n.descricao, n.qtd, n.status,
                    p.descricao AS produto_descricao,
                    p.estoque_qty,
                    p.controla_estoque,
                    IF(em.id IS NOT NULL, 1, 0) AS entrada_registrada,
                    CASE
                        WHEN COALESCE(n.produto_id, ti.produto_id) IS NULL THEN 'item_manual'
                        WHEN p.id IS NULL THEN 'produto_nao_localizado'
                        ELSE 'estoque_insuficiente'
                    END AS motivo_bloqueio
               FROM necessidades_compra n
          LEFT JOIN tecnico_itens ti
                 ON ti.id = n.tecnico_item_id
          LEFT JOIN produtos p
                 ON p.id = COALESCE(n.produto_id, ti.produto_id)
          LEFT JOIN estoque_movimentacoes em
                 ON em.origem_tipo = 'necessidade_compra'
                AND em.origem_id   = CAST(n.id AS CHAR) COLLATE utf8mb4_unicode_ci
              WHERE n.os_id = :os
                AND n.equip_idx = :idx
                AND n.status <> 'cancelado'
                AND n.status IN ('pendente','comprado')
                AND em.id IS NULL
                AND NOT EXISTS (
                    SELECT 1
                      FROM orcamentos o
                INNER JOIN orcamento_itens oi ON oi.orcamento_id = o.id
                     WHERE o.os_id = n.os_id
                       AND o.equip_idx = n.equip_idx
                       AND oi.fornecido_cliente = 1
                       AND (
                            (TRIM(COALESCE(n.codigo, '')) <> '' AND TRIM(oi.codigo) = TRIM(n.codigo))
                         OR (TRIM(COALESCE(n.descricao, '')) <> '' AND TRIM(oi.descricao) = TRIM(n.descricao))
                       )
                     LIMIT 1
                )
                AND (
                    COALESCE(n.produto_id, ti.produto_id) IS NULL
                    OR p.id IS NULL
                    OR (
                        COALESCE(p.controla_estoque, 1) = 1
                        AND COALESCE(p.estoque_qty, 0) < n.qtd
                    )
                )
           ORDER BY n.id ASC"
        );
        $stmt->execute([':os' => $osId, ':idx' => $equipIdx]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna true se existir ao menos uma necessidade de compra bloqueante
     * para o equipamento — usada para bloquear promoção automática para montagem.
     */
    public function temBloqueantesPorEquip(string $osId, int $equipIdx): bool
    {
        return $this->listarBloqueantesPorEquip($osId, $equipIdx) !== [];
    }

    /**
     * Retorna resumo de necessidades de compra agrupado por equipamento para uma OS.
     * Resultado indexado por equip_idx — uma única query, evita N+1.
     *
     * Campos retornados por equip_idx:
     *   pendentes              — status='pendente'
     *   compradas_sem_entrada  — status='comprado', sem movimentação, produto cadastrado
     *   manuais_sem_entrada    — sem produto vinculado na necessidade nem no item técnico
     *   entradas_feitas        — possui movimentação em estoque_movimentacoes
     *   bloqueantes_total      — pendentes + compradas_sem_entrada + manuais_sem_entrada
     *
     * Bloqueante = impede montagem se ainda não houver entrada e o estoque
     * atual do produto cadastrado for insuficiente.
     *
     * @return array<int, array{pendentes:int, compradas_sem_entrada:int, manuais_sem_entrada:int, entradas_feitas:int, bloqueantes_total:int}>
     */
    public function listarResumoPorOs(string $osId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT
                n.equip_idx,
                SUM(
                    n.status = 'pendente'
                    AND em.id IS NULL
                    AND NOT EXISTS (
                        SELECT 1
                          FROM orcamentos o
                    INNER JOIN orcamento_itens oi ON oi.orcamento_id = o.id
                         WHERE o.os_id = n.os_id
                           AND o.equip_idx = n.equip_idx
                           AND oi.fornecido_cliente = 1
                           AND (
                                (TRIM(COALESCE(n.codigo, '')) <> '' AND TRIM(oi.codigo) = TRIM(n.codigo))
                             OR (TRIM(COALESCE(n.descricao, '')) <> '' AND TRIM(oi.descricao) = TRIM(n.descricao))
                           )
                         LIMIT 1
                    )
                    AND (
                        COALESCE(n.produto_id, ti.produto_id) IS NULL
                        OR p.id IS NULL
                        OR (COALESCE(p.controla_estoque, 1) = 1 AND COALESCE(p.estoque_qty, 0) < n.qtd)
                    )
                ) AS pendentes,
                SUM(
                    n.status = 'comprado'
                    AND em.id IS NULL
                    AND COALESCE(n.produto_id, ti.produto_id) IS NOT NULL
                    AND NOT EXISTS (
                        SELECT 1
                          FROM orcamentos o
                    INNER JOIN orcamento_itens oi ON oi.orcamento_id = o.id
                         WHERE o.os_id = n.os_id
                           AND o.equip_idx = n.equip_idx
                           AND oi.fornecido_cliente = 1
                           AND (
                                (TRIM(COALESCE(n.codigo, '')) <> '' AND TRIM(oi.codigo) = TRIM(n.codigo))
                             OR (TRIM(COALESCE(n.descricao, '')) <> '' AND TRIM(oi.descricao) = TRIM(n.descricao))
                           )
                         LIMIT 1
                    )
                    AND (
                        p.id IS NULL
                        OR (COALESCE(p.controla_estoque, 1) = 1 AND COALESCE(p.estoque_qty, 0) < n.qtd)
                    )
                ) AS compradas_sem_entrada,
                SUM(
                    COALESCE(n.produto_id, ti.produto_id) IS NULL
                    AND em.id IS NULL
                    AND n.status IN ('pendente','comprado')
                    AND NOT EXISTS (
                        SELECT 1
                          FROM orcamentos o
                    INNER JOIN orcamento_itens oi ON oi.orcamento_id = o.id
                         WHERE o.os_id = n.os_id
                           AND o.equip_idx = n.equip_idx
                           AND oi.fornecido_cliente = 1
                           AND (
                                (TRIM(COALESCE(n.codigo, '')) <> '' AND TRIM(oi.codigo) = TRIM(n.codigo))
                             OR (TRIM(COALESCE(n.descricao, '')) <> '' AND TRIM(oi.descricao) = TRIM(n.descricao))
                           )
                         LIMIT 1
                    )
                ) AS manuais_sem_entrada,
                SUM(em.id IS NOT NULL) AS entradas_feitas,
                SUM(
                    n.status IN ('pendente','comprado')
                    AND em.id IS NULL
                    AND NOT EXISTS (
                        SELECT 1
                          FROM orcamentos o
                    INNER JOIN orcamento_itens oi ON oi.orcamento_id = o.id
                         WHERE o.os_id = n.os_id
                           AND o.equip_idx = n.equip_idx
                           AND oi.fornecido_cliente = 1
                           AND (
                                (TRIM(COALESCE(n.codigo, '')) <> '' AND TRIM(oi.codigo) = TRIM(n.codigo))
                             OR (TRIM(COALESCE(n.descricao, '')) <> '' AND TRIM(oi.descricao) = TRIM(n.descricao))
                           )
                         LIMIT 1
                    )
                    AND (
                        COALESCE(n.produto_id, ti.produto_id) IS NULL
                        OR p.id IS NULL
                        OR (COALESCE(p.controla_estoque, 1) = 1 AND COALESCE(p.estoque_qty, 0) < n.qtd)
                    )
                ) AS bloqueantes_total
              FROM necessidades_compra n
         LEFT JOIN tecnico_itens ti
                ON ti.id = n.tecnico_item_id
         LEFT JOIN produtos p
                ON p.id = COALESCE(n.produto_id, ti.produto_id)
         LEFT JOIN estoque_movimentacoes em
                ON em.origem_tipo = 'necessidade_compra'
               AND em.origem_id   = CAST(n.id AS CHAR) COLLATE utf8mb4_unicode_ci
             WHERE n.os_id = ? AND n.status <> 'cancelado'
          GROUP BY n.equip_idx"
        );
        $stmt->execute([$osId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['equip_idx']] = [
                'pendentes'             => (int) $row['pendentes'],
                'compradas_sem_entrada' => (int) $row['compradas_sem_entrada'],
                'manuais_sem_entrada'   => (int) $row['manuais_sem_entrada'],
                'entradas_feitas'       => (int) $row['entradas_feitas'],
                'bloqueantes_total'     => (int) $row['bloqueantes_total'],
            ];
        }
        return $result;
    }

    /**
     * Verifica se já existe uma movimentação de estoque de entrada
     * vinculada a esta necessidade (idempotência via origem_tipo/origem_id).
     * Evita dupla entrada sem precisar de migration.
     */
    public function entradaJaRegistrada(int $id): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT 1 FROM estoque_movimentacoes
              WHERE origem_tipo = 'necessidade_compra' AND origem_id = ?
              LIMIT 1"
        );
        $stmt->execute([(string) $id]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Retorna lista de fabricantes distintos com necessidades, resolvendo:
     *   produtos.marca (se preenchida) → os_equipamento.fabricante → ignorado
     * Usado para popular o dropdown de filtro por fabricante.
     * @return array<int, string>
     */
    public function listarFabricantesDisponiveis(): array
    {
        $sql = "SELECT DISTINCT
                       COALESCE(NULLIF(TRIM(p.marca),''), NULLIF(TRIM(eq.fabricante),'')) AS fabricante
                  FROM necessidades_compra n
             LEFT JOIN os_equipamento eq ON eq.os_id = n.os_id AND eq.ordem_idx = n.equip_idx
             LEFT JOIN produtos       p  ON p.id = n.produto_id
                 WHERE COALESCE(NULLIF(TRIM(p.marca),''), NULLIF(TRIM(eq.fabricante),'')) IS NOT NULL
              ORDER BY fabricante
                 LIMIT 200";
        $stmt = Database::pdo()->query($sql);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param array{status?:string, os_id?:string, q?:string, tipo?:string, fabricante?:string} $filtros
     * @return array{0:string, 1:array<string,mixed>, 2:string}
     *   [0] WHERE clause (empty string if no filters)
     *   [1] bound params
     *   [2] extra LEFT JOINs needed by the WHERE (for use in contarComFiltros — listarComFiltros already has them)
     */
    private function buildWhere(array $filtros): array
    {
        $clauses    = [];
        $params     = [];
        $extraJoins = [];

        $status = trim((string) ($filtros['status'] ?? ''));
        if ($status !== '' && $status !== 'todos') {
            $clauses[] = "n.status = :status";
            $params[':status'] = $status;
        }

        $osId = trim((string) ($filtros['os_id'] ?? ''));
        if ($osId !== '') {
            $clauses[] = "n.os_id = :os_id";
            $params[':os_id'] = $osId;
        }

        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            $extraJoins['os'] = 'LEFT JOIN ordem_servico os ON os.id = n.os_id';
            $extraJoins['c'] = 'LEFT JOIN clientes c ON c.id = os.cliente_id';
            $extraJoins['eq'] = 'LEFT JOIN os_equipamento eq ON eq.os_id = n.os_id AND eq.ordem_idx = n.equip_idx';
            $extraJoins['p'] = 'LEFT JOIN produtos p ON p.id = n.produto_id';

            $qFields = [
                'n.descricao',
                'n.codigo',
                'n.os_id',
                'os.nome_cliente',
                'c.nome',
                'c.nome_fantasia',
                'eq.nome',
                'eq.fabricante',
                'p.codigo',
                'p.descricao',
                'p.marca',
            ];
            $qParts = [];
            foreach ($qFields as $i => $field) {
                $ph = ':q_' . $i;
                $qParts[] = "{$field} LIKE {$ph}";
                $params[$ph] = '%' . $q . '%';
            }
            $clauses[] = '(' . implode(' OR ', $qParts) . ')';
        }

        $tipo = trim((string) ($filtros['tipo'] ?? ''));
        if ($tipo === 'manual') {
            $clauses[] = "n.produto_id IS NULL";
        } elseif ($tipo === 'cadastrado') {
            $clauses[] = "n.produto_id IS NOT NULL";
        }

        $fabricante = mb_strtoupper(trim((string) ($filtros['fabricante'] ?? '')), 'UTF-8');
        if ($fabricante !== '') {
            // Resolução: produtos.marca → os_equipamento.fabricante
            $extraJoins['eq'] = 'LEFT JOIN os_equipamento eq ON eq.os_id = n.os_id AND eq.ordem_idx = n.equip_idx';
            $extraJoins['p'] = 'LEFT JOIN produtos p ON p.id = n.produto_id';
            $clauses[]  = "COALESCE(NULLIF(TRIM(p.marca),''), NULLIF(TRIM(eq.fabricante),'')) = :fabricante";
            $params[':fabricante'] = $fabricante;
        }

        $where = empty($clauses) ? '' : 'WHERE ' . implode(' AND ', $clauses);
        return [$where, $params, implode(' ', $extraJoins)];
    }
}
