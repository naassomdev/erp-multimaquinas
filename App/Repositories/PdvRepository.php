<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;
use App\Services\Pdv\PdvFiscalStatus;
use App\Services\Pdv\PdvSaleStatus;

final class PdvRepository
{
    public function __construct(private readonly ?PDO $pdo = null) {}

    private function pdo(): PDO
    {
        return $this->pdo ?? Database::pdo();
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function criarVendaRascunho(array $dados): int
    {
        $pdo = $this->pdo();
        $numero = $this->proximoNumero();
        $stmt = $pdo->prepare(
            "INSERT INTO vendas_balcao (
                numero, cliente_id, os_id, origem_tipo, lancamento_receber_id,
                nome_cliente, telefone, email, forma_pagamento,
                subtotal, desconto, total, troco,
                status, nfe_status, nfe_serie,
                operador_id, obs,
                status_venda, status_fiscal,
                total_bruto, total_desconto, total_acrescimo, total_liquido,
                observacoes, created_by, updated_by, cancelled_at, cancel_reason
            ) VALUES (
                :numero, :cliente_id, :os_id, :origem_tipo, :lancamento_receber_id,
                :nome_cliente, :telefone, :email, :forma_pagamento,
                :subtotal, :desconto, :total, :troco,
                :status, :nfe_status, :nfe_serie,
                :operador_id, :obs,
                :status_venda, :status_fiscal,
                :total_bruto, :total_desconto, :total_acrescimo, :total_liquido,
                :observacoes, :created_by, :updated_by, :cancelled_at, :cancel_reason
            )"
        );

        $statusVenda = (string)($dados['status_venda'] ?? PdvSaleStatus::RASCUNHO);
        $statusFiscal = (string)($dados['status_fiscal'] ?? PdvFiscalStatus::NAO_APLICAVEL);
        $nfeStatus = isset($dados['nfe_status']) && trim((string)$dados['nfe_status']) !== ''
            ? trim((string)$dados['nfe_status'])
            : ($statusFiscal === PdvFiscalStatus::NAO_APLICAVEL
                ? PdvFiscalStatus::NAO_APLICAVEL
                : PdvFiscalStatus::PENDENTE);
        $observacoes = trim((string)($dados['observacoes'] ?? ''));
        $createdBy = isset($dados['created_by']) ? (int)$dados['created_by'] : null;
        $updatedBy = isset($dados['updated_by']) ? (int)$dados['updated_by'] : $createdBy;

        $stmt->execute([
            ':numero' => $numero,
            ':cliente_id' => $this->nullableInt($dados['cliente_id'] ?? null),
            ':os_id' => $this->nullableString($dados['os_id'] ?? null),
            ':origem_tipo' => $this->nullableString($dados['origem_tipo'] ?? null),
            ':lancamento_receber_id' => $this->nullableInt($dados['lancamento_receber_id'] ?? null),
            ':nome_cliente' => trim((string)($dados['nome_cliente'] ?? '')),
            ':telefone' => trim((string)($dados['telefone'] ?? '')),
            ':email' => trim((string)($dados['email'] ?? '')),
            ':forma_pagamento' => trim((string)($dados['forma_pagamento'] ?? 'dinheiro')),
            ':subtotal' => 0.00,
            ':desconto' => 0.00,
            ':total' => 0.00,
            ':troco' => 0.00,
            ':status' => $statusVenda,
            ':nfe_status' => $nfeStatus,
            ':nfe_serie' => trim((string)($dados['nfe_serie'] ?? '001')),
            ':operador_id' => $this->nullableInt($dados['operador_id'] ?? $createdBy),
            ':obs' => $observacoes !== '' ? $observacoes : null,
            ':status_venda' => $statusVenda,
            ':status_fiscal' => $statusFiscal,
            ':total_bruto' => 0.00,
            ':total_desconto' => 0.00,
            ':total_acrescimo' => 0.00,
            ':total_liquido' => 0.00,
            ':observacoes' => $observacoes !== '' ? $observacoes : null,
            ':created_by' => $createdBy,
            ':updated_by' => $updatedBy,
            ':cancelled_at' => null,
            ':cancel_reason' => null,
        ]);

        return (int)$pdo->lastInsertId();
    }

