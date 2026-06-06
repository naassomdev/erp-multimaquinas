<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class VendaPagamentoRepository
{
    public function __construct(private readonly ?PDO $pdo = null) {}

    private function pdo(): PDO
    {
        return $this->pdo ?? Database::pdo();
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function inserir(int $vendaId, array $dados): int
    {
        $dbNowFields = is_array($dados['db_now_fields'] ?? null) ? $dados['db_now_fields'] : [];
        $valuePagoEm = in_array('pago_em', $dbNowFields, true) ? 'NOW()' : ':pago_em';
        $valueCancelledAt = in_array('cancelled_at', $dbNowFields, true) ? 'NOW()' : ':cancelled_at';

        $stmt = $this->pdo()->prepare(
            "INSERT INTO venda_pagamentos (
                venda_id, forma_pagamento, status, valor, parcelas,
                referencia_externa, payload_json, pago_em, cancelled_at,
                created_by, updated_by
            ) VALUES (
                :venda_id, :forma_pagamento, :status, :valor, :parcelas,
                :referencia_externa, :payload_json, {$valuePagoEm}, {$valueCancelledAt},
                :created_by, :updated_by
            )"
        );

        $payload = $dados['payload_json'] ?? null;
        $params = [
            ':venda_id' => $vendaId,
            ':forma_pagamento' => trim((string)($dados['forma_pagamento'] ?? '')),
            ':status' => trim((string)($dados['status'] ?? 'pendente')),
            ':valor' => (float)($dados['valor'] ?? 0),
            ':parcelas' => $this->nullableInt($dados['parcelas'] ?? null),
            ':referencia_externa' => $this->nullableString($dados['referencia_externa'] ?? null),
            ':payload_json' => is_array($payload)
                ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $payload,
            ':created_by' => $this->nullableInt($dados['created_by'] ?? null),
            ':updated_by' => $this->nullableInt($dados['updated_by'] ?? null),
        ];

        if (!in_array('pago_em', $dbNowFields, true)) {
            $params[':pago_em'] = $this->nullableString($dados['pago_em'] ?? null);
        }
        if (!in_array('cancelled_at', $dbNowFields, true)) {
            $params[':cancelled_at'] = $this->nullableString($dados['cancelled_at'] ?? null);
        }

        $stmt->execute($params);

        return (int)$this->pdo()->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listarPorVenda(int $vendaId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM venda_pagamentos WHERE venda_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$vendaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listarPorVendaForUpdate(int $vendaId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM venda_pagamentos WHERE venda_id = ? ORDER BY id ASC FOR UPDATE'
        );
        $stmt->execute([$vendaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function somarPorVenda(int $vendaId): float
    {
        $stmt = $this->pdo()->prepare(
            "SELECT COALESCE(SUM(valor), 0)
             FROM venda_pagamentos
             WHERE venda_id = ?
               AND status = 'pago'"
        );
        $stmt->execute([$vendaId]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * @return array{total_pago_confirmado:float,qtd_ativos_nao_cancelados:int,qtd_pendentes:int,qtd_pagos:int}
     */
    public function resumirStatusPorVenda(int $vendaId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END), 0) AS total_pago_confirmado,
                COALESCE(SUM(CASE WHEN status <> 'cancelado' THEN 1 ELSE 0 END), 0) AS qtd_ativos_nao_cancelados,
                COALESCE(SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END), 0) AS qtd_pendentes,
                COALESCE(SUM(CASE WHEN status = 'pago' THEN 1 ELSE 0 END), 0) AS qtd_pagos
             FROM venda_pagamentos
             WHERE venda_id = ?"
        );
        $stmt->execute([$vendaId]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total_pago_confirmado' => (float)($row['total_pago_confirmado'] ?? 0),
            'qtd_ativos_nao_cancelados' => (int)($row['qtd_ativos_nao_cancelados'] ?? 0),
            'qtd_pendentes' => (int)($row['qtd_pendentes'] ?? 0),
            'qtd_pagos' => (int)($row['qtd_pagos'] ?? 0),
        ];
    }

    public function contarPorVenda(int $vendaId): int
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM venda_pagamentos WHERE venda_id = ?'
        );
        $stmt->execute([$vendaId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $extras
     * @param string[] $dbNowFields
     */
    public function alterarStatus(int $id, string $status, array $extras = [], array $dbNowFields = []): void
    {
        $sets = ['status = :status'];
        $params = [
            ':id' => $id,
            ':status' => $status,
        ];

        $map = [
            'pago_em' => 'pago_em',
            'cancelled_at' => 'cancelled_at',
            'updated_by' => 'updated_by',
            'referencia_externa' => 'referencia_externa',
        ];

        foreach ($map as $input => $column) {
            if (in_array($input, $dbNowFields, true)) {
                $sets[] = "{$column} = NOW()";
                continue;
            }
            if (!array_key_exists($input, $extras)) {
                continue;
            }
            $sets[] = "{$column} = :{$input}";
            $params[":{$input}"] = $extras[$input];
        }

        $sql = 'UPDATE venda_pagamentos SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int)$value;
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
