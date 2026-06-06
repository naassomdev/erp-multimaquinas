<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Repositório de notas_fiscais (NFS-e). Listagem com filtros, busca por id e
 * helpers para o dashboard. Joins seguem a mesma regra do EmitirNfseJob:
 * para puxar cliente/valor passamos por lancamentos_receber, evitando o
 * mismatch ordem_servico.id (varchar) ↔ notas_fiscais.os_id (int).
 */
final class NfseRepository
{
    public function listar(array $filtros, int $page, int $perPage): array
    {
        [$where, $params] = $this->buildWhere($filtros);
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset   = ($page - 1) * $perPage;

        $sql = "SELECT nf.id, nf.os_id, nf.lancamento_id, nf.orcamento_id, nf.status,
                       nf.numero, nf.protocolo, nf.chave_acesso, nf.ambiente, nf.criado_em, nf.atualizado_em,
                       COALESCE(nf.valor_total, lr.valor) AS valor,
                       COALESCE(nf.descricao_servico, lr.descricao) AS descricao,
                       COALESCE(nf.cliente_id, lr.cliente_id) AS cliente_id,
                       c.nome        AS cliente_nome,
                       c.cpf_cnpj    AS cpf_cnpj
                FROM notas_fiscais nf
                LEFT JOIN lancamentos_receber lr ON lr.id = nf.lancamento_id
                LEFT JOIN clientes c             ON c.id  = COALESCE(nf.cliente_id, lr.cliente_id)
                {$whereSql}
                ORDER BY nf.id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function contar(array $filtros): int
    {
        [$where, $params] = $this->buildWhere($filtros);
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT COUNT(*)
                FROM notas_fiscais nf
                LEFT JOIN lancamentos_receber lr ON lr.id = nf.lancamento_id
                LEFT JOIN clientes c             ON c.id  = COALESCE(nf.cliente_id, lr.cliente_id)
                {$whereSql}";
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function buscarPorId(int $id): ?array
    {
        $sql = "SELECT nf.*,
                       COALESCE(nf.valor_total, lr.valor) AS valor,
                       COALESCE(nf.descricao_servico, lr.descricao) AS descricao,
                       COALESCE(nf.cliente_id, lr.cliente_id) AS cliente_id,
                       lr.os_id      AS lr_os_id,
                       lr.vencimento AS vencimento,
                       lr.status     AS lancamento_status,
                       c.nome        AS cliente_nome,
                       c.cpf_cnpj    AS cpf_cnpj,
                       c.telefone    AS cliente_telefone,
                       c.email       AS cliente_email,
                       c.endereco    AS cliente_endereco,
                       c.numero      AS cliente_numero,
                       c.complemento AS cliente_complemento,
                       c.bairro      AS cliente_bairro,
                       c.cidade      AS cliente_cidade,
                       c.uf          AS cliente_uf,
                       c.cep         AS cliente_cep
                FROM notas_fiscais nf
                LEFT JOIN lancamentos_receber lr ON lr.id = nf.lancamento_id
                LEFT JOIN clientes c             ON c.id  = COALESCE(nf.cliente_id, lr.cliente_id)
                WHERE nf.id = ?
                LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Totais por status (para cards do dashboard).
     */
    public function totaisPorStatus(): array
    {
        $sql = "SELECT nf.status,
                       COUNT(*)                          AS qtd,
                       COALESCE(SUM(COALESCE(nf.valor_total, lr.valor)), 0) AS valor_total
                FROM notas_fiscais nf
                LEFT JOIN lancamentos_receber lr ON lr.id = nf.lancamento_id
                GROUP BY nf.status";
        $rows = Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $resumo = [
            'pendente'   => ['qtd' => 0, 'valor_total' => 0.0],
            'rascunho'   => ['qtd' => 0, 'valor_total' => 0.0],
            'autorizada' => ['qtd' => 0, 'valor_total' => 0.0],
            'rejeitada'  => ['qtd' => 0, 'valor_total' => 0.0],
            'cancelada'  => ['qtd' => 0, 'valor_total' => 0.0],
            'erro'       => ['qtd' => 0, 'valor_total' => 0.0],
            'substituida'=> ['qtd' => 0, 'valor_total' => 0.0],
        ];
        foreach ($rows as $r) {
            $resumo[$r['status']] = [
                'qtd'         => (int)$r['qtd'],
                'valor_total' => (float)$r['valor_total'],
            ];
        }
        return $resumo;
    }

    /**
     * Atualiza o status de uma nota (para uso pelo NfseIntegrationService).
     */
    public function atualizarStatus(int $id, string $novoStatus, ?string $protocolo = null): void
    {
        if ($protocolo !== null) {
            $sql  = "UPDATE notas_fiscais SET status = ?, protocolo = ?, atualizado_em = NOW() WHERE id = ? LIMIT 1";
            $args = [$novoStatus, $protocolo, $id];
        } else {
            $sql  = "UPDATE notas_fiscais SET status = ?, atualizado_em = NOW() WHERE id = ? LIMIT 1";
            $args = [$novoStatus, $id];
        }
        Database::pdo()->prepare($sql)->execute($args);
    }

    private function buildWhere(array $filtros): array
    {
        $where = [];
        $params = [];

        $status = $filtros['status'] ?? '';
        if (in_array($status, ['rascunho', 'pendente', 'autorizada', 'rejeitada', 'cancelada', 'substituida', 'erro'], true)) {
            $where[] = 'nf.status = :status';
            $params[':status'] = $status;
        }

        $de  = $filtros['de']  ?? '';
        $ate = $filtros['ate'] ?? '';
        if ($de !== '' && $ate !== '') {
            $where[] = 'DATE(nf.criado_em) BETWEEN :de AND :ate';
            $params[':de']  = $de;
            $params[':ate'] = $ate;
        } elseif ($de !== '') {
            $where[] = 'DATE(nf.criado_em) >= :de';
            $params[':de'] = $de;
        } elseif ($ate !== '') {
            $where[] = 'DATE(nf.criado_em) <= :ate';
            $params[':ate'] = $ate;
        }

        $busca = trim((string)($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $like = "%{$busca}%";
            $where[] = '(nf.numero LIKE :b1 OR nf.protocolo LIKE :b2 OR c.nome LIKE :b3 OR c.cpf_cnpj LIKE :b4 OR COALESCE(nf.descricao_servico, lr.descricao) LIKE :b5)';
            $params[':b1'] = $like;
            $params[':b2'] = $like;
            $params[':b3'] = $like;
            $params[':b4'] = $like;
            $params[':b5'] = $like;
        }

        return [$where, $params];
    }

    /**
     * @param array<string,mixed> $dados
     */
    public function criarRascunho(array $dados): int
    {
        $sql = "INSERT INTO notas_fiscais
                   (os_id, lancamento_id, orcamento_id, cliente_id, tipo_documento, ambiente, status,
                    valor_total, descricao_servico, serie_dps, competencia, created_by, updated_by, criado_em, atualizado_em)
                VALUES
                   (:os_id, NULL, :orcamento_id, :cliente_id, 'nfse', :ambiente, 'rascunho',
                    :valor_total, :descricao_servico, :serie_dps, :competencia, :created_by, :updated_by, NOW(), NOW())";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':os_id' => $dados['os_id'],
            ':orcamento_id' => $dados['orcamento_id'],
            ':cliente_id' => $dados['cliente_id'],
            ':ambiente' => $dados['ambiente'],
            ':valor_total' => $dados['valor_total'],
            ':descricao_servico' => $dados['descricao_servico'],
            ':serie_dps' => $dados['serie_dps'],
            ':competencia' => $dados['competencia'],
            ':created_by' => $dados['created_by'],
            ':updated_by' => $dados['updated_by'],
        ]);

        return (int)Database::pdo()->lastInsertId();
    }

    public function atualizarConferencia(int $id, string $descricaoServico, int $usuarioId): void
    {
        Database::pdo()->prepare(
            "UPDATE notas_fiscais
                SET descricao_servico = ?,
                    status = CASE WHEN status = 'rascunho' THEN 'rascunho' ELSE status END,
                    updated_by = ?,
                    atualizado_em = NOW()
              WHERE id = ?
              LIMIT 1"
        )->execute([$descricaoServico, $usuarioId, $id]);
    }

    public function registrarEvento(
        int $notaId,
        string $tipo,
        ?string $statusAnterior,
        ?string $statusNovo,
        ?string $mensagem,
        ?int $usuarioId
    ): void {
        Database::pdo()->prepare(
            "INSERT INTO nota_fiscal_eventos
                 (nota_fiscal_id, tipo_evento, status_anterior, status_novo, mensagem, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$notaId, $tipo, $statusAnterior, $statusNovo, $mensagem, $usuarioId]);
    }
}