    public function buscarVendaPorId(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM vendas_balcao WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function buscarVendaPorIdForUpdate(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM vendas_balcao WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function buscarUsuarioPorId(int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, nome, email, nivel_acesso FROM usuarios WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array<int, array<string, mixed>>
     */
    public function listarVendas(array $filtros, int $limit, int $offset): array
    {
        [$joins, $where, $params] = $this->buildSalesListQueryParts($filtros, true);

        $sql = "SELECT
                    v.id,
                    v.numero,
                    v.created_at,
                    v.status_venda,
                    v.status_fiscal,
                    v.forma_pagamento,
                    v.total_liquido,
                    v.observacoes,
                    v.cliente_id,
                    v.operador_id,
                    v.created_by,
                    v.lancamento_receber_id,
                    c.nome AS cliente_nome,
                    u.nome AS operador_nome,
                    lr.status AS financeiro_status,
                    COALESCE(vi.itens_count, 0) AS itens_count,
                    COALESCE(vi.itens_com_estoque, 0) AS itens_com_estoque,
                    COALESCE(vd.documentos_fiscais_count, 0) AS documentos_fiscais_count,
                    vd.fiscal_tipo_documento,
                    vd.fiscal_modelo,
                    vd.fiscal_numero,
                    vd.fiscal_serie
                FROM vendas_balcao v
                {$joins}
                {$where}
                ORDER BY v.id DESC
                LIMIT :lim OFFSET :off";

        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':lim', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':off', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $filtros
     */
    public function contarVendas(array $filtros): int
    {
        [$joins, $where, $params] = $this->buildSalesListQueryParts($filtros, false);
        $sql = "SELECT COUNT(*) FROM vendas_balcao v {$joins} {$where}";
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<int, array{id:int,nome:string}>
     */
    public function listarOperadoresComVendas(): array
    {
        $stmt = $this->pdo()->query(
            "SELECT DISTINCT
                u.id,
                u.nome
             FROM vendas_balcao v
             INNER JOIN usuarios u
                ON u.id = COALESCE(v.operador_id, v.created_by)
             ORDER BY u.nome ASC"
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $row): array => [
            'id' => (int)($row['id'] ?? 0),
            'nome' => (string)($row['nome'] ?? ''),
        ], $rows ?: []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listarRascunhos(int $limit = 20): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id, cliente_id, origem_tipo, status_venda, status_fiscal,
                    total_liquido, observacoes, created_by, created_at, cancel_reason
               FROM vendas_balcao
              WHERE status_venda = :status
              ORDER BY id DESC
              LIMIT :lim"
        );
        $stmt->bindValue(':status', PdvSaleStatus::RASCUNHO);
        $stmt->bindValue(':lim', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listarItens(int $vendaId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM vendas_itens WHERE venda_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$vendaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listarItensPorVendaForUpdate(int $vendaId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM vendas_itens WHERE venda_id = ? ORDER BY id ASC FOR UPDATE'
        );
        $stmt->execute([$vendaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarItemDaVendaPorId(int $vendaId, int $itemId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM vendas_itens WHERE venda_id = ? AND id = ? LIMIT 1'
        );
        $stmt->execute([$vendaId, $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function buscarItemDaVendaPorIdForUpdate(int $vendaId, int $itemId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM vendas_itens WHERE venda_id = ? AND id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$vendaId, $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function buscarProdutoPorIdForUpdate(int $produtoId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM produtos WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$produtoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function buscarMovimentacaoEstoquePorIdForUpdate(int $movimentacaoId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM estoque_movimentacoes WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$movimentacaoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function buscarLancamentoReceberPorIdForUpdate(int $lancamentoId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM lancamentos_receber WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$lancamentoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function inserirItem(int $vendaId, array $dados): int
    {
        $quantidade = max(0.001, (float)($dados['quantidade'] ?? $dados['qtd'] ?? 1));
        $valorUnitario = max(0.0, (float)($dados['valor_unitario'] ?? $dados['valor_unit'] ?? 0));
        $subtotal = round($quantidade * $valorUnitario, 2);
        $desconto = max(0.0, (float)($dados['desconto'] ?? 0));
        $acrescimo = max(0.0, (float)($dados['acrescimo'] ?? 0));
        $totalLiquido = round($subtotal - $desconto + $acrescimo, 2);

        $stmt = $this->pdo()->prepare(
            "INSERT INTO vendas_itens (
                venda_id, produto_id, tecnico_item_id, orcamento_item_id,
                origem_tipo, origem_id, codigo, descricao, marca, ncm,
                quantidade, valor_unitario, desconto_item, subtotal,
                desconto, acrescimo, total_liquido, estoque_movimentacao_id
            ) VALUES (
                :venda_id, :produto_id, :tecnico_item_id, :orcamento_item_id,
                :origem_tipo, :origem_id, :codigo, :descricao, :marca, :ncm,
                :quantidade, :valor_unitario, :desconto_item, :subtotal,
                :desconto, :acrescimo, :total_liquido, :estoque_movimentacao_id
            )"
        );

        $stmt->execute([
            ':venda_id' => $vendaId,
            ':produto_id' => $this->nullableInt($dados['produto_id'] ?? null),
            ':tecnico_item_id' => $this->nullableInt($dados['tecnico_item_id'] ?? null),
            ':orcamento_item_id' => $this->nullableInt($dados['orcamento_item_id'] ?? null),
            ':origem_tipo' => $this->nullableString($dados['origem_tipo'] ?? null),
            ':origem_id' => $this->nullableString($dados['origem_id'] ?? null),
            ':codigo' => trim((string)($dados['codigo'] ?? '')),
            ':descricao' => trim((string)($dados['descricao'] ?? '')),
            ':marca' => trim((string)($dados['marca'] ?? '')),
            ':ncm' => trim((string)($dados['ncm'] ?? '')),
            ':quantidade' => $quantidade,
            ':valor_unitario' => $valorUnitario,
            ':desconto_item' => $desconto,
            ':subtotal' => $subtotal,
            ':desconto' => $desconto,
            ':acrescimo' => $acrescimo,
            ':total_liquido' => $totalLiquido,
            ':estoque_movimentacao_id' => $this->nullableInt($dados['estoque_movimentacao_id'] ?? null),
        ]);

        return (int)$this->pdo()->lastInsertId();
    }

    public function removerItemDaVenda(int $vendaId, int $itemId): void
    {
        $stmt = $this->pdo()->prepare(
            'DELETE FROM vendas_itens WHERE venda_id = :venda_id AND id = :id LIMIT 1'
        );
        $stmt->execute([
            ':venda_id' => $vendaId,
            ':id' => $itemId,
        ]);
    }

    /**
     * @return array{total_bruto:float,total_desconto:float,total_acrescimo:float,total_liquido:float}
     */
    public function calcularTotaisDaVenda(int $vendaId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT
                COALESCE(SUM(subtotal), 0) AS total_bruto,
                COALESCE(SUM(COALESCE(desconto, desconto_item, 0)), 0) AS total_desconto,
                COALESCE(SUM(COALESCE(acrescimo, 0)), 0) AS total_acrescimo,
                COALESCE(SUM(COALESCE(total_liquido, subtotal)), 0) AS total_liquido
             FROM vendas_itens
             WHERE venda_id = ?"
        );
        $stmt->execute([$vendaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [
                'total_bruto' => 0.0,
                'total_desconto' => 0.0,
                'total_acrescimo' => 0.0,
                'total_liquido' => 0.0,
            ];
        }

        return [
            'total_bruto' => (float)$row['total_bruto'],
            'total_desconto' => (float)$row['total_desconto'],
            'total_acrescimo' => (float)$row['total_acrescimo'],
            'total_liquido' => (float)$row['total_liquido'],
        ];
    }

    /**
     * @param array{total_bruto:float,total_desconto:float,total_acrescimo:float,total_liquido:float} $totais
     */
    public function atualizarTotais(int $vendaId, array $totais, ?int $updatedBy = null): void
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE vendas_balcao
             SET subtotal = :subtotal,
                 desconto = :desconto,
                 total = :total,
                 total_bruto = :total_bruto,
                 total_desconto = :total_desconto,
                 total_acrescimo = :total_acrescimo,
                 total_liquido = :total_liquido,
                 updated_by = :updated_by
             WHERE id = :id
             LIMIT 1"
        );

        $stmt->execute([
            ':id' => $vendaId,
            ':subtotal' => $totais['total_bruto'],
            ':desconto' => $totais['total_desconto'],
            ':total' => $totais['total_liquido'],
            ':total_bruto' => $totais['total_bruto'],
            ':total_desconto' => $totais['total_desconto'],
            ':total_acrescimo' => $totais['total_acrescimo'],
            ':total_liquido' => $totais['total_liquido'],
            ':updated_by' => $updatedBy,
        ]);
    }

    public function alterarStatusVenda(int $vendaId, string $statusVenda, ?int $updatedBy = null): void
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE vendas_balcao
             SET status = :status_legacy,
                 status_venda = :status_venda,
                 updated_by = :updated_by
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $vendaId,
            ':status_legacy' => $statusVenda,
            ':status_venda' => $statusVenda,
            ':updated_by' => $updatedBy,
        ]);
    }

    public function alterarStatusFiscal(int $vendaId, string $statusFiscal, ?int $updatedBy = null): void
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE vendas_balcao
             SET status_fiscal = :status_fiscal,
                 updated_by = :updated_by
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $vendaId,
            ':status_fiscal' => $statusFiscal,
            ':updated_by' => $updatedBy,
        ]);
    }

    public function atualizarFiscalManualVenda(
        int $vendaId,
        string $statusFiscal,
        string $nfeStatus,
        ?string $chaveAcesso,
        ?string $numero,
        ?string $serie,
        ?int $updatedBy = null
    ): void {
        $stmt = $this->pdo()->prepare(
            "UPDATE vendas_balcao
             SET status_fiscal = :status_fiscal,
                 nfe_status = :nfe_status,
                 nfe_chave = :nfe_chave,
                 nfe_numero = :nfe_numero,
                 nfe_serie = COALESCE(:nfe_serie, nfe_serie),
                 updated_by = :updated_by
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $vendaId,
            ':status_fiscal' => $statusFiscal,
            ':nfe_status' => $nfeStatus,
            ':nfe_chave' => $chaveAcesso,
            ':nfe_numero' => $numero,
            ':nfe_serie' => $serie,
            ':updated_by' => $updatedBy,
        ]);
    }

    public function limparFiscalManualVenda(int $vendaId, ?int $updatedBy = null): void
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE vendas_balcao
             SET status_fiscal = :status_fiscal,
                 nfe_status = :nfe_status,
                 nfe_chave = NULL,
                 nfe_numero = NULL,
                 nfe_serie = '001',
                 nfe_pdf_url = NULL,
                 nfe_xml = NULL,
                 updated_by = :updated_by
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $vendaId,
            ':status_fiscal' => PdvFiscalStatus::NAO_APLICAVEL,
            ':nfe_status' => PdvFiscalStatus::NAO_APLICAVEL,
            ':updated_by' => $updatedBy,
        ]);
    }

    public function cancelarRascunho(int $vendaId, string $motivo, ?int $updatedBy = null): void
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE vendas_balcao
             SET status = :status_legacy,
                 status_venda = :status_venda,
                 cancelled_at = NOW(),
                 cancel_reason = :cancel_reason,
                 updated_by = :updated_by
             WHERE id = :id
             LIMIT 1"
        );

        $stmt->execute([
            ':id' => $vendaId,
            ':status_legacy' => PdvSaleStatus::CANCELADO,
            ':status_venda' => PdvSaleStatus::CANCELADO,
            ':cancel_reason' => $motivo,
            ':updated_by' => $updatedBy,
        ]);
    }

