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
    public function concluirOS(int $osId, int $operadorId): array
    {
        if (!FiscalGuard::canRunWorker($this->pdo)) {
            FiscalGuard::auditBlock($this->pdo, 'erp-nfse_os_concluir', null, $operadorId);
            throw new DomainException(FiscalGuard::blockMessage($this->pdo, 'erp-nfse_os_concluir'));
        }

        $this->pdo->beginTransaction();
        try {
            // 1. Lê a OS (com lock de leitura para evitar dupla conclusão)
            $st = $this->pdo->prepare(
                'SELECT id, cliente_id, total, status
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
            if ($os['status'] === 'concluida') {
                throw new DomainException("OS #{$osId} já foi concluída anteriormente.");
            }

            // 2. Atualiza status da OS
            $this->pdo->prepare(
                "UPDATE ordem_servico
                 SET status = 'concluida', data_conclusao = NOW(), operador_id = ?
                 WHERE id = ?
                 LIMIT 1"
            )->execute([$operadorId, $osId]);

            // 3. Cria lançamento a receber (vencimento padrão: D+1)
            $descricao = "OS #{$osId} — " . ($os['nome_cliente'] ?? 'Cliente');
            $this->pdo->prepare(
                "INSERT INTO lancamentos_receber
                 (os_id, cliente_id, valor, vencimento, status, descricao, criado_em)
                 VALUES (?, ?, ?, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'aberto', ?, NOW())"
            )->execute([$osId, $os['cliente_id'], $os['total'], $descricao]);

            $lancamentoId = (int)$this->pdo->lastInsertId();

            // 4. Registra a nota como pendente (referência para o worker)
            $this->pdo->prepare(
                "INSERT INTO notas_fiscais
                 (os_id, lancamento_id, status, criado_em)
                 VALUES (?, ?, 'pendente', NOW())"
            )->execute([$osId, $lancamentoId]);

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
    public function reabrirOS(int $osId): void
    {
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
}
