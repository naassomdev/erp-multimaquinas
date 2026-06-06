<?php
declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Core\Database;
use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Faturamento B2B: agrupa OS retiradas com pagamento "faturado" em um relatório
 * de cobrança vinculado a uma PO/pedido do cliente. Quando o relatório é
 * finalizado, os lançamentos a receber são quitados.
 *
 * Tabelas: relatorios_faturamento + relatorio_faturamento_os.
 */
final class FaturamentoService
{
    public function __construct(private readonly ?PDO $pdo = null) {}

    private function pdo(): PDO
    {
        return $this->pdo ?? Database::pdo();
    }

    /**
     * Lista as OS de um cliente B2B aguardando fatura, com totais.
     * Inclui número do pedido (PO informado na retirada) para agrupamento.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarPendentesPorCliente(int $clienteId): array
    {
        $sql = "SELECT
                    os.id          AS os_id,
                    os.nome_cliente,
                    os.data_retirada,
                    os.numero_pedido,
                    lr.id          AS lancamento_id,
                    lr.valor,
                    lr.descricao
                  FROM ordem_servico   os
            INNER JOIN lancamentos_receber lr
                    ON lr.os_id = os.id
                 WHERE os.cliente_id = :cli
                   AND lr.status = 'aguardando_fatura'
              ORDER BY os.data_retirada ASC";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([':cli' => $clienteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cria um novo relatório de faturamento agrupando uma lista de OS.
     * Status inicial: 'rascunho'. Use finalizar() para quitar.
     *
     * @param  array<int, string> $osIds
     * @return int ID do relatório criado
     */
    public function gerarRelatorio(
        int    $clienteId,
        string $numeroPo,
        int    $criadoPor,
        array  $osIds,
        string $observacoes = '',
    ): int {
        $numeroPo = trim($numeroPo);
        if ($numeroPo === '') {
            throw new InvalidArgumentException('Número do pedido (PO) obrigatório');
        }
        $osIds = array_values(array_filter(array_map('strval', $osIds), fn(string $id) => $id !== ''));
        if (empty($osIds)) {
            throw new InvalidArgumentException('Selecione ao menos uma OS para o relatório');
        }

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            // Carrega o nome do cliente (cliente_nome é coluna obrigatória do relatório)
            $stCli = $pdo->prepare('SELECT nome FROM clientes WHERE id = ? LIMIT 1');
            $stCli->execute([$clienteId]);
            $clienteNome = (string) $stCli->fetchColumn();
            if ($clienteNome === '') {
                throw new DomainException("Cliente #{$clienteId} não encontrado");
            }

            // Valida que todas as OS pertencem ao cliente e estão aguardando fatura
            $placeholders = implode(',', array_fill(0, count($osIds), '?'));
            $stVal = $pdo->prepare(
                "SELECT os.id
                   FROM ordem_servico   os
             INNER JOIN lancamentos_receber lr ON lr.os_id = os.id
                  WHERE os.cliente_id = ?
                    AND os.id IN ({$placeholders})
                    AND lr.status = 'aguardando_fatura'"
            );
            $stVal->execute(array_merge([$clienteId], $osIds));
            $validas = $stVal->fetchAll(PDO::FETCH_COLUMN);
            if (count($validas) !== count($osIds)) {
                throw new DomainException('Algumas OS não estão aguardando fatura ou não pertencem ao cliente');
            }

            // Insere o relatório
            $pdo->prepare(
                "INSERT INTO relatorios_faturamento
                   (numero_po, cliente_id, cliente_nome, criado_por, criado_em, observacoes, status)
                 VALUES (?, ?, ?, ?, NOW(), ?, 'rascunho')"
            )->execute([$numeroPo, $clienteId, $clienteNome, $criadoPor, $observacoes]);
            $relatorioId = (int) $pdo->lastInsertId();

            // Vincula OS
            $stIns = $pdo->prepare(
                'INSERT INTO relatorio_faturamento_os (relatorio_id, os_id) VALUES (?, ?)'
            );
            foreach ($osIds as $osId) {
                $stIns->execute([$relatorioId, $osId]);
            }

            $pdo->commit();
            return $relatorioId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Finaliza o relatório: marca como 'finalizado' e quita os lançamentos
     * (status: aguardando_fatura → pago, valor_pago = valor, data = hoje).
     */
    public function finalizar(int $relatorioId): array
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                "SELECT id, status FROM relatorios_faturamento WHERE id = ? LIMIT 1 FOR UPDATE"
            );
            $st->execute([$relatorioId]);
            $rel = $st->fetch(PDO::FETCH_ASSOC);
            if (!$rel) {
                throw new DomainException("Relatório #{$relatorioId} não encontrado");
            }
            if ($rel['status'] === 'finalizado') {
                throw new DomainException('Relatório já finalizado');
            }

