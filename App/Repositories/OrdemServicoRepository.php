<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class OrdemServicoRepository
{
    /**
     * Lista OS com filtros e paginação (para o Painel).
     */
    public function listar(array $filtros, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        $this->aplicarFiltros($filtros, $where, $params);

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT o.id, o.cliente_id, o.data_entrada, o.nome_cliente, o.telefone,
                       o.status, o.created_at, o.usuario_recebeu,
                       COUNT(eq.id) AS total_equipamentos
                  FROM ordem_servico o
                  LEFT JOIN os_equipamento eq ON eq.os_id = o.id
                 {$whereStr}
                 GROUP BY o.id
                 ORDER BY o.created_at DESC
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

    /**
     * Conta total de OS com filtros.
     */
    public function contar(array $filtros): int
    {
        $where = [];
        $params = [];
        $this->aplicarFiltros($filtros, $where, $params);

        $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT COUNT(*) FROM ordem_servico o {$whereStr}";
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Cria a OS principal. Retorna o ID.
     */
    public function criar(array $dados): string
    {
        $sql = "INSERT INTO ordem_servico
                (id, cliente_id, data_entrada, nome_cliente, telefone,
                 contato_nome, contato_telefone,
                 doc_cliente, usuario_recebeu, status, created_at)
                VALUES (:id, :cliente_id, :data_entrada, :nome_cliente, :telefone,
                        :contato_nome, :contato_telefone,
                        :doc_cliente, :usuario_recebeu, 'aberta', NOW())";

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':id'               => $dados['id'],
            ':cliente_id'       => $dados['cliente_id'] ?? null,
            ':data_entrada'     => $dados['data_entrada'],
            ':nome_cliente'     => $dados['nome_cliente'],
            ':telefone'         => $dados['telefone'] ?? '',
            ':contato_nome'     => ($dados['contato_nome'] ?? '') !== '' ? (string)$dados['contato_nome'] : null,
            ':contato_telefone' => ($dados['contato_telefone'] ?? '') !== '' ? (string)$dados['contato_telefone'] : null,
            ':doc_cliente'      => $dados['doc_cliente'] ?? '',
            ':usuario_recebeu'  => $dados['usuario_recebeu'] ?? '',
        ]);
        
        return $dados['id'];
    }

    public function buscarPorId(string $id): ?array
    {
        $sql = "SELECT id, cliente_id, data_entrada, nome_cliente, telefone,
                       contato_nome, contato_telefone,
                       doc_cliente, status, created_at, updated_at, usuario_recebeu,
                       data_conclusao
                  FROM ordem_servico
                 WHERE id = :id
                 LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function atualizar(string $id, array $dados): void
    {
        $sql = "UPDATE ordem_servico
                   SET cliente_id       = :cliente_id,
                       nome_cliente     = :nome_cliente,
                       telefone         = :telefone,
                       contato_nome     = :contato_nome,
                       contato_telefone = :contato_telefone,
                       doc_cliente      = :doc_cliente
                 WHERE id = :id LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':id'               => $id,
            ':cliente_id'       => $dados['cliente_id'] ?? null,
            ':nome_cliente'     => $dados['nome_cliente'],
            ':telefone'         => $dados['telefone'] ?? '',
            ':contato_nome'     => ($dados['contato_nome'] ?? '') !== '' ? (string)$dados['contato_nome'] : null,
            ':contato_telefone' => ($dados['contato_telefone'] ?? '') !== '' ? (string)$dados['contato_telefone'] : null,
            ':doc_cliente'      => $dados['doc_cliente'] ?? '',
        ]);
    }

    public function buscarPorTelefone(string $telefone, int $limit = 50): array
    {
        $sql = "SELECT id, cliente_id, data_entrada, nome_cliente, telefone,
                       status, created_at
                  FROM ordem_servico
                 WHERE telefone = :tel
                 ORDER BY created_at DESC
                 LIMIT :lim";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':tel', $telefone);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarComResumoPorTelefone(string $telefone, int $limit = 50): array
    {
        $telefoneLimpo = preg_replace('/\D/', '', $telefone);
        if ($telefoneLimpo === null || $telefoneLimpo === '') return [];

        $sql = "SELECT
                  o.id, o.data_entrada, o.nome_cliente, o.telefone, o.status,
                  o.created_at,
                  GROUP_CONCAT(eq.nome ORDER BY eq.ordem_idx SEPARATOR ', ') AS equipamentos,
                  COUNT(eq.id) AS qtd_equipamentos
                  FROM ordem_servico o
             LEFT JOIN os_equipamento eq ON eq.os_id = o.id
                 WHERE REPLACE(REPLACE(REPLACE(REPLACE(o.telefone, '(', ''), ')', ''), '-', ''), ' ', '')
                       LIKE :tel
              GROUP BY o.id
              ORDER BY o.created_at DESC
                 LIMIT :lim";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':tel', '%' . $telefoneLimpo . '%');
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca global do topo: localiza OS por ID, nome do cliente, telefone ou
     * pelos dados do equipamento (série, nome, fabricante, modelo).
     * Retorna um resumo da OS + lista de equipamentos/séries para exibição.
     */
    public function buscarGlobal(string $termo, int $limit = 12): array
    {
        $termo = trim($termo);
        if ($termo === '') return [];

        $like    = '%' . $termo . '%';
        $digitos = preg_replace('/\D/', '', $termo);

        // Cláusulas OR montadas dinamicamente — telefone só entra se houver
        // ao menos 3 dígitos no termo (evita casar com qualquer coisa).
        $ors = [
            'o.id LIKE :like_id',
            'o.nome_cliente LIKE :like_nome',
            "EXISTS (SELECT 1 FROM os_equipamento e2
                      WHERE e2.os_id = o.id
                        AND (e2.serie LIKE :like_serie
                          OR e2.nome LIKE :like_eqnome
                          OR e2.fabricante LIKE :like_fab
                          OR e2.modelo LIKE :like_mod))",
        ];
        $params = [
            ':like_id'     => $like,
            ':like_nome'   => $like,
            ':like_serie'  => $like,
            ':like_eqnome' => $like,
            ':like_fab'    => $like,
            ':like_mod'    => $like,
        ];
        if (strlen((string) $digitos) >= 3) {
            $ors[] = "REPLACE(REPLACE(REPLACE(REPLACE(o.telefone, '(', ''), ')', ''), '-', ''), ' ', '') LIKE :like_tel";
            $params[':like_tel'] = '%' . $digitos . '%';
        }

        $sql = "SELECT
                    o.id, o.data_entrada, o.nome_cliente, o.telefone, o.status,
                    o.created_at,
                    GROUP_CONCAT(DISTINCT eq.nome ORDER BY eq.ordem_idx SEPARATOR ', ') AS equipamentos,
                    GROUP_CONCAT(DISTINCT NULLIF(eq.serie, '') SEPARATOR ', ')          AS series,
                    COUNT(DISTINCT eq.id) AS qtd_equipamentos
                  FROM ordem_servico o
             LEFT JOIN os_equipamento eq ON eq.os_id = o.id
                 WHERE " . implode("\n                    OR ", $ors) . "
              GROUP BY o.id
              ORDER BY (o.id = :exato) DESC, o.created_at DESC
                 LIMIT :lim";

        $params[':exato'] = $termo;

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarStatus(string $id): ?string
    {
        $sql = "SELECT status FROM ordem_servico WHERE id = :id LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':id' => $id]);
        $status = $stmt->fetchColumn();
        return $status === false ? null : (string) $status;
    }

    public function atualizarStatus(string $id, string $status): void
    {
        $sql = "UPDATE ordem_servico SET status = :status WHERE id = :id";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':status' => $status, ':id' => $id]);
    }

    public function buscarEquipamentos(string $osId): array
    {
        $sql = "SELECT * FROM os_equipamento WHERE os_id = ? ORDER BY ordem_idx ASC";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$osId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deletarEquipamentos(string $osId): void
    {
        $sql = "DELETE FROM os_equipamento WHERE os_id = ?";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$osId]);
    }

    public function adicionarEquipamento(string $osId, array $dados): void
    {
        $sql = "INSERT INTO os_equipamento
                (os_id, ordem_idx, nome, fabricante, modelo, serie, defeito, voltagem, cx, em_garantia, tipo_garantia, garantia_autorizacao)
                VALUES (:os_id, :ordem_idx, :nome, :fabricante, :modelo, :serie, :defeito, :voltagem, :cx, :em_garantia, :tipo_garantia, :garantia_autorizacao)";
        $stmt = Database::pdo()->prepare($sql);
        $ga = trim((string) ($dados['garantia_autorizacao'] ?? ''));
        $stmt->execute([
            ':os_id'                => $osId,
            ':ordem_idx'            => $dados['ordem_idx'] ?? 0,
            ':nome'                 => $dados['nome'] ?? '',
            ':fabricante'           => $dados['fabricante'] ?? '',
            ':modelo'               => $dados['modelo'] ?? '',
            ':serie'                => $dados['serie'] ?? '',
            ':defeito'              => $dados['defeito'] ?? '',
            ':voltagem'             => $dados['voltagem'] ?? '',
            ':cx'                   => $dados['cx'] ?? '',
            ':em_garantia'          => $dados['em_garantia'] ?? 0,
            ':tipo_garantia'        => $dados['tipo_garantia'] ?? null,
            ':garantia_autorizacao' => $ga !== '' ? mb_strtoupper($ga, 'UTF-8') : null,
        ]);
    }

    private function aplicarFiltros(array $filtros, array &$where, array &$params): void
    {
        $busca = trim($filtros['busca'] ?? '');
        if ($busca !== '') {
            $dig = preg_replace('/\D/', '', $busca);
            $like = "%{$busca}%";

            $conditions = [
                'o.id LIKE :busca_id',
                'o.nome_cliente LIKE :busca_nome'
            ];
            $params[':busca_id'] = $like;
            $params[':busca_nome'] = $like;

            if ($dig !== '') {
                $conditions[] = 'o.telefone LIKE :busca_tel';
                $conditions[] = 'o.doc_cliente LIKE :busca_doc';
                $params[':busca_tel'] = "%{$dig}%";
                $params[':busca_doc'] = "%{$dig}%";
            }

            $where[] = '(' . implode(' OR ', $conditions) . ')';
        }

        $status = trim($filtros['status'] ?? '');
        if ($status !== '') {
            $where[] = 'o.status = :status';
            $params[':status'] = $status;
        }

        $dataInicio = trim($filtros['data_inicio'] ?? '');
        if ($dataInicio !== '') {
            $where[] = 'DATE(o.created_at) >= :dt_ini';
            $params[':dt_ini'] = $dataInicio;
        }

        $dataFim = trim($filtros['data_fim'] ?? '');
        if ($dataFim !== '') {
            $where[] = 'DATE(o.created_at) <= :dt_fim';
            $params[':dt_fim'] = $dataFim;
        }
    }
}
