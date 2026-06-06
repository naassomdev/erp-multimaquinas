<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Relatório de garantias de fabricante — somente leitura.
 * Não altera nenhuma tabela; todas as consultas são SELECT.
 *
 * Etapa 9J-3.
 */
final class GarantiaRepository
{
    private const PER_PAGE = 50;

    /**
     * Lista equipamentos com tipo_garantia = 'fabricante', com filtros e paginação.
     *
     * @param array{de?:string, ate?:string, fabricante?:string, status_equip?:string, motivo_gratuidade?:string, autorizacao?:string} $filtros
     * @return list<array<string, mixed>>
     */
    public function listarGarantiasFabricante(array $filtros = [], int $pagina = 1): array
    {
        [$whereStr, $params] = $this->buildWhere($filtros);

        $offset = ($pagina - 1) * self::PER_PAGE;

        $sql = "
            SELECT
                eq.os_id,
                eq.ordem_idx             AS equip_idx,
                eq.nome                  AS equip_nome,
                eq.fabricante,
                eq.garantia_autorizacao,
                eq.status_equip,
                eq.status_equip_em,
                os.nome_cliente,
                os.telefone,
                os.status                AS status_os,
                os.created_at            AS os_criado_em,
                o.id                     AS orc_id,
                o.status                 AS orc_status,
                o.total                  AS orc_total,
                o.motivo_gratuidade,
                o.data_aprovado,
                o.data_retirada,
                o.retirado_por
            FROM os_equipamento eq
            JOIN ordem_servico os ON os.id = eq.os_id
            LEFT JOIN orcamentos o
                   ON o.os_id = eq.os_id AND o.equip_idx = eq.ordem_idx
            WHERE eq.em_garantia = 1
              AND eq.tipo_garantia = 'fabricante'
              {$whereStr}
            ORDER BY os.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,        PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Total de registros para paginação (respeita os mesmos filtros).
     */
    public function contarGarantiasFabricante(array $filtros = []): int
    {
        [$whereStr, $params] = $this->buildWhere($filtros);

        $sql = "
            SELECT COUNT(*)
            FROM os_equipamento eq
            JOIN ordem_servico os ON os.id = eq.os_id
            LEFT JOIN orcamentos o
                   ON o.os_id = eq.os_id AND o.equip_idx = eq.ordem_idx
            WHERE eq.em_garantia = 1
              AND eq.tipo_garantia = 'fabricante'
              {$whereStr}
        ";

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * KPIs agregados (respeita os mesmos filtros).
     *
     * @return array{total:int, retirados:int, em_aberto:int, sem_autorizacao:int, total_zero:int, motivo_garantia_fab:int}
     */
    public function kpis(array $filtros = []): array
    {
        [$whereStr, $params] = $this->buildWhere($filtros);

        $sql = "
            SELECT
                COUNT(*)                                                                          AS total,
                SUM(eq.status_equip = 'retirado')                                                AS retirados,
                SUM(eq.status_equip NOT IN ('retirado','devolvido','descartado','cancelado'))     AS em_aberto,
                SUM(eq.garantia_autorizacao IS NULL OR eq.garantia_autorizacao = '')              AS sem_autorizacao,
                SUM(o.id IS NOT NULL AND CAST(o.total AS DECIMAL(10,2)) = 0)                     AS total_zero,
                SUM(o.motivo_gratuidade = 'garantia_fabricante')                                 AS motivo_garantia_fab
            FROM os_equipamento eq
            JOIN ordem_servico os ON os.id = eq.os_id
            LEFT JOIN orcamentos o
                   ON o.os_id = eq.os_id AND o.equip_idx = eq.ordem_idx
            WHERE eq.em_garantia = 1
              AND eq.tipo_garantia = 'fabricante'
              {$whereStr}
        ";

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : [];
    }

    /**
     * Lista todos os registros para exportação CSV — sem paginação.
     * Mesmos filtros de listarGarantiasFabricante(); ordenação estável por fabricante → os_id → equip_idx.
     *
     * @return list<array<string, mixed>>
     */
    public function listarGarantiasFabricanteExport(array $filtros = []): array
    {
        [$whereStr, $params] = $this->buildWhere($filtros);

        $sql = "
            SELECT
                eq.os_id,
                eq.ordem_idx             AS equip_idx,
                eq.nome                  AS equip_nome,
                eq.fabricante,
                eq.garantia_autorizacao,
                eq.status_equip,
                eq.status_equip_em,
                os.nome_cliente,
                os.telefone,
                os.status                AS status_os,
                os.created_at            AS os_criado_em,
                o.id                     AS orc_id,
                o.status                 AS orc_status,
                o.total                  AS orc_total,
                o.motivo_gratuidade,
                o.data_aprovado,
                o.data_retirada,
                o.retirado_por
            FROM os_equipamento eq
            JOIN ordem_servico os ON os.id = eq.os_id
            LEFT JOIN orcamentos o
                   ON o.os_id = eq.os_id AND o.equip_idx = eq.ordem_idx
            WHERE eq.em_garantia = 1
              AND eq.tipo_garantia = 'fabricante'
              {$whereStr}
            ORDER BY eq.fabricante ASC, eq.os_id ASC, eq.ordem_idx ASC
        ";

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fabricantes distintos de equipamentos em garantia de fabricante.
     * Usado para popular o dropdown de filtro.
     *
     * @return list<string>
     */
    public function listarFabricantes(): array
    {
        $sql = "
            SELECT DISTINCT fabricante
            FROM os_equipamento
            WHERE em_garantia = 1
              AND tipo_garantia = 'fabricante'
              AND fabricante <> ''
            ORDER BY fabricante ASC
        ";
        return Database::pdo()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    // ── Privado ────────────────────────────────────────────────────────────

    /**
     * Monta a cláusula WHERE adicional (AND ...) e array de params.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $filtros): array
    {
        $where  = [];
        $params = [];

        // Período — data de abertura da OS
        $de  = trim($filtros['de']  ?? '');
        $ate = trim($filtros['ate'] ?? '');
        if ($de !== '') {
            $where[]       = 'DATE(os.created_at) >= :de';
            $params[':de'] = $de;
        }
        if ($ate !== '') {
            $where[]        = 'DATE(os.created_at) <= :ate';
            $params[':ate'] = $ate;
        }

        // Fabricante — valor exato (vem do dropdown)
        $fab = trim($filtros['fabricante'] ?? '');
        if ($fab !== '') {
            $where[]               = 'eq.fabricante = :fabricante';
            $params[':fabricante'] = $fab;
        }

        // Status físico — whitelist para evitar injeção
        $se = trim($filtros['status_equip'] ?? '');
        $statusAllowed = ['aberta','andamento','montagem','pronto','retirado','devolvido','descartado','cancelado'];
        if ($se !== '' && in_array($se, $statusAllowed, true)) {
            $where[]                 = 'eq.status_equip = :status_equip';
            $params[':status_equip'] = $se;
        }

        // Motivo gratuidade
        $motivo = trim($filtros['motivo_gratuidade'] ?? '');
        if ($motivo === 'garantia_fabricante') {
            $where[] = "o.motivo_gratuidade = 'garantia_fabricante'";
        } elseif ($motivo === 'cortesia') {
            $where[] = "o.motivo_gratuidade = 'cortesia'";
        } elseif ($motivo === 'nao_informado') {
            $where[] = '(o.motivo_gratuidade IS NULL)';
        }

        // Autorização / RMA
        $aut = trim($filtros['autorizacao'] ?? '');
        if ($aut === 'com') {
            $where[] = "(eq.garantia_autorizacao IS NOT NULL AND eq.garantia_autorizacao <> '')";
        } elseif ($aut === 'sem') {
            $where[] = "(eq.garantia_autorizacao IS NULL OR eq.garantia_autorizacao = '')";
        }

        $whereStr = !empty($where) ? 'AND ' . implode(' AND ', $where) : '';
        return [$whereStr, $params];
    }
}
