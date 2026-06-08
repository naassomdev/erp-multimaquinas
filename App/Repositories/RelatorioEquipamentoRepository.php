<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class RelatorioEquipamentoRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    // ── Saída de Equipamentos ──────────────────────────────────────────────

    public function listarSaidas(array $filtros, int $pagina, int $perPage): array
    {
        [$where, $params] = $this->whereSaidas($filtros);
        $offset = ($pagina - 1) * $perPage;

        $sql = "
            SELECT
                oe.os_id,
                oe.ordem_idx,
                oe.nome AS equip_nome,
                oe.fabricante,
                oe.modelo,
                oe.status_equip,
                oe.diagnostico_concluido_em,
                u.nome AS tecnico_nome,
                os.nome_cliente,
                CASE
                    WHEN LOWER(oe.nome) REGEXP 'motobomba' THEN 'Motobomba'
                    WHEN LOWER(oe.nome) REGEXP 'bomba' THEN 'Bomba'
                    WHEN LOWER(oe.nome) REGEXP 'motor' THEN 'Motor Elétrico'
                    ELSE 'Outros'
                END AS tipo_equip
            FROM os_equipamento oe
            INNER JOIN ordem_servico os ON os.id = oe.os_id
            LEFT JOIN usuarios u ON u.id = oe.diagnostico_concluido_por
            WHERE {$where}
            ORDER BY oe.diagnostico_concluido_em DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function contarSaidas(array $filtros): int
    {
        [$where, $params] = $this->whereSaidas($filtros);
        $sql = "
            SELECT COUNT(*)
            FROM os_equipamento oe
            INNER JOIN ordem_servico os ON os.id = oe.os_id
            LEFT JOIN usuarios u ON u.id = oe.diagnostico_concluido_por
            WHERE {$where}
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function kpisSaidas(array $filtros): array
    {
        [$where, $params] = $this->whereSaidas($filtros);
        $sql = "
            SELECT
                COUNT(*) AS total,
                COUNT(DISTINCT oe.os_id) AS total_os,
                SUM(CASE WHEN LOWER(oe.nome) REGEXP 'motobomba' THEN 1 ELSE 0 END) AS motobombas,
                SUM(CASE WHEN LOWER(oe.nome) REGEXP 'bomba' AND LOWER(oe.nome) NOT REGEXP 'motobomba' THEN 1 ELSE 0 END) AS bombas,
                SUM(CASE WHEN LOWER(oe.nome) REGEXP 'motor' THEN 1 ELSE 0 END) AS motores,
                SUM(CASE WHEN LOWER(oe.nome) NOT REGEXP 'motobomba|bomba|motor' THEN 1 ELSE 0 END) AS outros
            FROM os_equipamento oe
            INNER JOIN ordem_servico os ON os.id = oe.os_id
            LEFT JOIN usuarios u ON u.id = oe.diagnostico_concluido_por
            WHERE {$where}
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function listarTecnicos(): array
    {
        return $this->pdo
            ->query("SELECT id, nome FROM usuarios WHERE nivel_acesso IN ('oficina','admin') ORDER BY nome")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Curva ABC ──────────────────────────────────────────────────────────

    public function abcEstoque(): array
    {
        $sql = "
            SELECT
                codigo,
                descricao,
                CAST(estoque_qty AS DECIMAL(12,3)) AS qty,
                CAST(preco_custo AS DECIMAL(12,2)) AS custo_unit,
                CAST(estoque_qty * preco_custo AS DECIMAL(14,2)) AS valor_total
            FROM produtos
            WHERE controla_estoque = 1
              AND estoque_qty > 0
              AND preco_custo > 0
            ORDER BY valor_total DESC
        ";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function abcTipo(string $de, string $ate): array
    {
        [$where, $params] = $this->wherePeriodoOs($de, $ate);
        $sql = "
            SELECT
                CASE
                    WHEN LOWER(oe.nome) REGEXP 'motobomba' THEN 'Motobomba'
                    WHEN LOWER(oe.nome) REGEXP 'bomba' THEN 'Bomba'
                    WHEN LOWER(oe.nome) REGEXP 'motor' THEN 'Motor Elétrico'
                    ELSE 'Outros'
                END AS label,
                COUNT(*) AS quantidade
            FROM os_equipamento oe
            INNER JOIN ordem_servico os ON os.id = oe.os_id
            WHERE {$where}
            GROUP BY label
            ORDER BY quantidade DESC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function abcMarca(string $de, string $ate): array
    {
        [$where, $params] = $this->wherePeriodoOs($de, $ate);
        $where .= " AND COALESCE(oe.fabricante,'') != ''";
        $sql = "
            SELECT oe.fabricante AS label, COUNT(*) AS quantidade
            FROM os_equipamento oe
            INNER JOIN ordem_servico os ON os.id = oe.os_id
            WHERE {$where}
            GROUP BY oe.fabricante
            ORDER BY quantidade DESC
            LIMIT 60
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Helpers privados ───────────────────────────────────────────────────

    private function whereSaidas(array $f): array
    {
        $where = 'oe.diagnostico_concluido_em IS NOT NULL';
        $params = [];

        if (($f['de'] ?? '') !== '') {
            $where .= ' AND oe.diagnostico_concluido_em >= :de';
            $params[':de'] = $f['de'] . ' 00:00:00';
        }
        if (($f['ate'] ?? '') !== '') {
            $where .= ' AND oe.diagnostico_concluido_em <= :ate';
            $params[':ate'] = $f['ate'] . ' 23:59:59';
        }
        if (($f['tecnico_id'] ?? '') !== '') {
            $where .= ' AND oe.diagnostico_concluido_por = :tid';
            $params[':tid'] = (int) $f['tecnico_id'];
        }

        $tipo = (string) ($f['tipo'] ?? '');
        if ($tipo !== '') {
            $where .= match ($tipo) {
                'Motobomba'      => " AND LOWER(oe.nome) REGEXP 'motobomba'",
                'Bomba'          => " AND LOWER(oe.nome) REGEXP 'bomba' AND LOWER(oe.nome) NOT REGEXP 'motobomba'",
                'Motor Elétrico' => " AND LOWER(oe.nome) REGEXP 'motor'",
                'Outros'         => " AND LOWER(oe.nome) NOT REGEXP 'motobomba|bomba|motor'",
                default          => '',
            };
        }

        return [$where, $params];
    }

    private function wherePeriodoOs(string $de, string $ate): array
    {
        $where = '1=1';
        $params = [];

        if ($de !== '') {
            $where .= ' AND os.created_at >= :de';
            $params[':de'] = $de . ' 00:00:00';
        }
        if ($ate !== '') {
            $where .= ' AND os.created_at <= :ate';
            $params[':ate'] = $ate . ' 23:59:59';
        }

        return [$where, $params];
    }
}
