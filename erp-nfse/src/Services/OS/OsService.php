<?php
declare(strict_types=1);
namespace App\Services\OS;

use App\Queue\DatabaseQueue;
use App\Services\Fiscal\FiscalGuard;
use DomainException;
use PDO;
use Throwable;

final class OsService
{
    public function __construct(
        private readonly PDO           $pdo,
        private readonly DatabaseQueue $queue
    ) {}

    /**
     * Conclui uma OS, cria o lançamento financeiro e enfileira a emissão da NFS-e.
     *
     * TUDO dentro de uma única transação, exceto o enqueue — que é intencional:
     * se o enqueue falhar, o financeiro já foi salvo e pode ser reconciliado pelo cron.
     */
    public function concluirOS(string $osId, int $operadorId): array
    {
        $osId = trim($osId);
        if ($osId === '') {
            throw new DomainException('OS inválida para conclusão.');
        }

        if (!FiscalGuard::canRunWorker($this->pdo)) {
            FiscalGuard::auditBlock($this->pdo, 'erp-nfse_os_concluir', null, $operadorId);
            throw new DomainException(FiscalGuard::blockMessage($this->pdo, 'erp-nfse_os_concluir'));
        }

        $this->pdo->beginTransaction();
        try {
            // 1. Lê a OS (com lock de leitura para evitar dupla conclusão)
            $st = $this->pdo->prepare(
                'SELECT id, cliente_id, nome_cliente, status, data_conclusao
                 FROM ordem_servico
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $st->execute([$osId]);
            $os = $st->fetch(PDO::FETCH_ASSOC);

            if (!$os) {
                throw new DomainException("OS #{$osId} não encontrada.");
            }
            if ($os['status'] === 'retirado') {
                throw new DomainException("OS #{$osId} já foi entregue ao cliente.");
            }
            if ($os['data_conclusao'] !== null) {
                throw new DomainException("OS #{$osId} já foi concluída administrativamente.");
            }

            $total = $this->somarOrcamentosAprovados($osId);
            if ($total <= 0.0) {
                throw new DomainException("OS #{$osId} não possui orçamento aprovado com valor para faturar.");
            }

            // 2. Atualiza status da OS
            $this->pdo->prepare(
                "UPDATE ordem_servico
                 SET status = 'pronto', data_conclusao = NOW(), operador_id = ?
                 WHERE id = ?
                 LIMIT 1"
            )->execute([$operadorId, $osId]);

            // 3. Cria lançamento a receber (vencimento padrão: D+1)
            $descricao = "OS #{$osId} — " . ($os['nome_cliente'] ?? 'Cliente');
            $this->pdo->prepare(
                "INSERT INTO lancamentos_receber
                 (os_id, cliente_id, valor, vencimento, status, descricao, criado_em)
                 VALUES (?, ?, ?, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'aberto', ?, NOW())"
            )->execute([$osId, $os['cliente_id'], $total, $descricao]);

            $lancamentoId = (int)$this->pdo->lastInsertId();

            // 4. Registra a nota como pendente (referência para o worker)
            $this->pdo->prepare(
                "INSERT INTO notas_fiscais
                 (os_id, lancamento_id, cliente_id, tipo_documento, ambiente, status,
                  valor_total, descricao_servico, serie_dps, competencia, created_by, updated_by, criado_em, atualizado_em)
                 VALUES (?, ?, ?, 'nfse', ?, 'pendente', ?, ?, ?, CURDATE(), ?, ?, NOW(), NOW())"
            )->execute([
                $osId,
                $lancamentoId,
                $os['cliente_id'],
                getenv('NFSE_AMBIENTE') ?: 'homologacao',
                $total,
                $descricao,
                getenv('NFSE_SERIE_DPS') ?: '1',
                $operadorId,
                $operadorId,
            ]);

            $notaId = (int)$this->pdo->lastInsertId();

            // COMMIT — financeiro garantido ANTES de qualquer chamada externa
            $this->pdo->commit();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // 5. Enfileira emissão fora da transação (falha aqui não reverte o financeiro)
        $this->queue->enqueue('emitir_nfse', [
            'nota_id'      => $notaId,
            'os_id'        => $osId,
            'operador_id'  => $operadorId,
        ]);

        return [
            'ok'           => true,
            'nota_id'      => $notaId,
            'lancamento_id'=> $lancamentoId,
        ];
    }

    /**
     * Reabre uma OS que foi concluída indevidamente (só admin).
     * Estorna o lançamento financeiro associado.
     */
    public function reabrirOS(string $osId): void
    {
        $osId = trim($osId);
        if ($osId === '') {
            throw new DomainException('OS inválida para reabertura.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE ordem_servico SET status = 'andamento', data_conclusao = NULL WHERE id = ? LIMIT 1"
            )->execute([$osId]);

            $this->pdo->prepare(
                "UPDATE lancamentos_receber SET status = 'cancelado' WHERE os_id = ? LIMIT 1"
            )->execute([$osId]);

            $this->pdo->prepare(
                "UPDATE notas_fiscais SET status = 'cancelada' WHERE os_id = ? LIMIT 1"
            )->execute([$osId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function somarOrcamentosAprovados(string $osId): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(o.total), 0)
               FROM orcamentos o
               JOIN os_equipamento eq
                 ON eq.os_id     = o.os_id
                AND eq.ordem_idx = o.equip_idx
              WHERE o.os_id  = ?
                AND o.status = 'aprovado'
                AND eq.status_equip NOT IN ('retirado','devolvido','descartado')"
        );
        $stmt->execute([$osId]);
        return (float)$stmt->fetchColumn();
    }
}
