<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;
use PDO;
use Throwable;

final class NfseFiscalJobService
{
    public function __construct(
        private readonly AuditoriaService $audit = new AuditoriaService(),
        private readonly ?PDO $pdo = null,
    ) {}

    private function pdo(): PDO
    {
        return $this->pdo ?? Database::pdo();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listarEmitirNfse(): array
    {
        $sql = "SELECT j.id AS job_id,
                       j.queue,
                       j.tipo,
                       j.status AS job_status,
                       j.payload,
                       JSON_UNQUOTE(JSON_EXTRACT(j.payload, '$.nota_id')) AS nota_fiscal_id_payload,
                       JSON_UNQUOTE(JSON_EXTRACT(j.payload, '$.os_id')) AS os_id_payload,
                       nf.id AS nota_existe,
                       nf.os_id AS nota_os_id,
                       COALESCE(nf.cliente_id, lr.cliente_id) AS cliente_id,
                       c.nome AS cliente_nome,
                       COALESCE(nf.valor_total, lr.valor) AS valor,
                       j.tentativas,
                       j.max_tentativas,
                       j.disponivel_em,
                       j.criado_em,
                       j.processado_em,
                       j.erro,
                       arq.id AS arquivamento_id,
                       arq.motivo AS arquivamento_motivo,
                       arq.usuario_id AS arquivado_por,
                       arq.created_at AS arquivado_em
                  FROM jobs j
             LEFT JOIN notas_fiscais nf
                    ON nf.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(j.payload, '$.nota_id')) AS UNSIGNED)
             LEFT JOIN lancamentos_receber lr ON lr.id = nf.lancamento_id
             LEFT JOIN clientes c ON c.id = COALESCE(nf.cliente_id, lr.cliente_id)
             LEFT JOIN job_fiscal_arquivamentos arq ON arq.job_id = j.id
                 WHERE j.tipo = 'emitir_nfse'
              ORDER BY j.id ASC";