            $stOs = $pdo->prepare(
                'SELECT os_id FROM relatorio_faturamento_os WHERE relatorio_id = ?'
            );
            $stOs->execute([$relatorioId]);
            $osIds = $stOs->fetchAll(PDO::FETCH_COLUMN);
            if (empty($osIds)) {
                throw new DomainException('Relatório sem OS vinculadas');
            }

            $placeholders = implode(',', array_fill(0, count($osIds), '?'));
            $upd = $pdo->prepare(
                "UPDATE lancamentos_receber
                    SET status = 'pago',
                        valor_pago = valor,
                        data_pagamento = CURDATE()
                  WHERE os_id IN ({$placeholders})
                    AND status = 'aguardando_fatura'"
            );
            $upd->execute($osIds);
            $quitados = $upd->rowCount();

            $pdo->prepare(
                "UPDATE relatorios_faturamento SET status = 'finalizado' WHERE id = ? LIMIT 1"
            )->execute([$relatorioId]);

            $pdo->commit();

            return [
                'relatorio_id' => $relatorioId,
                'os_quitadas'  => $quitados,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Lista relatórios (com filtro de status opcional) para a tela admin.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarRelatorios(?string $statusFiltro = null, int $limit = 100): array
    {
        $where = '';
        $params = [];
        if ($statusFiltro !== null && in_array($statusFiltro, ['rascunho', 'finalizado'], true)) {
            $where = 'WHERE r.status = :st';
            $params[':st'] = $statusFiltro;
        }

        $sql = "SELECT r.id, r.numero_po, r.cliente_id, r.cliente_nome,
                       r.criado_por, r.criado_em, r.status, r.observacoes,
                       (SELECT COUNT(*) FROM relatorio_faturamento_os WHERE relatorio_id = r.id) AS qtd_os,
                       (SELECT COALESCE(SUM(lr.valor), 0)
                          FROM relatorio_faturamento_os rfo
                     INNER JOIN lancamentos_receber lr ON lr.os_id = rfo.os_id
                         WHERE rfo.relatorio_id = r.id) AS valor_total
                  FROM relatorios_faturamento r
                 {$where}
              ORDER BY r.criado_em DESC
                 LIMIT :lim";
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca detalhes de um relatório (cabeçalho + OS vinculadas).
     */
    public function detalhar(int $relatorioId): ?array
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM relatorios_faturamento WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$relatorioId]);
        $rel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rel) return null;

        $stOs = $pdo->prepare(
            "SELECT rfo.os_id, os.data_retirada, os.numero_pedido,
                    lr.valor, lr.status AS status_lancamento, lr.descricao
               FROM relatorio_faturamento_os rfo
          LEFT JOIN ordem_servico        os ON os.id = rfo.os_id
          LEFT JOIN lancamentos_receber  lr ON lr.os_id = rfo.os_id
              WHERE rfo.relatorio_id = ?
           ORDER BY os.data_retirada ASC"
        );
        $stOs->execute([$relatorioId]);
        $rel['ordens'] = $stOs->fetchAll(PDO::FETCH_ASSOC);

        return $rel;
    }
}
