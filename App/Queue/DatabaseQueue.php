<?php
declare(strict_types=1);

namespace App\Queue;

use PDO;
use Throwable;

/**
 * Fila de jobs apoiada em MySQL com SELECT ... FOR UPDATE SKIP LOCKED.
 * Permite múltiplos workers concorrentes sem race condition.
 */
final class DatabaseQueue
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Enfileira um novo job. Retorna o ID inserido.
     */
    public function enqueue(string $tipo, array $payload, string $queue = 'default'): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO jobs (queue, tipo, payload) VALUES (?, ?, ?)'
        );
        $st->execute([
            $queue,
            $tipo,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Reivindica o próximo job atomicamente. Retorna null se a fila está vazia.
     */
    public function claim(string $queue = 'default'): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                "SELECT id, tipo, payload, tentativas
                 FROM jobs
                 WHERE queue = ?
                   AND status = 'pending'
                   AND tentativas < max_tentativas
                   AND disponivel_em <= NOW()
                   AND NOT EXISTS (
                       SELECT 1
                         FROM job_fiscal_arquivamentos arq
                        WHERE arq.job_id = jobs.id
                        LIMIT 1
                   )
                 ORDER BY id ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED"
            );
            $st->execute([$queue]);
            $job = $st->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                $this->pdo->rollBack();
                return null;
            }

            $this->pdo->prepare(
                "UPDATE jobs SET status = 'processing', tentativas = tentativas + 1 WHERE id = ? LIMIT 1"
            )->execute([$job['id']]);

            $this->pdo->commit();

            $job['payload'] = json_decode((string)$job['payload'], true, 512, JSON_THROW_ON_ERROR);
            return $job;

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function done(int $jobId): void
    {
        $this->pdo->prepare(
            "UPDATE jobs SET status = 'done', processado_em = NOW() WHERE id = ? LIMIT 1"
        )->execute([$jobId]);
    }

    public function releaseBlocked(int $jobId, string $motivo, int $delayMinutes = 15): void
    {
        $this->pdo->prepare(
            "UPDATE jobs
             SET status = 'pending',
                 tentativas = CASE WHEN tentativas > 0 THEN tentativas - 1 ELSE 0 END,
                 erro = ?,
                 processado_em = NOW(),
                 disponivel_em = DATE_ADD(NOW(), INTERVAL ? MINUTE)
             WHERE id = ? LIMIT 1"
        )->execute([mb_substr($motivo, 0, 65535), max(1, $delayMinutes), $jobId]);
    }

    /**
     * Marca falha. Se ainda tem tentativas e $retry, volta para 'pending' com back-off (5, 20, 45 min).
     */
    public function failed(int $jobId, string $erro, bool $retry = true): void
    {
        $this->pdo->prepare(
            "UPDATE jobs
             SET status = CASE
                   WHEN tentativas < max_tentativas AND ? = 1 THEN 'pending'
                   ELSE 'failed'
                 END,
                 erro          = ?,
                 processado_em = NOW(),
                 disponivel_em = DATE_ADD(NOW(), INTERVAL (tentativas * tentativas * 5) MINUTE)
             WHERE id = ? LIMIT 1"
        )->execute([$retry ? 1 : 0, mb_substr($erro, 0, 65535), $jobId]);
    }

    /**
     * Contagem de jobs por status. Para dashboards.
     */
    public function stats(string $queue = 'default'): array
    {
        $st = $this->pdo->prepare(
            "SELECT status, COUNT(*) AS total
             FROM jobs
             WHERE queue = ?
             GROUP BY status"
        );
        $st->execute([$queue]);
        $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_merge(['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0], $rows);
    }
}