        $rows = $this->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row): array => $this->comDiagnostico($row), $rows);
    }

    /**
     * @return array<string,int>
     */
    public function resumo(): array
    {
        $jobs = $this->listarEmitirNfse();
        $resumo = [
            'total' => count($jobs),
            'arquivados' => 0,
            'invalidos' => 0,
            'preservados' => 0,
            'pendentes' => 0,
        ];

        foreach ($jobs as $job) {
            if (!empty($job['arquivado'])) {
                $resumo['arquivados']++;
            }
            if (!empty($job['recomendado_arquivar'])) {
                $resumo['invalidos']++;
            }
            if (!empty($job['preservar'])) {
                $resumo['preservados']++;
            }
            if (($job['job_status'] ?? '') === 'pending') {
                $resumo['pendentes']++;
            }
        }

        return $resumo;
    }

    public function arquivar(int $jobId, string $motivo, int $usuarioId): void
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new DomainException('Informe o motivo do arquivamento.');
        }

        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "SELECT j.*,
                        JSON_UNQUOTE(JSON_EXTRACT(j.payload, '$.nota_id')) AS nota_fiscal_id_payload,
                        JSON_UNQUOTE(JSON_EXTRACT(j.payload, '$.os_id')) AS os_id_payload,
                        nf.id AS nota_existe
                   FROM jobs j
              LEFT JOIN notas_fiscais nf
                     ON nf.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(j.payload, '$.nota_id')) AS UNSIGNED)
                  WHERE j.id = ?
                  LIMIT 1
                  FOR UPDATE"
            );
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                throw new DomainException("Job fiscal #{$jobId} não encontrado.");
            }
            if ((string)$job['tipo'] !== 'emitir_nfse') {
                throw new DomainException('Apenas jobs do tipo emitir_nfse podem ser arquivados nesta tela.');
            }

            $notaId = $this->nullableInt($job['nota_fiscal_id_payload'] ?? null);
            $osId = $this->nullableString($job['os_id_payload'] ?? null);
            $notaExiste = !empty($job['nota_existe']);

            if ($jobId === 25 || $notaExiste) {
                $pdo->rollBack();
                $this->audit->registrar('jobs', (string)$jobId, 'NFSE_JOB_ARQUIVAMENTO_BLOQUEADO', [
                    'job_id' => $jobId,
                    'tipo' => $job['tipo'],
                    'status' => $job['status'],
                    'nota_fiscal_id' => $notaId,
                    'os_id' => $osId,
                    'motivo_informado' => $motivo,
                    'bloqueio' => $jobId === 25
                        ? 'job_25_preservado_por_politica'
                        : 'nota_fiscal_existente',
                    'sem_transmissao_fiscal' => true,
                ]);
                throw new DomainException('Este job possui nota fiscal existente e deve ser preservado. Nenhum arquivamento foi realizado.');
            }

            $exists = $pdo->prepare('SELECT id FROM job_fiscal_arquivamentos WHERE job_id = ? LIMIT 1');
            $exists->execute([$jobId]);
            if ($exists->fetchColumn()) {
                throw new DomainException("Job fiscal #{$jobId} já está arquivado.");
            }

            $payload = $this->decodePayload($job['payload'] ?? null);
            $payloadResumo = [
                'job_id' => $jobId,
                'tipo' => $job['tipo'],
                'status_original' => $job['status'],
                'nota_fiscal_id' => $notaId,
                'os_id' => $osId,
                'payload' => $payload,
            ];

            $insert = $pdo->prepare(
                "INSERT INTO job_fiscal_arquivamentos
                    (job_id, motivo, payload_resumo, nota_fiscal_id, os_id, usuario_id)
                 VALUES
                    (?, ?, ?, ?, ?, ?)"
            );
            $insert->execute([
                $jobId,
                mb_substr($motivo, 0, 500),
                json_encode($payloadResumo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $notaId,
                $osId,
                $usuarioId > 0 ? $usuarioId : null,
            ]);

            $pdo->commit();

            $this->audit->registrar('jobs', (string)$jobId, 'NFSE_JOB_ARQUIVADO', [
                'job_id' => $jobId,
                'tipo' => $job['tipo'],
                'status_anterior' => $job['status'],
                'marcacao_auxiliar' => 'job_fiscal_arquivamentos',
                'nota_fiscal_id' => $notaId,
                'os_id' => $osId,
                'motivo' => $motivo,
                'usuario_id' => $usuarioId,
                'sem_transmissao_fiscal' => true,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function comDiagnostico(array $row): array
    {
        $payload = $this->decodePayload($row['payload'] ?? null);
        $notaId = $this->nullableInt($row['nota_fiscal_id_payload'] ?? ($payload['nota_id'] ?? null));
        $osId = $this->nullableString($row['os_id_payload'] ?? ($payload['os_id'] ?? null));
        $notaExiste = !empty($row['nota_existe']);
        $arquivado = !empty($row['arquivamento_id']);

        $diagnosticos = [];
        if ($notaExiste) {
            $diagnosticos[] = 'nota existente';
            $diagnosticos[] = 'job fiscal real pendente';
            $diagnosticos[] = 'preservar';
        } else {
            $diagnosticos[] = 'nota não encontrada';
            $diagnosticos[] = 'recomendado arquivar';
        }
        $diagnosticos[] = 'bloqueado por flags';
        if ((int)$row['job_id'] === 25) {
            $diagnosticos[] = 'job 25 preservado';
        }
        if ($arquivado) {
            $diagnosticos[] = 'arquivado internamente';
        }

        $row['payload_decoded'] = $payload;
        $row['payload_resumo'] = $this->resumoPayload($payload);
        $row['nota_fiscal_id'] = $notaId;
        $row['os_id'] = $osId;
        $row['nota_existe_bool'] = $notaExiste;
        $row['arquivado'] = $arquivado;
        $row['diagnosticos'] = $diagnosticos;
        $row['recomendado_arquivar'] = !$notaExiste && !$arquivado;
        $row['preservar'] = $notaExiste || (int)$row['job_id'] === 25;
        $row['pode_arquivar'] = !$notaExiste && !$arquivado && (int)$row['job_id'] !== 25;

        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (!is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resumoPayload(array $payload): string
    {
        if ($payload === []) {
            return '{}';
        }

        $safe = [];
        foreach (['nota_id', 'os_id', 'operador_id', 'origem'] as $key) {
            if (array_key_exists($key, $payload)) {
                $safe[$key] = $payload[$key];
            }
        }

        return json_encode($safe ?: $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        $int = (int)$value;
        return $int > 0 ? $int : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $str = trim((string)$value);
        return $str !== '' && strtolower($str) !== 'null' ? $str : null;
    }
}
