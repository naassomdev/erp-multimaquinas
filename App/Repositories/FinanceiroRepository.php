<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use InvalidArgumentException;
use PDO;

/**
 * Repositório dos lançamentos financeiros (a receber e a pagar).
 * As duas tabelas têm shape parecido, então as operações são parametrizadas
 * pelo "tipo" (receber|pagar) e mapeadas para os respectivos joins.
 */
final class FinanceiroRepository
{
    public const TIPO_RECEBER = 'receber';
    public const TIPO_PAGAR   = 'pagar';

    private const TABELAS = [
        self::TIPO_RECEBER => 'lancamentos_receber',
        self::TIPO_PAGAR   => 'lancamentos_pagar',
    ];

    public function tabela(string $tipo): string
    {
        if (!isset(self::TABELAS[$tipo])) {
            throw new InvalidArgumentException("Tipo inválido: {$tipo}. Use receber|pagar.");
        }
        return self::TABELAS[$tipo];
    }

    /**
     * Lista lançamentos com filtros e paginação. Retorna joins com a contraparte
     * (cliente para receber, fornecedor para pagar).
     */
    public function listar(string $tipo, array $filtros, int $page, int $perPage): array
    {
        $tabela = $this->tabela($tipo);
        [$where, $params] = $this->buildWhere($tipo, $filtros);

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset = ($page - 1) * $perPage;

        if ($tipo === self::TIPO_RECEBER) {
            $sql = "SELECT l.*,
                           c.nome AS contraparte_nome,
                           c.cpf_cnpj AS contraparte_doc,
                           eq.nome AS equip_nome
                    FROM {$tabela} l
                    LEFT JOIN clientes c ON c.id = l.cliente_id
                    LEFT JOIN os_equipamento eq ON eq.os_id = l.os_id AND eq.ordem_idx = l.equip_idx
                    {$whereSql}
                    ORDER BY l.vencimento ASC, l.id DESC
                    LIMIT :limit OFFSET :offset";
        } else {
            $sql = "SELECT l.*,
                           f.nome AS contraparte_nome,
                           f.cnpj AS contraparte_doc
                    FROM {$tabela} l
                    LEFT JOIN fornecedores f ON f.id = l.fornecedor_id
                    {$whereSql}
                    ORDER BY l.vencimento ASC, l.id DESC
                    LIMIT :limit OFFSET :offset";
        }

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function contar(string $tipo, array $filtros): int
    {
        $tabela = $this->tabela($tipo);
        [$where, $params] = $this->buildWhere($tipo, $filtros);
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $joinSql = $this->joinContraparteSql($tipo);

        $sql = "SELECT COUNT(*) FROM {$tabela} l {$joinSql} {$whereSql}";
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function buscarPorId(string $tipo, int $id): ?array
    {
        $tabela = $this->tabela($tipo);

        if ($tipo === self::TIPO_RECEBER) {
            $sql = "SELECT l.*,
                           c.nome AS contraparte_nome,
                           c.cpf_cnpj AS contraparte_doc,
                           c.telefone AS contraparte_telefone,
                           eq.nome AS equip_nome
                    FROM {$tabela} l
                    LEFT JOIN clientes c ON c.id = l.cliente_id
                    LEFT JOIN os_equipamento eq ON eq.os_id = l.os_id AND eq.ordem_idx = l.equip_idx
                    WHERE l.id = ? LIMIT 1";
        } else {
            $sql = "SELECT l.*,
                           f.nome AS contraparte_nome,
                           f.cnpj AS contraparte_doc,
                           f.telefone AS contraparte_telefone
                    FROM {$tabela} l
                    LEFT JOIN fornecedores f ON f.id = l.fornecedor_id
                    WHERE l.id = ? LIMIT 1";
        }

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Cria um novo lançamento (receber ou pagar).
     * Para "receber", aceita: cliente_id, os_id, valor, vencimento, descricao.
     * Para "pagar",   aceita: fornecedor_id, chave_nfe, valor, vencimento, descricao.
     */
    public function criar(string $tipo, array $dados): int
    {
        $tabela = $this->tabela($tipo);
        $campos = $this->camposPermitidos($tipo);

        $cols = [];
        $params = [];
        foreach ($campos as $c) {
            if (array_key_exists($c, $dados)) {
                $cols[] = $c;
                $params[":{$c}"] = $dados[$c];
            }
        }

        if (empty($cols)) {
            throw new InvalidArgumentException('Nenhum campo válido informado para criar lançamento.');
        }

        $colsSql = implode(', ', $cols);
        $placeholders = implode(', ', array_map(fn($c) => ":{$c}", $cols));

        $sql = "INSERT INTO {$tabela} ({$colsSql}) VALUES ({$placeholders})";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int)Database::pdo()->lastInsertId();
    }

    public function atualizar(string $tipo, int $id, array $dados): void
    {
        $tabela = $this->tabela($tipo);
        $campos = $this->camposPermitidos($tipo);

        $sets = [];
        $params = [':id' => $id];
        foreach ($campos as $c) {
            if (array_key_exists($c, $dados)) {
                $sets[] = "{$c} = :{$c}";
                $params[":{$c}"] = $dados[$c];
            }
        }
        if (empty($sets)) return;

        $sql = "UPDATE {$tabela} SET " . implode(', ', $sets) . " WHERE id = :id LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
    }

    public function cancelar(string $tipo, int $id): void
    {
        $tabela = $this->tabela($tipo);
        $stmt = Database::pdo()->prepare(
            "UPDATE {$tabela} SET status = 'cancelado' WHERE id = ? AND status = 'aberto' LIMIT 1"
        );
        $stmt->execute([$id]);
    }

    /**
     * Totais agrupados por status. Útil para cards do dashboard.
     */
    public function totaisPorStatus(string $tipo): array
    {
        $tabela = $this->tabela($tipo);
        $sql = "SELECT status,
                       COUNT(*) AS qtd,
                       COALESCE(SUM(valor), 0) AS valor_total,
                       COALESCE(SUM(valor_pago), 0) AS valor_pago_total
                FROM {$tabela}
                GROUP BY status";
        $stmt = Database::pdo()->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $resumo = [
            'aberto'            => ['qtd' => 0, 'valor_total' => 0.0, 'valor_pago_total' => 0.0],
            'pago'              => ['qtd' => 0, 'valor_total' => 0.0, 'valor_pago_total' => 0.0],
            'cancelado'         => ['qtd' => 0, 'valor_total' => 0.0, 'valor_pago_total' => 0.0],
            'aguardando_fatura' => ['qtd' => 0, 'valor_total' => 0.0, 'valor_pago_total' => 0.0],
        ];
        foreach ($rows as $r) {
            $resumo[$r['status']] = [
                'qtd'              => (int)$r['qtd'],
                'valor_total'      => (float)$r['valor_total'],
                'valor_pago_total' => (float)$r['valor_pago_total'],
            ];
        }
        return $resumo;
    }

    private function camposPermitidos(string $tipo): array
    {
        if ($tipo === self::TIPO_RECEBER) {
            return ['os_id', 'cliente_id', 'valor', 'valor_pago', 'vencimento', 'data_pagamento', 'status', 'descricao'];
        }
        return ['fornecedor_id', 'chave_nfe', 'valor', 'valor_pago', 'vencimento', 'data_pagamento', 'status', 'descricao'];
    }

    private function joinContraparteSql(string $tipo): string
    {
        if ($tipo === self::TIPO_RECEBER) {
            return 'LEFT JOIN clientes c ON c.id = l.cliente_id';
        }

        return 'LEFT JOIN fornecedores f ON f.id = l.fornecedor_id';
    }

    /**
     * Constrói WHERE comum (status, período, busca textual). O alias da tabela é "l".
     */
    private function buildWhere(string $tipo, array $filtros): array
    {
        $where = [];
        $params = [];

        $status = $filtros['status'] ?? '';
        $statusValidos = $tipo === self::TIPO_RECEBER
            ? ['aberto', 'pago', 'cancelado', 'aguardando_fatura']
            : ['aberto', 'pago', 'cancelado'];
        if (in_array($status, $statusValidos, true)) {
            $where[] = 'l.status = :status';
            $params[':status'] = $status;
        }

        if ($tipo === self::TIPO_RECEBER) {
            $osId = trim((string)($filtros['os_id'] ?? ''));
            if ($osId !== '') {
                $where[] = 'l.os_id = :os_id';
                $params[':os_id'] = (int)$osId;
            }

            $formaPag = trim((string)($filtros['forma_pag'] ?? ''));
            if ($formaPag !== '') {
                $where[] = 'l.forma_pagamento = :forma_pag';
                $params[':forma_pag'] = $formaPag;
            }
        }

        $de = $filtros['de'] ?? '';
        $ate = $filtros['ate'] ?? '';
        if ($de !== '' && $ate !== '') {
            $where[] = 'l.vencimento BETWEEN :de AND :ate';
            $params[':de'] = $de;
            $params[':ate'] = $ate;
        } elseif ($de !== '') {
            $where[] = 'l.vencimento >= :de';
            $params[':de'] = $de;
        } elseif ($ate !== '') {
            $where[] = 'l.vencimento <= :ate';
            $params[':ate'] = $ate;
        }

        if (!empty($filtros['vencidas'])) {
            $where[] = "l.status = 'aberto' AND l.vencimento < CURDATE()";
        }

        $busca = trim((string)($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $like = "%{$busca}%";
            if ($tipo === self::TIPO_RECEBER) {
                $where[] = '(l.descricao LIKE :b1 OR c.nome LIKE :b2 OR c.cpf_cnpj LIKE :b3)';
                $params[':b1'] = $like;
                $params[':b2'] = $like;
                $params[':b3'] = $like;
            } else {
                $where[] = '(l.descricao LIKE :b1 OR f.nome LIKE :b2 OR f.cnpj LIKE :b3 OR l.chave_nfe LIKE :b4)';
                $params[':b1'] = $like;
                $params[':b2'] = $like;
                $params[':b3'] = $like;
                $params[':b4'] = $like;
            }
        }

        return [$where, $params];
    }
}
