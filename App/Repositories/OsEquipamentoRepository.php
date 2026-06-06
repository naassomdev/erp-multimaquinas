<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class OsEquipamentoRepository
{
    private const STATUS_VALIDOS = ['aberta', 'andamento', 'montagem', 'pronto', 'cancelado'];

    /**
     * @param array{status?: string, garantia?: string, busca?: string} $filtros
     * @return array<int, array<string, mixed>>
     */
    public function listarComFiltros(array $filtros, int $page = 1, int $perPage = 30): array
    {
        [$where, $params] = $this->buildWhere($filtros);
        $whereSql = $where === '' ? '' : "WHERE {$where}";

        $sql = "SELECT
                  e.id            AS equip_id,
                  e.os_id, e.ordem_idx,
                  e.nome          AS equip_nome,
                  e.fabricante, e.modelo, e.serie, e.defeito, e.voltagem, e.cx,
                  e.status_equip, e.diagnostico_concluido_em,
                  e.em_garantia, e.tipo_garantia,
                  o.nome_cliente, o.telefone, o.data_entrada,
                  o.status        AS os_status,
                  o.created_at    AS os_created_at,
                  o.usuario_recebeu
                  FROM os_equipamento e
            INNER JOIN ordem_servico o ON o.id = e.os_id
                {$whereSql}
              ORDER BY o.created_at DESC, e.ordem_idx ASC
                 LIMIT :limit OFFSET :offset";

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit',  max(1, $perPage),  PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, ($page - 1) * $perPage), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function contarComFiltros(array $filtros): int
    {
        [$where, $params] = $this->buildWhere($filtros);
        $whereSql = $where === '' ? '' : "WHERE {$where}";

        $sql = "SELECT COUNT(*) AS total
                  FROM os_equipamento e
            INNER JOIN ordem_servico o ON o.id = e.os_id
                {$whereSql}";

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function buscar(string $osId, int $equipIdx): ?array
    {
        $sql = "SELECT id, os_id, ordem_idx, nome, fabricante, modelo, serie, defeito, voltagem, cx,
                       status_equip, status_equip_em, diagnostico_concluido_em, diagnostico_concluido_por,
                       vista_explodida, obs_int, obs_cli,
                       pecas_json, fotos_os_json, fotos_json,
                       em_garantia, tipo_garantia, garantia_autorizacao,
                       descarte_autorizado_em, descarte_autorizado_por,
                       descarte_autorizado_uid, descarte_meio,
                       descarte_executado_em, descarte_executado_uid,
                       devolucao_em, devolucao_uid
                  FROM os_equipamento
                 WHERE os_id = :os AND ordem_idx = :idx
                 LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':os' => $osId, ':idx' => $equipIdx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listarPorOs(string $osId, int $limit = 50): array
    {
        $sql = "SELECT id, os_id, ordem_idx, nome, fabricante, modelo, serie, defeito, voltagem, cx,
                       status_equip, diagnostico_concluido_em, diagnostico_concluido_por,
                       em_garantia, tipo_garantia, garantia_autorizacao, obs_int, obs_cli
                  FROM os_equipamento
                 WHERE os_id = :os
                 ORDER BY ordem_idx ASC
                 LIMIT :lim";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':os', $osId);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Igual a listarPorOs, mas inclui a contagem de itens registrados pelo técnico
     * (qtd_itens_tecnico) via LEFT JOIN — usado exclusivamente pelo módulo de Orçamento
     * para exibir o badge e habilitar o botão "Importar do técnico".
     */
    public function listarPorOsParaOrcamento(string $osId, int $limit = 50): array
    {
        $sql = "SELECT e.id, e.os_id, e.ordem_idx, e.nome, e.fabricante, e.modelo,
                       e.serie, e.defeito, e.voltagem, e.cx,
                       e.status_equip, e.diagnostico_concluido_em, e.diagnostico_concluido_por,
                       e.em_garantia, e.tipo_garantia, e.garantia_autorizacao, e.obs_int, e.obs_cli,
                       e.fotos_json,
                       e.descarte_autorizado_em, e.descarte_autorizado_por, e.descarte_meio,
                       e.devolucao_em,
                       COUNT(ti.id) AS qtd_itens_tecnico
                  FROM os_equipamento e
             LEFT JOIN tecnico_itens ti ON ti.os_id = e.os_id AND ti.equip_idx = e.ordem_idx AND ti.ativo = 1
                 WHERE e.os_id = :os
                 GROUP BY e.id
                 ORDER BY e.ordem_idx ASC
                 LIMIT :lim";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':os', $osId);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, string> */
    public function listarStatusPorOs(string $osId): array
    {
        $sql = "SELECT status_equip
                  FROM os_equipamento
                 WHERE os_id = :os
                 LIMIT 50";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':os' => $osId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function atualizarStatus(string $osId, int $equipIdx, string $status): void
    {
        if (!in_array($status, self::STATUS_VALIDOS, true)) {
            throw new \InvalidArgumentException("Status inválido: {$status}");
        }
        $sql = "UPDATE os_equipamento
                   SET status_equip    = :status,
                       status_equip_em = NOW()
                 WHERE os_id = :os AND ordem_idx = :idx";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':status' => $status, ':os' => $osId, ':idx' => $equipIdx]);
    }

    public function atualizarLaudo(string $osId, int $equipIdx, string $obsInt, ?string $obsCli): void
    {
        $sql = "UPDATE os_equipamento
                   SET obs_int = :obs_int,
                       obs_cli = :obs_cli,
                       diagnostico_concluido_em = NULL,
                       diagnostico_concluido_por = NULL
                 WHERE os_id = :os AND ordem_idx = :idx";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':obs_int' => $obsInt,
            ':obs_cli' => $obsCli,
            ':os'      => $osId,
            ':idx'     => $equipIdx,
        ]);
    }

    public function atualizarNome(string $osId, int $equipIdx, string $nome): void
    {
        $sql = "UPDATE os_equipamento
                   SET nome = :nome
                 WHERE os_id = :os AND ordem_idx = :idx";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':nome' => mb_strtoupper($nome),
            ':os'   => $osId,
            ':idx'  => $equipIdx,
        ]);
    }

    public function atualizarDescricaoECaixa(string $osId, int $equipIdx, string $descricao, string $cx): void
    {
        $sql = "UPDATE os_equipamento
                   SET defeito = :defeito,
                       cx = :cx
                 WHERE os_id = :os AND ordem_idx = :idx";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':defeito' => mb_strtoupper(trim($descricao)),
            ':cx'      => mb_strtoupper(trim($cx)),
            ':os'      => $osId,
            ':idx'     => $equipIdx,
        ]);
    }

    /**
     * Atualiza campos técnicos editáveis (serie, voltagem, cx, fabricante, modelo) — sempre em UPPERCASE.
     * @param array{serie?: string, voltagem?: string, cx?: string, fabricante?: string, modelo?: string} $campos
     */
    public function atualizarDados(string $osId, int $equipIdx, array $campos): void
    {
        $allowed = ['serie', 'voltagem', 'cx', 'fabricante', 'modelo', 'garantia_autorizacao'];
        $sets    = [];
        $params  = [':os' => $osId, ':idx' => $equipIdx];

        foreach ($allowed as $campo) {
            if (array_key_exists($campo, $campos)) {
                $sets[] = "{$campo} = :{$campo}";
                $val = trim((string) $campos[$campo]);
                // garantia_autorizacao é nullable — salva NULL quando vazio.
                $params[":{$campo}"] = ($campo === 'garantia_autorizacao' && $val === '')
                    ? null
                    : mb_strtoupper($val, 'UTF-8');
            }
        }

        if (empty($sets)) {
            return;
        }

        $sql  = 'UPDATE os_equipamento SET ' . implode(', ', $sets)
              . ' WHERE os_id = :os AND ordem_idx = :idx';
        Database::pdo()->prepare($sql)->execute($params);
    }

    /** Retorna o valor atual do campo cx (string vazia se nulo). */
    public function buscarCx(string $osId, int $equipIdx): string
    {
        $sql  = 'SELECT cx FROM os_equipamento WHERE os_id = :os AND ordem_idx = :idx LIMIT 1';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':os' => $osId, ':idx' => $equipIdx]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    }

    public function appendObsInterno(string $osId, int $equipIdx, string $texto): void
    {
        $sql = "UPDATE os_equipamento
                   SET obs_int = IF(obs_int IS NULL OR obs_int = '',
                                    :t1,
                                    CONCAT(obs_int, CHAR(10), :t2))
                 WHERE os_id = :os AND ordem_idx = :idx";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':t1'  => $texto,
            ':t2'  => $texto,
            ':os'  => $osId,
            ':idx' => $equipIdx,
        ]);
    }

    public function concluirDiagnostico(string $osId, int $equipIdx, int $usuarioId): void
    {
        $sql = "UPDATE os_equipamento
                   SET diagnostico_concluido_em = NOW(),
                       diagnostico_concluido_por = :uid
                 WHERE os_id = :os
                   AND ordem_idx = :idx
                   AND status_equip = 'andamento'";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':uid' => $usuarioId > 0 ? $usuarioId : null,
            ':os'  => $osId,
            ':idx' => $equipIdx,
        ]);
    }

    public function limparConclusaoDiagnostico(string $osId, int $equipIdx): void
    {
        $sql = "UPDATE os_equipamento
                   SET diagnostico_concluido_em = NULL,
                       diagnostico_concluido_por = NULL
                 WHERE os_id = :os AND ordem_idx = :idx";
        Database::pdo()->prepare($sql)->execute([':os' => $osId, ':idx' => $equipIdx]);
    }

    /**
     * @return array<int, string> lista de URLs após adição
     */
    public function adicionarFoto(string $osId, int $equipIdx, string $url): array
    {
        $fotos = $this->lerFotos($osId, $equipIdx);
        $fotos[] = $url;
        $this->salvarFotos($osId, $equipIdx, $fotos);
        return $fotos;
    }

    /**
     * @return array<int, string> lista de URLs após remoção
     */
    public function removerFoto(string $osId, int $equipIdx, string $url): array
    {
        $fotos = $this->lerFotos($osId, $equipIdx);
        $fotos = array_values(array_filter($fotos, static fn($f) => $f !== $url));
        $this->salvarFotos($osId, $equipIdx, $fotos);
        return $fotos;
    }

    public function setVistaExplodida(string $osId, int $equipIdx, ?string $url): void
    {
        $sql = "UPDATE os_equipamento
                   SET vista_explodida = :url
                 WHERE os_id = :os AND ordem_idx = :idx";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':url' => $url ?? '',
            ':os'  => $osId,
            ':idx' => $equipIdx,
        ]);
    }

    /** @return array<int, string> */
    private function lerFotos(string $osId, int $equipIdx): array
    {
        $sql = "SELECT fotos_json FROM os_equipamento
                 WHERE os_id = :os AND ordem_idx = :idx LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':os' => $osId, ':idx' => $equipIdx]);
        $json = $stmt->fetchColumn();
        if ($json === false) {
            throw new \RuntimeException("Equipamento não encontrado: {$osId}#{$equipIdx}");
        }
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) return [];
        return array_values(array_filter($decoded, 'is_string'));
    }

    /** @param array<int, string> $fotos */
    private function salvarFotos(string $osId, int $equipIdx, array $fotos): void
    {
        $sql = "UPDATE os_equipamento
                   SET fotos_json = :json
                 WHERE os_id = :os AND ordem_idx = :idx";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':json' => json_encode(array_values($fotos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':os'   => $osId,
            ':idx'  => $equipIdx,
        ]);
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $filtros): array
    {
        $clauses = [];
        $params  = [];

        $statusFiltro = (string) ($filtros['status'] ?? 'pendentes');
        if ($statusFiltro === 'pendentes') {
            $clauses[] = "e.status_equip IN ('aberta','andamento','montagem')";
            $clauses[] = "o.status NOT IN ('retirado','descartado','cancelado')";
        } elseif (in_array($statusFiltro, self::STATUS_VALIDOS, true)) {
            $clauses[] = "e.status_equip = :status";
            $params[':status'] = $statusFiltro;
        }

        $garantia = (string) ($filtros['garantia'] ?? '');
        if ($garantia === 'sim') {
            $clauses[] = "e.em_garantia = 1";
        } elseif ($garantia === 'nao') {
            $clauses[] = "e.em_garantia = 0";
        }

        $busca = trim((string) ($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $clauses[] = "(e.os_id LIKE :b1 OR o.nome_cliente LIKE :b2 OR e.nome LIKE :b3 OR e.defeito LIKE :b4)";
            $like = '%' . $busca . '%';
            $params[':b1'] = $like;
            $params[':b2'] = $like;
            $params[':b3'] = $like;
            $params[':b4'] = $like;
        }

        return [implode(' AND ', $clauses), $params];
    }
}
