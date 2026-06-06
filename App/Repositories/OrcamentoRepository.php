<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

final class OrcamentoRepository
{
    // 9H-4: status comerciais puros — pronto/retirado removidos (estado físico em os_equipamento.status_equip).
    public const STATUS_VALIDOS = ['rascunho', 'enviado', 'aprovado', 'cancelado'];

    public function buscarPorId(int $id): ?array
    {
        $sql = "SELECT id, os_id, equip_idx, tipo, status, tecnico, gerado_por,
                       obs_admin, obs_tecnico, mo_valor, total, motivo_gratuidade,
                       data_orcamento, data_aprovado, data_pronto, data_retirada,
                       retirado_por, pdv, pago, wpp_enviado_em,
                       created_at, updated_at
                  FROM orcamentos
                 WHERE id = :id
                 LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function buscarPorOsEquip(string $osId, int $equipIdx): ?array
    {
        $sql = "SELECT id, os_id, equip_idx, tipo, status, tecnico, gerado_por,
                       obs_admin, obs_tecnico, mo_valor, total, motivo_gratuidade,
                       data_orcamento, data_aprovado, data_pronto, data_retirada,
                       retirado_por, pdv, pago, wpp_enviado_em,
                       created_at, updated_at
                  FROM orcamentos
                 WHERE os_id = :os AND equip_idx = :idx
                 LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':os' => $osId, ':idx' => $equipIdx]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Busca todos os dados necessários para gerar o documento formal (PDF/impressão)
     * de um orçamento. Inclui OS, cliente e equipamento num único SELECT.
     *
     * @return array<string, mixed>|null
     */
    public function buscarParaDocumento(int $orcId): ?array
    {
        $sql = "SELECT
                       o.id, o.os_id, o.equip_idx, o.status, o.total, o.mo_valor,
                       o.obs_admin, o.motivo_gratuidade, o.data_orcamento, o.data_aprovado,
                       o.gerado_por, o.wpp_enviado_em, o.created_at,
                       os.nome_cliente, os.telefone AS os_telefone,
                       os.contato_nome, os.contato_telefone,
                       os.data_entrada, os.status AS os_status, os.cliente_id,
                       eq.nome AS equip_nome, eq.fabricante, eq.modelo, eq.serie,
                       eq.voltagem, eq.defeito, eq.obs_cli, eq.obs_int, eq.obs_recepcao, eq.status_equip,
                       eq.em_garantia, eq.tipo_garantia, eq.garantia_autorizacao,
                       c.nome       AS cli_nome,
                       c.nome_fantasia AS cli_fantasia,
                       c.cpf_cnpj   AS cli_cpf_cnpj,
                       c.email      AS cli_email,
                       c.telefone   AS cli_telefone,
                       c.celular    AS cli_celular,
                       c.endereco   AS cli_endereco,
                       c.numero     AS cli_numero,
                       c.complemento AS cli_complemento,
                       c.bairro     AS cli_bairro,
                       c.cidade     AS cli_cidade,
                       c.uf         AS cli_uf,
                       c.cep        AS cli_cep
                  FROM orcamentos o
                 INNER JOIN ordem_servico  os ON os.id = o.os_id
                  LEFT JOIN os_equipamento eq ON eq.os_id = o.os_id AND eq.ordem_idx = o.equip_idx
                  LEFT JOIN clientes       c  ON c.id = os.cliente_id
                 WHERE o.id = :id
                 LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':id' => $orcId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Busca dados completos de um orçamento com JOIN em OS e equipamento.
     * Usado para montar a mensagem de WhatsApp do orçamento.
     *
     * @return array<string, mixed>|null
     */
    public function buscarParaWhatsapp(int $orcId): ?array
    {
        // 10F-1: JOIN em clientes para obter nome_fantasia, celular, fone, telefone2.
        // Permite ClienteHelper::nomeParaMensagem() e telefoneParaWhatsapp() usarem
        // a prioridade correta de campos em vez de depender só do os.telefone.
        $sql = "SELECT o.id, o.os_id, o.equip_idx, o.tipo, o.status, o.total, o.mo_valor,
                       o.obs_admin, o.obs_tecnico, o.wpp_enviado_em, o.motivo_gratuidade,
                       os.nome_cliente, os.telefone,
                       eq.nome AS equip_nome, eq.fabricante, eq.modelo, eq.serie,
                       eq.voltagem, eq.obs_cli, eq.obs_int, eq.status_equip,
                       c.nome_fantasia, c.celular, c.fone, c.telefone2
                  FROM orcamentos o
                 INNER JOIN ordem_servico  os ON os.id = o.os_id
                  LEFT JOIN os_equipamento eq ON eq.os_id = o.os_id AND eq.ordem_idx = o.equip_idx
                  LEFT JOIN clientes       c  ON c.id = os.cliente_id
                 WHERE o.id = :id
                 LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':id' => $orcId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Lista todos os orçamentos de uma OS com itens aninhados.
     * @return array<int, array<string, mixed>>
     */
    public function listarPorOs(string $osId): array
    {
        $sql = "SELECT id, os_id, equip_idx, tipo, status, tecnico, gerado_por,
                       obs_admin, obs_tecnico, mo_valor, total, motivo_gratuidade,
                       data_orcamento, data_aprovado, data_pronto, data_retirada,
                       retirado_por, pdv, pago, wpp_enviado_em,
                       created_at, updated_at
                  FROM orcamentos
                 WHERE os_id = :os
                 ORDER BY equip_idx ASC
                 LIMIT 50";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':os' => $osId]);
        $orcamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($orcamentos)) return [];

        $ids = array_column($orcamentos, 'id');
        $itensPorOrc = $this->listarItensDeMuitos($ids);

        foreach ($orcamentos as &$orc) {
            $orc['itens']    = $itensPorOrc[(int) $orc['id']] ?? [];
            $orc['mo_valor'] = (float) $orc['mo_valor'];
            $orc['total']    = (float) $orc['total'];
            $orc['pago']     = (int) $orc['pago'];
        }
        unset($orc);

        return $orcamentos;
    }

    public function existeOrcamentoEnviadoHojeNaOs(string $osId): bool
    {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $inicio = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
        $fim = $inicio->modify('+1 day');

        $sql = "SELECT COUNT(*)
                  FROM orcamentos
                 WHERE os_id = :os
                   AND wpp_enviado_em IS NOT NULL
                   AND wpp_enviado_em >= :inicio
                   AND wpp_enviado_em < :fim";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':os'     => $osId,
            ':inicio' => $inicio->format('Y-m-d H:i:s'),
            ':fim'    => $fim->format('Y-m-d H:i:s'),
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function listarItens(int $orcamentoId, int $limit = 200): array
    {
        $sql = "SELECT id, orcamento_id, ordem_idx, descricao, codigo,
                       produto_id, tecnico_item_id,
                       qtd, unidade, valor_unit, valor_total,
                       em_estoque, data_pedido, obs,
                       fornecido_cliente, fornecido_em, fornecido_por,
                       motivo_remocao_cobranca, valor_original, subtotal_original
                  FROM orcamento_itens
                 WHERE orcamento_id = :id
                 ORDER BY ordem_idx ASC, id ASC
                 LIMIT :lim";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':id',  $orcamentoId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,       PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Informa se um item técnico deve ser tratado como peça fornecida pelo cliente
     * no equipamento informado. Usado para não bloquear montagem nem movimentar
     * estoque físico de algo que não saiu do estoque da loja.
     *
     * @param array<string, mixed> $item
     */
    public function tecnicoItemFornecidoCliente(string $osId, int $equipIdx, array $item): bool
    {
        $codigo = trim((string) ($item['codigo'] ?? ''));
        $descricao = trim(preg_replace('/\s+/', ' ', (string) ($item['descricao'] ?? '')) ?? '');

        if ($codigo === '' && $descricao === '') {
            return false;
        }

        $whereItem = [];
        $params = [
            ':os' => $osId,
            ':idx' => $equipIdx,
        ];

        if ($codigo !== '') {
            $whereItem[] = 'TRIM(oi.codigo) = :codigo';
            $params[':codigo'] = $codigo;
        }
        if ($descricao !== '') {
            $whereItem[] = 'TRIM(oi.descricao) = :descricao';
            $params[':descricao'] = $descricao;
        }

        $sql = "SELECT 1
                  FROM orcamentos o
            INNER JOIN orcamento_itens oi ON oi.orcamento_id = o.id
                 WHERE o.os_id = :os
                   AND o.equip_idx = :idx
                   AND oi.fornecido_cliente = 1
                   AND (" . implode(' OR ', $whereItem) . ")
                 LIMIT 1";

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Inbox de orçamentos aguardando aprovação (rascunho ou enviado ao cliente).
     * Aprovados, prontos, cancelados e retirados não aparecem.
     * @return array<int, array<string, mixed>>
     */
    public function listarPendentes(int $limit = 30): array
    {
        $sql = "SELECT
                  o.id, o.os_id, o.equip_idx, o.tipo, o.status, o.tecnico,
                  o.mo_valor, o.total, o.data_orcamento, o.data_aprovado,
                  o.data_pronto, o.pago, o.updated_at, o.wpp_enviado_em,
                  CASE
                      WHEN o.status = 'enviado' AND o.wpp_enviado_em IS NOT NULL
                      THEN DATEDIFF(NOW(), o.wpp_enviado_em)
                      ELSE NULL
                  END AS dias_aguardando,
                  os.nome_cliente, os.telefone,
                  eq.nome AS equip_nome
                  FROM orcamentos o
            INNER JOIN ordem_servico   os ON os.id = o.os_id
             LEFT JOIN os_equipamento  eq ON eq.os_id = o.os_id AND eq.ordem_idx = o.equip_idx
                 WHERE o.status IN ('rascunho', 'enviado')
              ORDER BY
                  CASE o.status
                      WHEN 'enviado'  THEN 1
                      WHEN 'rascunho' THEN 2
                      ELSE 3
                  END,
                  o.updated_at DESC
                 LIMIT :lim";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function marcarProntoSeNecessario(string $osId, int $equipIdx): void
    {
        $sql = "UPDATE orcamentos
                   SET data_pronto = CURDATE()
                 WHERE os_id = :os AND equip_idx = :idx
                   AND (data_pronto IS NULL OR data_pronto = '0000-00-00')
                 LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':os' => $osId, ':idx' => $equipIdx]);
    }

    /**
     * @param array<string, mixed> $cabecalho
     */
    public function criar(array $cabecalho): int
    {
        $sql = "INSERT INTO orcamentos
                  (os_id, equip_idx, tipo, status, tecnico, gerado_por,
                   obs_admin, mo_valor, total, data_orcamento)
                VALUES
                  (:os, :idx, :tipo, :status, :tecnico, :gerado_por,
                   :obs_admin, :mo_valor, :total, :data_orcamento)";
        $pdo = Database::pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':os'             => $cabecalho['os_id'],
            ':idx'            => (int) $cabecalho['equip_idx'],
            ':tipo'           => $cabecalho['tipo'],
            ':status'         => $cabecalho['status'] ?? 'rascunho',
            ':tecnico'        => $cabecalho['tecnico'] ?? '',
            ':gerado_por'     => $cabecalho['gerado_por'] ?? '',
            ':obs_admin'      => $cabecalho['obs_admin'] ?? '',
            ':mo_valor'       => (float) ($cabecalho['mo_valor'] ?? 0),
            ':total'          => (float) ($cabecalho['total'] ?? 0),
            ':data_orcamento' => $cabecalho['data_orcamento'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $cabecalho
     */
    public function atualizarCabecalho(int $id, array $cabecalho): void
    {
        $sql = "UPDATE orcamentos
                   SET tipo            = :tipo,
                       tecnico         = :tecnico,
                       gerado_por      = :gerado_por,
                       obs_admin       = :obs_admin,
                       mo_valor        = :mo_valor,
                       total           = :total,
                       data_orcamento  = :data_orcamento
                 WHERE id = :id";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':tipo'           => $cabecalho['tipo'],
            ':tecnico'        => $cabecalho['tecnico'] ?? '',
            ':gerado_por'     => $cabecalho['gerado_por'] ?? '',
            ':obs_admin'      => $cabecalho['obs_admin'] ?? '',
            ':mo_valor'       => (float) ($cabecalho['mo_valor'] ?? 0),
            ':total'          => (float) ($cabecalho['total'] ?? 0),
            ':data_orcamento' => $cabecalho['data_orcamento'] ?? null,
            ':id'             => $id,
        ]);
    }

    /**
     * Atualiza só os campos passados (PATCH). Whitelist + validação do status.
     * Lança InvalidArgumentException se status fornecido for inválido.
     *
     * @param  array<string, mixed> $campos
     * @return array<string, mixed> Campos efetivamente aplicados (após casting)
     */
    public function atualizarParcial(int $id, array $campos): array
    {
        $whitelist = [
            'status', 'tecnico', 'gerado_por', 'retirado_por', 'pdv',
            'obs_admin', 'data_orcamento', 'data_aprovado', 'data_pronto',
            'wpp_enviado_em',
            'motivo_gratuidade', // 9J-1: motivo quando total = 0
            // 'data_retirada' removido: preenchida apenas via fluxo oficial de retirada da OS.
            // 'pago' removido: reflexo exclusivo do financeiro; atualizado por retirarEquipamento/retirar/reabrir.
        ];

        $sets = [];
        $params = [];
        $aplicados = [];

        foreach ($campos as $key => $value) {
            if (!in_array($key, $whitelist, true)) continue;

            if ($key === 'status') {
                if (!in_array($value, self::STATUS_VALIDOS, true)) {
                    throw new InvalidArgumentException("Status inválido: {$value}");
                }
                $valFinal = (string) $value;
            } elseif ($key === 'motivo_gratuidade') {
                // 9J-1: aceita null/vazio (limpa) ou valores válidos do ENUM
                if ($value === '' || $value === null) {
                    $valFinal = null;
                } elseif (in_array($value, ['garantia_fabricante', 'cortesia'], true)) {
                    $valFinal = (string) $value;
                } else {
                    throw new InvalidArgumentException("motivo_gratuidade inválido: '{$value}'");
                }
            } elseif ($key === 'pago') {
                $valFinal = (int) $value === 1 ? 1 : 0;
            } elseif (in_array($key, ['data_orcamento', 'data_aprovado', 'data_pronto', 'data_retirada', 'wpp_enviado_em'], true)) {
                $valFinal = ($value === '' || $value === null) ? null : (string) $value;
            } else {
                $valFinal = (string) $value;
            }

            $placeholder = ':p_' . $key;
            $sets[]            = "{$key} = {$placeholder}";
            $params[$placeholder] = $valFinal;
            $aplicados[$key]   = $valFinal;
        }

        if (empty($sets)) return [];

        $params[':id'] = $id;
        $sql = "UPDATE orcamentos SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return $aplicados;
    }

    public function excluirItens(int $orcamentoId): void
    {
        $sql = "DELETE FROM orcamento_itens WHERE orcamento_id = :id";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':id' => $orcamentoId]);
    }

    /**
     * @param array<string, mixed> $item
     */
    public function inserirItem(int $orcamentoId, int $ordemIdx, array $item): int
    {
        $qtd       = (float) ($item['qtd'] ?? 1);
        $valorUnit = (float) ($item['valor_unit'] ?? 0);
        $valorTot  = isset($item['valor_total']) ? (float) $item['valor_total'] : $qtd * $valorUnit;

        $produtoId = isset($item['produto_id']) && (int) $item['produto_id'] > 0
            ? (int) $item['produto_id']
            : null;
        $tecnicoItemId = isset($item['tecnico_item_id']) && (int) $item['tecnico_item_id'] > 0
            ? (int) $item['tecnico_item_id']
            : null;

        $sql = "INSERT INTO orcamento_itens
                  (orcamento_id, ordem_idx, descricao, codigo, produto_id, tecnico_item_id, qtd, unidade,
                   valor_unit, valor_total, em_estoque, data_pedido, obs)
                VALUES
                  (:orc, :idx, :descricao, :codigo, :produto_id, :tecnico_item_id, :qtd, :unidade,
                   :vu, :vt, :em_estoque, :data_pedido, :obs)";
        $pdo = Database::pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':orc'         => $orcamentoId,
            ':idx'         => $ordemIdx,
            ':descricao'   => trim((string) ($item['descricao'] ?? '')),
            ':codigo'      => trim((string) ($item['codigo'] ?? '')),
            ':produto_id'  => $produtoId,
            ':tecnico_item_id' => $tecnicoItemId,
            ':qtd'         => $qtd,
            ':unidade'     => trim((string) ($item['unidade'] ?? 'un')),
            ':vu'          => $valorUnit,
            ':vt'          => $valorTot,
            ':em_estoque'  => (int) ($item['em_estoque'] ?? 0),
            ':data_pedido' => ($item['data_pedido'] ?? '') ?: null,
            ':obs'         => trim((string) ($item['obs'] ?? '')),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function atualizarVinculoItem(int $itemId, ?int $produtoId, int $tecnicoItemId): void
    {
        $produtoId = $produtoId !== null && $produtoId > 0 ? $produtoId : null;

        $stmt = Database::pdo()->prepare(
            "UPDATE orcamento_itens
                SET produto_id = :produto_id,
                    tecnico_item_id = :tecnico_item_id
              WHERE id = :id
              LIMIT 1"
        );
        $stmt->bindValue(':produto_id', $produtoId, $produtoId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':tecnico_item_id', $tecnicoItemId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Aplica pré-aprovação: tenta aprovar orçamento existente; se não existe, cria um stub aprovado.
     * Retorna o ID do orçamento afetado.
     */
    public function aprovarPreviamente(string $osId, int $equipIdx, string $dataAprovado): int
    {
        $pdo = Database::pdo();

        $up = $pdo->prepare(
            "UPDATE orcamentos
                SET status = 'aprovado', data_aprovado = :data
              WHERE os_id = :os AND equip_idx = :idx
                AND status NOT IN ('cancelado')  -- 9H-4: 'retirado' removido do modelo comercial
              LIMIT 1"
        );
        $up->execute([':data' => $dataAprovado, ':os' => $osId, ':idx' => $equipIdx]);

        if ($up->rowCount() > 0) {
            $sel = $pdo->prepare(
                "SELECT id FROM orcamentos
                  WHERE os_id = :os AND equip_idx = :idx
                  LIMIT 1"
            );
            $sel->execute([':os' => $osId, ':idx' => $equipIdx]);
            return (int) $sel->fetchColumn();
        }

        $ins = $pdo->prepare(
            "INSERT INTO orcamentos
               (os_id, equip_idx, tipo, status, gerado_por,
                data_orcamento, data_aprovado, mo_valor, total)
             VALUES
               (:os, :idx, 'maquina', 'aprovado', 'técnico',
                :data, :data, 0, 0)"
        );
        $ins->execute([':os' => $osId, ':idx' => $equipIdx, ':data' => $dataAprovado]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param  array<int, int> $orcamentoIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function listarItensDeMuitos(array $orcamentoIds): array
    {
        if (empty($orcamentoIds)) return [];

        $placeholders = implode(',', array_fill(0, count($orcamentoIds), '?'));
        $sql = "SELECT id, orcamento_id, ordem_idx, descricao, codigo,
                       produto_id, tecnico_item_id,
                       qtd, unidade, valor_unit, valor_total,
                       em_estoque, data_pedido, obs,
                       fornecido_cliente, fornecido_em, fornecido_por,
                       motivo_remocao_cobranca, valor_original, subtotal_original
                  FROM orcamento_itens
                 WHERE orcamento_id IN ({$placeholders})
                 ORDER BY orcamento_id, ordem_idx ASC
                 LIMIT 1000";

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(array_values($orcamentoIds));
        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $agrupados = [];
        foreach ($itens as $it) {
            $orcId = (int) $it['orcamento_id'];
            $it['qtd']         = (float) $it['qtd'];
            $it['valor_unit']  = (float) $it['valor_unit'];
            $it['valor_total'] = (float) $it['valor_total'];
            $it['em_estoque']  = (int) $it['em_estoque'];
            $it['fornecido_cliente'] = (int) ($it['fornecido_cliente'] ?? 0);
            $it['valor_original'] = $it['valor_original'] !== null ? (float) $it['valor_original'] : null;
            $it['subtotal_original'] = $it['subtotal_original'] !== null ? (float) $it['subtotal_original'] : null;
            $agrupados[$orcId][] = $it;
        }
        return $agrupados;
    }
}