    public function contarMovimentacoesEstoquePorVenda(int $vendaId): int
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM estoque_movimentacoes WHERE venda_id = ?'
        );
        $stmt->execute([$vendaId]);
        return (int)$stmt->fetchColumn();
    }

    public function contarMovimentacoesEstoquePorItens(int $vendaId): int
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM vendas_itens WHERE venda_id = ? AND estoque_movimentacao_id IS NOT NULL'
        );
        $stmt->execute([$vendaId]);
        return (int)$stmt->fetchColumn();
    }

    public function possuiLancamentoReceberVinculado(int $vendaId): bool
    {
        $stmt = $this->pdo()->prepare(
            'SELECT lancamento_receber_id FROM vendas_balcao WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$vendaId]);
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null && (int)$value > 0;
    }

    public function contarDocumentosFiscaisPorVenda(int $vendaId): int
    {
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*)
               FROM venda_documentos
              WHERE venda_id = ?
                AND categoria = 'fiscal'"
        );
        $stmt->execute([$vendaId]);
        return (int)$stmt->fetchColumn();
    }

    public function atualizarSaldoProduto(int $produtoId, float $saldoPos): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE produtos SET estoque_qty = :saldo_pos WHERE id = :id LIMIT 1'
        );
        $stmt->execute([
            ':id' => $produtoId,
            ':saldo_pos' => round($saldoPos, 3),
        ]);
    }

    public function registrarSaidaEstoquePdv(
        int $vendaId,
        int $vendaItemId,
        int $produtoId,
        float $qtd,
        float $saldoAnt,
        float $saldoPos,
        int $usuarioId,
        string $descricao
    ): int {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO estoque_movimentacoes (
                produto_id, os_id, tipo, qtd, saldo_ant, saldo_pos, descricao,
                usuario_id, criado_em, venda_id, venda_item_id, origem_tipo, origem_id
            ) VALUES (
                :produto_id, NULL, 'saida', :qtd, :saldo_ant, :saldo_pos, :descricao,
                :usuario_id, NOW(), :venda_id, :venda_item_id, :origem_tipo, :origem_id
            )"
        );
        $stmt->execute([
            ':produto_id' => $produtoId,
            ':qtd' => round($qtd, 3),
            ':saldo_ant' => round($saldoAnt, 3),
            ':saldo_pos' => round($saldoPos, 3),
            ':descricao' => $descricao,
            ':usuario_id' => $usuarioId,
            ':venda_id' => $vendaId,
            ':venda_item_id' => $vendaItemId,
            ':origem_tipo' => 'pdv_venda',
            ':origem_id' => (string)$vendaId,
        ]);

        return (int)$this->pdo()->lastInsertId();
    }

    public function registrarEntradaEstoquePdvEstorno(
        int $vendaId,
        int $vendaItemId,
        int $produtoId,
        float $qtd,
        float $saldoAnt,
        float $saldoPos,
        int $usuarioId,
        string $descricao
    ): int {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO estoque_movimentacoes (
                produto_id, os_id, tipo, qtd, saldo_ant, saldo_pos, descricao,
                usuario_id, criado_em, venda_id, venda_item_id, origem_tipo, origem_id
            ) VALUES (
                :produto_id, NULL, 'entrada', :qtd, :saldo_ant, :saldo_pos, :descricao,
                :usuario_id, NOW(), :venda_id, :venda_item_id, :origem_tipo, :origem_id
            )"
        );
        $stmt->execute([
            ':produto_id' => $produtoId,
            ':qtd' => round($qtd, 3),
            ':saldo_ant' => round($saldoAnt, 3),
            ':saldo_pos' => round($saldoPos, 3),
            ':descricao' => $descricao,
            ':usuario_id' => $usuarioId,
            ':venda_id' => $vendaId,
            ':venda_item_id' => $vendaItemId,
            ':origem_tipo' => 'pdv_estorno',
            ':origem_id' => (string)$vendaId,
        ]);

        return (int)$this->pdo()->lastInsertId();
    }

    public function vincularEstoqueMovimentacaoNoItem(int $itemId, int $movimentacaoId): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE vendas_itens SET estoque_movimentacao_id = :movimentacao_id WHERE id = :id LIMIT 1'
        );
        $stmt->execute([
            ':id' => $itemId,
            ':movimentacao_id' => $movimentacaoId,
        ]);
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function criarLancamentoReceberPdv(array $dados): int
    {
        $dbCurrentDateFields = is_array($dados['db_current_date_fields'] ?? null)
            ? $dados['db_current_date_fields']
            : [];
        $valueVencimento = in_array('vencimento', $dbCurrentDateFields, true) ? 'CURDATE()' : ':vencimento';
        $valueDataPagamento = in_array('data_pagamento', $dbCurrentDateFields, true) ? 'CURDATE()' : ':data_pagamento';

        $stmt = $this->pdo()->prepare(
            "INSERT INTO lancamentos_receber (
                os_id, equip_idx, orcamento_id, cliente_id, valor, valor_pago,
                desconto_valor, vencimento, data_pagamento, status, forma_pagamento,
                descricao, criado_em
            ) VALUES (
                NULL, NULL, NULL, :cliente_id, :valor, :valor_pago,
                :desconto_valor, {$valueVencimento}, {$valueDataPagamento}, :status, :forma_pagamento,
                :descricao, NOW()
            )"
        );

        $params = [
            ':cliente_id' => $this->nullableInt($dados['cliente_id'] ?? null),
            ':valor' => round((float)($dados['valor'] ?? 0), 2),
            ':valor_pago' => round((float)($dados['valor_pago'] ?? 0), 2),
            ':desconto_valor' => round((float)($dados['desconto_valor'] ?? 0), 2),
            ':status' => trim((string)($dados['status'] ?? 'aberto')),
            ':forma_pagamento' => $this->nullableString($dados['forma_pagamento'] ?? null),
            ':descricao' => trim((string)($dados['descricao'] ?? '')),
        ];

        if (!in_array('vencimento', $dbCurrentDateFields, true)) {
            $params[':vencimento'] = $dados['vencimento'] ?? null;
        }
        if (!in_array('data_pagamento', $dbCurrentDateFields, true)) {
            $params[':data_pagamento'] = $dados['data_pagamento'] ?? null;
        }

        $stmt->execute($params);

        return (int)$this->pdo()->lastInsertId();
    }

    public function vincularLancamentoReceberNaVenda(int $vendaId, int $lancamentoReceberId, ?int $updatedBy = null): void
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE vendas_balcao
             SET lancamento_receber_id = :lancamento_receber_id,
                 updated_by = :updated_by
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $vendaId,
            ':lancamento_receber_id' => $lancamentoReceberId,
            ':updated_by' => $updatedBy,
        ]);
    }

    public function atualizarFormaPagamentoLegada(int $vendaId, ?string $formaPagamento, ?int $updatedBy = null): void
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE vendas_balcao
             SET forma_pagamento = :forma_pagamento,
                 updated_by = :updated_by
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $vendaId,
            ':forma_pagamento' => $this->nullableString($formaPagamento) ?? 'dinheiro',
            ':updated_by' => $updatedBy,
        ]);
    }

    public function cancelarLancamentoReceber(int $lancamentoReceberId): void
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE lancamentos_receber
             SET status = 'cancelado',
                 valor_pago = 0,
                 data_pagamento = NULL
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $lancamentoReceberId,
        ]);
    }

    public function marcarVendaFinalizada(int $vendaId, ?int $updatedBy = null): void
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE vendas_balcao
             SET status = :status_legacy,
                 status_venda = :status_venda,
                 updated_by = :updated_by
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $vendaId,
            ':status_legacy' => PdvSaleStatus::FINALIZADO,
            ':status_venda' => PdvSaleStatus::FINALIZADO,
            ':updated_by' => $updatedBy,
        ]);
    }

    public function marcarVendaEstornada(int $vendaId, string $motivo, ?int $updatedBy = null): void
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE vendas_balcao
             SET status = :status_legacy,
                 status_venda = :status_venda,
                 cancelled_at = NOW(),
                 cancel_reason = :cancel_reason,
                 updated_by = :updated_by
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $vendaId,
            ':status_legacy' => PdvSaleStatus::ESTORNADO,
            ':status_venda' => PdvSaleStatus::ESTORNADO,
            ':cancel_reason' => $motivo,
            ':updated_by' => $updatedBy,
        ]);
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{0:string,1:string,2:array<string,mixed>}
     */
    private function buildSalesListQueryParts(array $filtros, bool $withItemsJoin): array
    {
        $joins = "
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN usuarios u ON u.id = COALESCE(v.operador_id, v.created_by)
            LEFT JOIN lancamentos_receber lr ON lr.id = v.lancamento_receber_id
        ";

        if ($withItemsJoin) {
            $joins .= "
                LEFT JOIN (
                    SELECT
                        venda_id,
                        COUNT(*) AS itens_count,
                        SUM(CASE WHEN estoque_movimentacao_id IS NOT NULL THEN 1 ELSE 0 END) AS itens_com_estoque
                    FROM vendas_itens
                    GROUP BY venda_id
                ) vi ON vi.venda_id = v.id
            ";
        }

        if ($withItemsJoin) {
            $joins .= "
                LEFT JOIN (
                    SELECT
                        venda_id,
                        COUNT(*) AS documentos_fiscais_count,
                        MAX(tipo_documento) AS fiscal_tipo_documento,
                        MAX(modelo) AS fiscal_modelo,
                        MAX(numero) AS fiscal_numero,
                        MAX(serie) AS fiscal_serie
                    FROM venda_documentos
                    WHERE categoria = 'fiscal'
                      AND status NOT IN ('cancelado', 'inativo', 'removido')
                    GROUP BY venda_id
                ) vd ON vd.venda_id = v.id
            ";
        }

        $where = [];
        $params = [];

        $dateFrom = trim((string)($filtros['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'v.created_at >= :date_from';
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = trim((string)($filtros['date_to'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'v.created_at <= :date_to';
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        $statusVenda = trim((string)($filtros['status_venda'] ?? ''));
        if ($statusVenda !== '') {
            $where[] = 'v.status_venda = :status_venda';
            $params[':status_venda'] = $statusVenda;
        }

        $formaPagamento = trim((string)($filtros['forma_pagamento'] ?? ''));
        if ($formaPagamento !== '') {
            $where[] = 'v.forma_pagamento = :forma_pagamento';
            $params[':forma_pagamento'] = $formaPagamento;
        }

        $operadorId = (int)($filtros['operador_id'] ?? 0);
        if ($operadorId > 0) {
            $where[] = 'COALESCE(v.operador_id, v.created_by) = :operador_id';
            $params[':operador_id'] = $operadorId;
        }

        $termo = trim((string)($filtros['q'] ?? ''));
        if ($termo !== '') {
            if (ctype_digit($termo)) {
                $where[] = '(v.id = :termo_id OR v.numero LIKE :termo_like OR c.nome LIKE :termo_like OR v.observacoes LIKE :termo_like)';
                $params[':termo_id'] = (int)$termo;
            } else {
                $where[] = '(v.numero LIKE :termo_like OR c.nome LIKE :termo_like OR v.observacoes LIKE :termo_like)';
            }
            $params[':termo_like'] = '%' . $termo . '%';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        return [$joins, $whereSql, $params];
    }

    private function proximoNumero(): string
    {
        $stmt = $this->pdo()->query(
            'SELECT numero FROM vendas_balcao ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $ultimo = $stmt->fetchColumn();
        if ($ultimo === false || $ultimo === null || $ultimo === '') {
            return '000001';
        }

        $digits = preg_replace('/\D/', '', (string)$ultimo) ?: '0';
        $next = (int)$digits + 1;
        return str_pad((string)$next, 6, '0', STR_PAD_LEFT);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int)$value;
        return $int > 0 ? $int : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string)$value);
        return $trimmed === '' ? null : $trimmed;
    }
}
