<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Helpers\ClienteHelper;
use App\Jobs\NotificarClienteJob;
use App\Repositories\NecessidadeCompraRepository;
use App\Repositories\NotificacaoTecnicoRepository;
use App\Repositories\OrcamentoRepository;
use App\Repositories\OrdemServicoRepository;
use App\Repositories\OsEquipamentoRepository;
use App\Repositories\ProdutoRepository;
use App\Repositories\TecnicoItemRepository;
use App\Services\TecnicoService;
use InvalidArgumentException;
use PDO;
use Throwable;

final class OrcamentoService
{
    public const TIPOS_VALIDOS = ['maquina', 'motor'];

    public function __construct(
        private readonly OrcamentoRepository         $repo            = new OrcamentoRepository(),
        private readonly OrdemServicoRepository      $osRepo          = new OrdemServicoRepository(),
        private readonly OsEquipamentoRepository     $equipRepo       = new OsEquipamentoRepository(),
        private readonly AuditoriaService            $auditoria       = new AuditoriaService(),
        private readonly TecnicoItemRepository       $itensRepo       = new TecnicoItemRepository(),
        private readonly NecessidadeCompraRepository $necessidadeRepo = new NecessidadeCompraRepository(),
        private readonly ProdutoRepository           $produtoRepo     = new ProdutoRepository(),
        private readonly TecnicoService              $tecnicoService  = new TecnicoService(),
        private readonly NotificacaoTecnicoRepository $notifRepo      = new NotificacaoTecnicoRepository(),
        private readonly TemplateService             $templateService = new TemplateService(),
    ) {}

    public function listarPorOs(string $osId): array
    {
        return $this->repo->listarPorOs($osId);
    }

    /**
     * Salva orçamento completo (cabeçalho + itens) em uma transação.
     * Cria se não existe, atualiza se já existe (UNIQUE os_id+equip_idx).
     *
     * @param  array<string, mixed>            $cabecalho
     * @param  array<int, array<string,mixed>> $itens
     */
    public function salvarCompleto(
        string $osId,
        int    $equipIdx,
        array  $cabecalho,
        array  $itens,
    ): int {
        if ($this->osRepo->buscarPorId($osId) === null) {
            throw new InvalidArgumentException("OS não encontrada: {$osId}");
        }
        if ($this->equipRepo->buscar($osId, $equipIdx) === null) {
            throw new InvalidArgumentException("Equipamento {$osId}#{$equipIdx} não encontrado");
        }

        $cab = $this->normalizarCabecalho($osId, $equipIdx, $cabecalho);

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $existente = $this->repo->buscarPorOsEquip($osId, $equipIdx);

            if ($existente === null) {
                // ── CRIAÇÃO: orçamento novo ─────────────────────────────────────
                $cab['status'] = 'rascunho';
                $orcId = $this->repo->criar($cab);
                $acao  = AuditoriaService::ACAO_INSERT;

                // Regra 1: nenhum item veio do frontend → importa do técnico.
                // Regra 8-11: a importação copia apenas descrição/código/qtd/valor_unit
                //             NÃO dá baixa em estoque, NÃO gera financeiro,
                //             NÃO aprova, NÃO muda status da OS.
                if (empty($itens)) {
                    $itens = $this->autoImportarItensTecnico($osId, $equipIdx);
                }
            } else {
                // ── ATUALIZAÇÃO: orçamento existente ───────────────────────────
                $orcId        = (int) $existente['id'];
                $statusAtual  = (string) ($existente['status'] ?? 'rascunho');

                // 9G-7: Proteger orçamentos em estados avançados contra edição de itens/valores.
                // Usa o status do banco — nunca confia no que o frontend envia.
                if (in_array($statusAtual, ['aprovado', 'cancelado'], true)) {
                    throw new \InvalidArgumentException(
                        'Este orçamento está aprovado, cancelado ou finalizado e não pode ter itens/valores alterados.'
                    );
                }

                $this->repo->atualizarCabecalho($orcId, $cab);
                $acao = AuditoriaService::ACAO_UPDATE;

                // Regra 2: rascunho + frontend não enviou itens → verificar se o
                //          banco também está vazio; se estiver, importa do técnico.
                // Regra 3: se o banco já tem itens, NÃO importa (preserva o que
                //          a recepção já editou).
                // Regra 5: status diferente de rascunho → nunca importa.
                if ($statusAtual === 'rascunho' && empty($itens)) {
                    $itensNoBanco = $this->repo->listarItens($orcId);
                    if (empty($itensNoBanco)) {
                        // Banco vazio + rascunho + frontend vazio → importa do técnico.
                        // Isso cobre o caso "recepção abre rascunho vazio e clica Salvar".
                        $itens = $this->autoImportarItensTecnico($osId, $equipIdx);
                    } else {
                        // Banco JÁ TEM itens e o frontend não enviou nenhum.
                        // Proteção contra exclusão acidental por bug de serialização
                        // ou requisição incompleta: mantém os itens existentes intactos.
                        // Para remover TODOS os itens intencionalmente, o frontend
                        // deve enviar ao menos um item válido e depois apagá-lo via UI,
                        // ou enviar o campo explícito `limpar_itens=true` (futuro).
                        $itens = $itensNoBanco;
                    }
                }
                // Regra 4: se frontend enviou itens (mesmo que parciais), usa
                //          exatamente o que veio — preserva preços e quantidades
                //          já ajustados pela recepção.
            }

            $itensSalvos = [];
            $this->repo->excluirItens($orcId);
            foreach (array_values($itens) as $idx => $item) {
                if (!is_array($item)) continue;
                $desc = trim((string) ($item['descricao'] ?? ''));
                if ($desc === '') continue;
                $itemId = $this->repo->inserirItem($orcId, $idx, $item);
                $item['id'] = $itemId;
                $item['ordem_idx'] = $idx;
                $itensSalvos[] = $item;
            }

            $syncResumo = $this->sincronizarItensTecnicosComOrcamento($osId, $equipIdx, $itensSalvos);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }


        $this->auditoria->registrar('orcamentos', (string) $orcId, $acao, [
            'os_id'     => $osId,
            'equip_idx' => $equipIdx,
            'total'     => $cab['total'],
            'mo_valor'  => $cab['mo_valor'],
            'tecnico'   => $cab['tecnico'],
            'qtd_itens' => count($itens),
        ]);

        if (!empty($syncResumo)) {
            $this->auditoria->registrar('tecnico_itens', $osId . '#' . $equipIdx, AuditoriaService::ACAO_UPDATE, [
                'mensagem' => 'Itens técnicos sincronizados a partir do orçamento.',
                'orcamento_id' => $orcId,
                'criados' => (int) ($syncResumo['criados'] ?? 0),
                'atualizados' => (int) ($syncResumo['atualizados'] ?? 0),
                'desativados' => (int) ($syncResumo['desativados'] ?? 0),
            ]);
        }

        return $orcId;
    }

    /**
     * Atualização parcial (PATCH). Auditoria registra os campos efetivamente aplicados.
     *
     * @param  array<string, mixed> $campos
     * @return array<string, mixed>
     */
    public function atualizarCampos(int $id, array $campos): array
    {
        $orcAntes = $this->repo->buscarPorId($id);
        if ($orcAntes === null) {
            throw new InvalidArgumentException("Orçamento não encontrado: {$id}");
        }

        // Bloqueia transição manual para 'retirado' — deve ocorrer apenas via fluxo de retirada da OS.
        if (isset($campos['status']) && $campos['status'] === 'retirado') {
            throw new InvalidArgumentException(
                "Status 'retirado' não pode ser definido manualmente no orçamento. " .
                "Use o fluxo oficial de retirada da OS."
            );
        }
        // 9H-1: Valida a transição de status contra a matriz de transições permitidas.
        // Usa o status atual do banco — nunca confia no que o frontend envia.
        if (isset($campos['status'])) {
            $statusAtualBanco = (string) ($orcAntes['status'] ?? 'rascunho');
            $novoStatus       = (string) $campos['status'];
            if ($novoStatus !== $statusAtualBanco) {
                $this->validarTransicaoStatusOrcamento($statusAtualBanco, $novoStatus);
            }
        }
        // 9J-1: motivo_gratuidade — validação preventiva antes de chegar ao repositório.
        // Valores válidos: null (limpa), 'garantia_fabricante', 'cortesia'.
        if (array_key_exists('motivo_gratuidade', $campos)) {
            $mv = $campos['motivo_gratuidade'];
            if ($mv !== null && $mv !== '' && !in_array($mv, ['garantia_fabricante', 'cortesia'], true)) {
                throw new InvalidArgumentException(
                    "Valor inválido para motivo_gratuidade: '{$mv}'. Use 'garantia_fabricante' ou 'cortesia'."
                );
            }
        }

        // 'pago' é reflexo exclusivo do sistema financeiro.
        // Qualquer atualização manual (pago=0 ou pago=1) é bloqueada aqui.
        // Fluxos oficiais que alteram 'pago': retirarEquipamento(), retirar(), reabrir()
        // — todos usam UPDATE direto, fora do atualizarCampos().
        if (array_key_exists('pago', $campos)) {
            throw new InvalidArgumentException(
                "O campo 'pago' não pode ser alterado manualmente. " .
                "Ele é atualizado automaticamente pelo sistema financeiro."
            );
        }

        $aplicados = $this->repo->atualizarParcial($id, $campos);
        if (!empty($aplicados)) {
            $this->auditoria->registrar(
                'orcamentos',
                (string) $id,
                AuditoriaService::ACAO_UPDATE,
                $aplicados,
            );
        }

        // Quando o orçamento muda para "aprovado", dispara geração de necessidades,
        // tenta promover o equipamento para montagem e notifica o técnico.
        if (
            isset($aplicados['status']) && $aplicados['status'] === 'aprovado'
            && ($orcAntes['status'] ?? '') !== 'aprovado'
        ) {
            $this->gerarNecessidadesDaOs((string) $orcAntes['os_id']);
            $statusEquip = $this->tentarPromoverMontagem((string) $orcAntes['os_id'], (int) $orcAntes['equip_idx']);
            $this->notificarTecnico(
                (string) $orcAntes['os_id'],
                (int) $orcAntes['equip_idx'],
                'aprovado',
                $statusEquip === 'montagem'
                    ? 'Orçamento aprovado — iniciar montagem/conserto.'
                    : 'Orçamento aprovado — verificar estoque/peças antes de montar.'
            );
            $this->notificarClienteAprovacao((int) $id, $orcAntes);
        }

        // Quando o orçamento muda para "cancelado", reflete imediatamente no equipamento
        // técnico. O destino físico posterior pode ser remontagem, devolução desmontada
        // ou descarte, mas o técnico não deve continuar vendo o equipamento em andamento.
        if (
            isset($aplicados['status']) && $aplicados['status'] === 'cancelado'
            && ($orcAntes['status'] ?? '') !== 'cancelado'
        ) {
            $osId     = (string) $orcAntes['os_id'];
            $equipIdx = (int) $orcAntes['equip_idx'];
            $this->sincronizarEquipamentoCancelado($osId, $equipIdx, (int) $id);
        }

        return $aplicados;
    }

    /**
     * Registra retirada/devolução sem custo para orçamento cancelado.
     *
     * Não gera financeiro, NF, WhatsApp, baixa de estoque nem altera outros equipamentos.
     *
     * @return array<string, mixed>
     */
    public function registrarRetiradaSemCusto(int $orcId, int $usuarioId): array
    {
        if ($orcId <= 0) {
            throw new InvalidArgumentException('Orçamento inválido.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT o.id, o.os_id, o.equip_idx, o.status AS orc_status,
                        eq.status_equip, eq.nome AS equip_nome,
                        eq.descarte_autorizado_em,
                        os.status AS os_status
                   FROM orcamentos o
             INNER JOIN os_equipamento eq ON eq.os_id = o.os_id AND eq.ordem_idx = o.equip_idx
             INNER JOIN ordem_servico os ON os.id = o.os_id
                  WHERE o.id = ?
                  LIMIT 1
                    FOR UPDATE"
            );
            $stmt->execute([$orcId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new InvalidArgumentException('Orçamento não encontrado.');
            }

            $osId = (string) $row['os_id'];
            $equipIdx = (int) $row['equip_idx'];
            $orcStatus = (string) $row['orc_status'];
            $statusAnterior = (string) $row['status_equip'];
            $osStatusAnterior = (string) $row['os_status'];

            if ($orcStatus !== 'cancelado') {
                throw new InvalidArgumentException('Retirada sem custo permitida apenas para orçamento cancelado.');
            }
            if (in_array($osStatusAnterior, ['retirado', 'descartado'], true)) {
                throw new InvalidArgumentException("OS #{$osId} já está encerrada.");
            }
            if (in_array($statusAnterior, ['retirado', 'devolvido', 'descartado'], true)) {
                throw new InvalidArgumentException("Equipamento já está finalizado com status '{$statusAnterior}'.");
            }
            if (!empty($row['descarte_autorizado_em'])) {
                throw new InvalidArgumentException('Este equipamento possui descarte autorizado. Resolva o descarte antes da retirada.');
            }

            $novoStatus = 'devolvido';
            $pdo->prepare(
                "UPDATE os_equipamento
                    SET status_equip = ?,
                        status_equip_em = NOW(),
                        devolucao_em = NOW(),
                        devolucao_uid = ?
                  WHERE os_id = ?
                    AND ordem_idx = ?
                  LIMIT 1"
            )->execute([$novoStatus, $usuarioId > 0 ? $usuarioId : null, $osId, $equipIdx]);

            $osStatusFinal = $this->finalizarOsSeTodosEquipamentosTerminais($pdo, $osId, $osStatusAnterior);

            $this->auditoria->registrar(
                'os_equipamento',
                $osId . '#' . $equipIdx,
                'RETIRADA_SEM_CUSTO',
                [
                    'mensagem' => 'Retirada sem custo registrada para equipamento com orçamento cancelado. Equipamento retirado desmontado/sem conserto.',
                    'usuario_id' => $usuarioId,
                    'os_id' => $osId,
                    'equip_idx' => $equipIdx,
                    'orcamento_id' => $orcId,
                    'status_anterior' => $statusAnterior,
                    'status_novo' => $novoStatus,
                    'os_status_anterior' => $osStatusAnterior,
                    'os_status_novo' => $osStatusFinal,
                    'sem_financeiro' => true,
                    'sem_nf' => true,
                    'sem_estoque' => true,
                    'sem_whatsapp' => true,
                ]
            );

            $this->notifRepo->marcarLidasPorOsEquipTipo($osId, $equipIdx, 'cancelado');

            $pdo->commit();

            return [
                'os_id' => $osId,
                'equip_idx' => $equipIdx,
                'orcamento_id' => $orcId,
                'status_anterior' => $statusAnterior,
                'status_equipamento' => $novoStatus,
                'status_os' => $osStatusFinal,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reabre um orçamento cancelado por engano em modo seguro.
     *
     * Não reativa financeiro, estoque, NF, PDV nem WhatsApp. Também não aprova
     * o orçamento e não promove o equipamento para montagem.
     *
     * @return array<string, mixed>
     */
    public function reverterCancelamento(int $orcId, string $motivo, int $usuarioId): array
    {
        $motivo = trim($motivo);
        if ($orcId <= 0) {
            throw new InvalidArgumentException('Orçamento inválido.');
        }
        if ($motivo === '') {
            throw new InvalidArgumentException('Informe o motivo da reversão.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT o.id, o.os_id, o.equip_idx, o.status, o.wpp_enviado_em,
                        eq.status_equip, eq.diagnostico_concluido_em,
                        os.status AS os_status
                   FROM orcamentos o
             INNER JOIN os_equipamento eq ON eq.os_id = o.os_id AND eq.ordem_idx = o.equip_idx
             INNER JOIN ordem_servico os ON os.id = o.os_id
                  WHERE o.id = ?
                  LIMIT 1
                    FOR UPDATE"
            );
            $stmt->execute([$orcId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new InvalidArgumentException('Orçamento não encontrado.');
            }

            $osId = (string) $row['os_id'];
            $equipIdx = (int) $row['equip_idx'];
            $orcStatusAnterior = (string) $row['status'];
            $equipStatusAnterior = (string) $row['status_equip'];
            $osStatusAnterior = (string) $row['os_status'];

            if ($orcStatusAnterior !== 'cancelado') {
                throw new InvalidArgumentException('A reversão só é permitida para orçamento cancelado.');
            }

            if (in_array($equipStatusAnterior, ['retirado', 'devolvido', 'descartado'], true)) {
                throw new InvalidArgumentException(
                    'Equipamento já finalizado fisicamente. Reversão de cancelamento não permitida neste estado.'
                );
            }

            $novoStatusOrc = !empty($row['wpp_enviado_em']) ? 'enviado' : 'rascunho';
            $novoStatusEquip = 'andamento';

            $upOrc = $pdo->prepare("UPDATE orcamentos SET status = ? WHERE id = ? LIMIT 1");
            $upOrc->execute([$novoStatusOrc, $orcId]);

            if ($equipStatusAnterior !== $novoStatusEquip) {
                $upEquip = $pdo->prepare(
                    "UPDATE os_equipamento
                        SET status_equip = ?,
                            status_equip_em = NOW()
                      WHERE os_id = ?
                        AND ordem_idx = ?
                      LIMIT 1"
                );
                $upEquip->execute([$novoStatusEquip, $osId, $equipIdx]);
            }

            $stStatus = $pdo->prepare(
                "SELECT status_equip
                   FROM os_equipamento
                  WHERE os_id = ?
                  ORDER BY ordem_idx ASC"
            );
            $stStatus->execute([$osId]);
            $statusList = array_map('strval', $stStatus->fetchAll(PDO::FETCH_COLUMN));
            $novoStatusOs = TecnicoService::derivarStatusMacro($statusList);

            if (
                $novoStatusOs !== $osStatusAnterior
                && !in_array($osStatusAnterior, ['retirado', 'descartado'], true)
            ) {
                $this->osRepo->atualizarStatus($osId, $novoStatusOs);
            } else {
                $novoStatusOs = $osStatusAnterior;
            }

            $auditoria = [
                'origem' => 'reverter_cancelamento',
                'mensagem' => 'Cancelamento revertido pelo administrador. Motivo: ' . $motivo,
                'usuario_id' => $usuarioId,
                'os_id' => $osId,
                'equip_idx' => $equipIdx,
                'orcamento_id' => $orcId,
                'motivo' => $motivo,
                'status_anterior' => [
                    'orcamento' => $orcStatusAnterior,
                    'equipamento' => $equipStatusAnterior,
                    'os' => $osStatusAnterior,
                ],
                'status_novo' => [
                    'orcamento' => $novoStatusOrc,
                    'equipamento' => $novoStatusEquip,
                    'os' => $novoStatusOs,
                ],
                'restauracao' => 'modo_seguro_sem_status_anterior_confiavel',
            ];

            $this->auditoria->registrar(
                'orcamentos',
                (string) $orcId,
                'REVERTER_CANCELAMENTO',
                $auditoria
            );

            $this->notifRepo->marcarLidasPorOsEquipTipo($osId, $equipIdx, 'cancelado');
            $this->notifRepo->criar(
                $osId,
                $equipIdx,
                'info',
                "Cancelamento revertido — revisar orçamento da OS #{$osId}.",
                'recepcao'
            );

            $pdo->commit();

            return [
                'os_id' => $osId,
                'equip_idx' => $equipIdx,
                'orcamento_id' => $orcId,
                'status_orcamento' => $novoStatusOrc,
                'status_equipamento' => $novoStatusEquip,
                'status_os' => $novoStatusOs,
                'aviso' => 'Não foi encontrado status anterior confiável. O equipamento foi reaberto em modo seguro para nova análise/orçamento.',
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Marca peças do orçamento como fornecidas pelo cliente, removendo apenas
     * a cobrança desses itens. Não altera M.O., dados antigos em massa, estoque,
     * financeiro, PDV, NF, vencimentos ou pagamentos.
     *
     * @param array<int, int> $itemIds
     * @return array<string, mixed>
     */
    public function marcarPecasFornecidasCliente(
        int $orcId,
        array $itemIds,
        string $motivo,
        bool $liberarMontagem,
        int $usuarioId
    ): array {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn(int $id): bool => $id > 0)));
        if ($orcId <= 0 || empty($itemIds)) {
            throw new InvalidArgumentException('Informe o orçamento e ao menos uma peça.');
        }

        $motivo = trim($motivo);
        if ($motivo === '') {
            $motivo = 'Cliente trouxe as peças';
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stOrc = $pdo->prepare(
                "SELECT o.id, o.os_id, o.equip_idx, o.status, o.mo_valor, o.total, o.data_aprovado,
                        eq.status_equip, eq.cx, eq.nome AS equip_nome
                   FROM orcamentos o
             INNER JOIN os_equipamento eq ON eq.os_id = o.os_id AND eq.ordem_idx = o.equip_idx
                  WHERE o.id = ?
                  LIMIT 1
                    FOR UPDATE"
            );
            $stOrc->execute([$orcId]);
            $orc = $stOrc->fetch(PDO::FETCH_ASSOC);
            if (!$orc) {
                throw new InvalidArgumentException('Orçamento não encontrado.');
            }

            $statusOrc = (string) ($orc['status'] ?? '');
            $statusEquip = (string) ($orc['status_equip'] ?? '');
            if ($statusOrc === 'cancelado') {
                throw new InvalidArgumentException('Orçamento cancelado não pode receber peças fornecidas pelo cliente.');
            }
            if (in_array($statusEquip, ['montagem', 'pronto', 'retirado', 'devolvido', 'descartado', 'cancelado'], true)) {
                throw new InvalidArgumentException("Equipamento em status '{$statusEquip}' não permite esta alteração.");
            }

            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
            $stItens = $pdo->prepare(
                "SELECT *
                   FROM orcamento_itens
                  WHERE orcamento_id = ?
                    AND id IN ({$placeholders})
                  ORDER BY ordem_idx ASC, id ASC
                    FOR UPDATE"
            );
            $stItens->execute(array_merge([$orcId], $itemIds));
            $itens = $stItens->fetchAll(PDO::FETCH_ASSOC);
            if (count($itens) !== count($itemIds)) {
                throw new InvalidArgumentException('Um ou mais itens informados não pertencem a este orçamento.');
            }

            $itensNovos = [];
            foreach ($itens as $item) {
                if ((int) ($item['fornecido_cliente'] ?? 0) === 1) {
                    continue;
                }
                if ($this->isItemMaoDeObraPrincipal($item)) {
                    throw new InvalidArgumentException('Mão de obra não pode ser marcada como peça fornecida pelo cliente.');
                }
                $itensNovos[] = $item;
            }
            if (empty($itensNovos)) {
                throw new InvalidArgumentException('Os itens selecionados já estavam marcados como fornecidos pelo cliente.');
            }

            $idsNovos = array_map(static fn(array $item): int => (int) $item['id'], $itensNovos);
            $phUpdate = implode(',', array_fill(0, count($idsNovos), '?'));
            $paramsUpdate = array_merge(
                [
                    $usuarioId > 0 ? $usuarioId : null,
                    $motivo,
                    $orcId,
                ],
                $idsNovos
            );
            $upItens = $pdo->prepare(
                "UPDATE orcamento_itens
                    SET fornecido_cliente = 1,
                        fornecido_em = NOW(),
                        fornecido_por = ?,
                        motivo_remocao_cobranca = ?,
                        valor_original = COALESCE(valor_original, valor_unit),
                        subtotal_original = COALESCE(subtotal_original, valor_total),
                        valor_unit = 0,
                        valor_total = 0
                  WHERE orcamento_id = ?
                    AND id IN ({$phUpdate})"
            );
            $upItens->execute($paramsUpdate);

            $stSubtotal = $pdo->prepare(
                "SELECT COALESCE(SUM(valor_total), 0)
                   FROM orcamento_itens
                  WHERE orcamento_id = ?"
            );
            $stSubtotal->execute([$orcId]);
            $subtotalPecas = (float) $stSubtotal->fetchColumn();
            $totalAnterior = (float) ($orc['total'] ?? 0);
            $totalAtual = round($subtotalPecas + (float) ($orc['mo_valor'] ?? 0), 2);

            $novoStatusOrc = $statusOrc === 'aprovado' ? 'aprovado' : 'aprovado';
            $dataAprovadoExpr = empty($orc['data_aprovado']) ? 'CURDATE()' : 'data_aprovado';
            $pdo->prepare(
                "UPDATE orcamentos
                    SET status = ?,
                        data_aprovado = {$dataAprovadoExpr},
                        total = ?
                  WHERE id = ?
                  LIMIT 1"
            )->execute([$novoStatusOrc, $totalAtual, $orcId]);

            $osId = (string) $orc['os_id'];
            $equipIdx = (int) $orc['equip_idx'];
            $necessidadesCanceladas = $this->necessidadeRepo
                ->cancelarRelacionadasAoFornecimentoCliente($osId, $equipIdx, $itensNovos);

            $novoStatusEquip = $statusEquip;
            if ($liberarMontagem) {
                if (trim((string) ($orc['cx'] ?? '')) === '') {
                    throw new InvalidArgumentException('Informe o número da Caixa (CX) antes de liberar a montagem.');
                }

                $upEq = $pdo->prepare(
                    "UPDATE os_equipamento
                        SET status_equip = 'montagem',
                            status_equip_em = NOW()
                      WHERE os_id = ?
                        AND ordem_idx = ?
                        AND status_equip IN ('aberta','andamento')
                      LIMIT 1"
                );
                $upEq->execute([$osId, $equipIdx]);
                if ($upEq->rowCount() > 0) {
                    $novoStatusEquip = 'montagem';
                    $pdo->prepare(
                        "UPDATE ordem_servico
                            SET status = 'andamento'
                          WHERE id = ?
                            AND status NOT IN ('retirado','cancelado','descartado')
                          LIMIT 1"
                    )->execute([$osId]);
                }
            }

            $notifId = $this->notifRepo->criar(
                $osId,
                $equipIdx,
                'info',
                'Peças fornecidas pelo cliente — iniciar montagem/conserto.',
                'oficina'
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->auditoria->registrar(
            'orcamentos',
            (string) $orcId,
            AuditoriaService::ACAO_UPDATE,
            [
                'pecas_fornecidas_cliente' => $idsNovos,
                'os_id' => $osId,
                'equip_idx' => $equipIdx,
                'total_anterior' => $totalAnterior,
                'total_atual' => $totalAtual,
                'necessidades_canceladas' => $necessidadesCanceladas,
                'status_equip' => $novoStatusEquip,
            ]
        );

        return [
            'orcamento_id' => $orcId,
            'os_id' => $osId,
            'equip_idx' => $equipIdx,
            'itens_marcados' => count($idsNovos),
            'total_anterior' => $totalAnterior,
            'total_atual' => $totalAtual,
            'necessidades_canceladas' => $necessidadesCanceladas,
            'status_equip' => $novoStatusEquip,
            'notificacao_id' => $notifId,
        ];
    }

    /**
     * Pré-aprovação iniciada pelo técnico (pula etapa administrativa).
     */
    public function preAprovar(string $osId, int $equipIdx): int
    {
        if ($this->equipRepo->buscar($osId, $equipIdx) === null) {
            throw new InvalidArgumentException("Equipamento {$osId}#{$equipIdx} não encontrado");
        }

        // Captura status atual antes da pré-aprovação para detectar transição real
        // e evitar notificações duplicadas caso o método seja chamado novamente.
        $orcAntes    = $this->repo->buscarPorOsEquip($osId, $equipIdx);
        $statusAntes = $orcAntes !== null ? (string) ($orcAntes['status'] ?? '') : '';

        $hoje = date('Y-m-d');
        $orcId = $this->repo->aprovarPreviamente($osId, $equipIdx, $hoje);

        $this->auditoria->registrar(
            'orcamentos',
            (string) $orcId,
            AuditoriaService::ACAO_UPDATE,
            [
                'pre_aprovacao'  => true,
                'os_id'          => $osId,
                'equip_idx'      => $equipIdx,
                'data_aprovado'  => $hoje,
            ],
        );

        $this->gerarNecessidadesDaOs($osId);
        $statusEquip = $this->tentarPromoverMontagem($osId, $equipIdx);

        if ($statusAntes !== 'aprovado') {
            $this->notificarTecnico(
                $osId,
                $equipIdx,
                'aprovado',
                $statusEquip === 'montagem'
                    ? 'Orçamento aprovado — iniciar montagem/conserto.'
                    : 'Orçamento aprovado — verificar estoque/peças antes de montar.'
            );
        }

        return $orcId;
    }

    /**
     * Gera os dados necessários para enviar o orçamento via WhatsApp.
     * Atualiza `wpp_enviado_em` ao confirmar o envio.
     *
     * @return array{mensagem:string, telefone:string, wpp_url:string}
     * @throws InvalidArgumentException se orçamento não encontrado ou sem telefone
     */
    public function gerarDadosWhatsapp(int $orcId, bool $registrarEnvio = true): array
    {
        $dados = $this->repo->buscarParaWhatsapp($orcId);
        if ($dados === null) {
            throw new InvalidArgumentException("Orçamento não encontrado: {$orcId}");
        }

        // 10A-1: guard de status — bloqueia envio em estados comerciais/físicos avançados.
        // A UI já oculta o botão, mas esta validação protege chamadas diretas à API.
        $statusOrc = (string) ($dados['status'] ?? '');
        if (!in_array($statusOrc, ['rascunho', 'enviado'], true)) {
            throw new InvalidArgumentException(
                "Não é possível enviar WhatsApp para um orçamento com status '{$statusOrc}'. " .
                'Apenas orçamentos em rascunho ou enviado podem gerar link de WhatsApp.'
            );
        }
        $statusEquip = (string) ($dados['status_equip'] ?? '');
        if (in_array($statusEquip, ['retirado', 'devolvido', 'descartado'], true)) {
            throw new InvalidArgumentException(
                "Não é possível enviar WhatsApp: o equipamento já está '{$statusEquip}'. " .
                'O fluxo deste equipamento está encerrado.'
            );
        }

        // 10F-1: prioridade contato_telefone da OS → celular → telefone2 → fone → telefone.
        $telefone = ClienteHelper::telefoneParaWhatsapp($dados, (string) ($dados['contato_telefone'] ?? ''));
        if ($telefone === null) {
            throw new InvalidArgumentException(
                'Não foi encontrado WhatsApp válido para este cliente. ' .
                'Atualize o cadastro ou localize o contato manualmente no WhatsApp.'
            );
        }

        $itens = $this->repo->listarItens($orcId);
        $jaEnviadoHojeNaOs = $this->repo->existeOrcamentoEnviadoHojeNaOs((string) ($dados['os_id'] ?? ''));
        $mensagem = $this->montarMensagemWhatsappOrcamento($dados, $itens, !$jaEnviadoHojeNaOs);
        $wppUrl = str_contains($telefone, '@')
            ? 'https://web.whatsapp.com/send?text=' . rawurlencode($mensagem)
            : 'https://wa.me/' . $telefone . '?text=' . rawurlencode($mensagem);

        if ($registrarEnvio) {
            // Registra data/hora apenas quando o usuário confirma o envio.
            // Se o orçamento ainda estiver em rascunho, avança para 'enviado' automaticamente.
            // Status já avançados (aprovado, cancelado) não são rebaixados.
            $atualizacoes = ['wpp_enviado_em' => date('Y-m-d H:i:s')];
            if ($dados['status'] === 'rascunho') {
                $atualizacoes['status'] = 'enviado';
            }
            $this->repo->atualizarParcial($orcId, $atualizacoes);
        }

        return [
            'mensagem' => $mensagem,
            'telefone' => $telefone,
            'wpp_url'  => $wppUrl,
        ];
    }

    /**
     * @param array<string, mixed> $dados
     * @param array<int, array<string, mixed>> $itens
     */
    private function montarMensagemWhatsappOrcamento(array $dados, array $itens, bool $incluirSaudacao = true): string
    {
        // 10F-1: usa ClienteHelper para preferir nome_fantasia quando disponível.
        $nomeCliente = ClienteHelper::nomeParaMensagem($dados);
        if ($nomeCliente === '') {
            $nomeCliente = 'cliente';
        } else {
            $nomeCliente = $this->normalizarNomeExibicao($nomeCliente);
        }

        $remetente = $this->normalizarNomeExibicao($this->primeiroNome((string) (Auth::user()['nome'] ?? '')));
        if ($remetente === '') {
            $remetente = 'Equipe Multimáquinas';
        }

        $equipamentoNome = $this->formatarEquipamentoWhatsapp($dados);

        $linhasItens = [];
        foreach ($itens as $item) {
            $desc = trim((string) ($item['descricao'] ?? ''));
            if ($desc === '') {
                continue;
            }

            $qtd = (float) ($item['qtd'] ?? 1);
            $unidade = trim((string) ($item['unidade'] ?? 'un')) ?: 'un';
            $qtdStr = ($qtd == (int) $qtd) ? (string) (int) $qtd : rtrim(rtrim(number_format($qtd, 3, '.', ''), '0'), '.');
            $linhasItens[] = '• ' . $this->formatarDescricaoItemWhatsapp($desc) . " ({$qtdStr} {$unidade})";
        }

        if (empty($linhasItens)) {
            $linhasItens[] = '(sem itens cadastrados — revise o orçamento)';
        }

        $total = (float) ($dados['total'] ?? 0);
        $totalBrl = 'R$ ' . number_format($total, 2, ',', '.');
        $equipamentoNumero = ((int) ($dados['equip_idx'] ?? 0)) + 1;
        $observacao = $this->normalizarObservacaoCliente($dados);
        $diagnosticoBloco = '';
        if ($observacao !== '') {
            $diagnosticoBloco = "\n*Diagnóstico:*\n" . $observacao;
        }
        $prazoDiasUteis = $this->calcularPrazoWhatsapp($dados, $itens);

        // 10A-2: rótulo legível do motivo de gratuidade.
        $motivoGratuidade = (string) ($dados['motivo_gratuidade'] ?? '');
        $motivoGratuidadeLabel = match ($motivoGratuidade) {
            'garantia_fabricante' => 'garantia de fabricante',
            'cortesia'            => 'cortesia',
            default               => '',
        };

        $vars = [
            'saudacao'                => $this->saudacaoPorHorario(),
            'cliente_nome'            => $nomeCliente,
            'remetente_nome'          => $remetente,
            'os_id'                   => (string) ($dados['os_id'] ?? ''),
            'equipamento_numero'      => (string) $equipamentoNumero,
            'equipamento_nome'        => $equipamentoNome,
            'itens_lista'             => implode("\n", $linhasItens),
            'total_brl'               => $totalBrl,
            'diagnostico_bloco'       => $diagnosticoBloco,
            'prazo_dias_uteis'        => (string) $prazoDiasUteis,
            'motivo_gratuidade'       => $motivoGratuidade,
            'motivo_gratuidade_label' => $motivoGratuidadeLabel,
        ];

        // 10A-2: escolha do template por motivo de gratuidade (total=0 + motivo preenchido).
        // Caso contrário: lembrete para reenvio, orcamento_os para envio inicial.
        if ($total <= 0 && $motivoGratuidade === 'garantia_fabricante') {
            $template = $incluirSaudacao
                ? 'orcamento_os_gratuidade_fabricante'
                : 'orcamento_os_gratuidade_fabricante_sem_saudacao';
        } elseif ($total <= 0 && $motivoGratuidade === 'cortesia') {
            $template = $incluirSaudacao
                ? 'orcamento_os_gratuidade_cortesia'
                : 'orcamento_os_gratuidade_cortesia_sem_saudacao';
        } elseif (((string) ($dados['status'] ?? '')) === 'enviado') {
            $template = $incluirSaudacao
                ? 'orcamento_os_lembrete'
                : 'orcamento_os_lembrete_sem_saudacao';
        } else {
            $template = $incluirSaudacao ? 'orcamento_os' : 'orcamento_os_sem_saudacao';
        }

        return $this->templateService->render($template, $vars);
    }

    /**
     * @param array<string, mixed> $dados
     */
    private function normalizarObservacaoCliente(array $dados): string
    {
        $observacao = trim((string) ($dados['obs_cli'] ?? ''));
        if ($observacao === '') {
            $observacao = trim((string) ($dados['obs_tecnico'] ?? ''));
        }
        if ($observacao === '') {
            $observacao = trim((string) ($dados['obs_int'] ?? ''));
        }

        return $observacao;
    }

    /**
     * @param array<string, mixed> $dados
     */
    private function formatarEquipamentoWhatsapp(array $dados): string
    {
        $nome = trim((string) ($dados['equip_nome'] ?? ''));
        $fabricante = trim((string) ($dados['fabricante'] ?? ''));
        $modelo = trim((string) ($dados['modelo'] ?? ''));
        $voltagem = trim((string) ($dados['voltagem'] ?? ''));

        $linha = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
            $nome,
            $fabricante,
            $modelo,
        ], static fn($valor) => trim((string) $valor) !== ''))) ?? '');

        if ($linha === '') {
            $linha = 'Equipamento não informado';
        }
        if ($voltagem !== '') {
            $linha .= ' - ' . $voltagem;
        }

        return trim(preg_replace('/\s+/', ' ', $linha) ?? $linha);
    }

    private function formatarDescricaoItemWhatsapp(string $descricao): string
    {
        $descricao = trim(preg_replace('/\s+/', ' ', $descricao) ?? $descricao);
        if ($descricao === '') {
            return $descricao;
        }

        $upper = function_exists('mb_strtoupper')
            ? mb_strtoupper($descricao, 'UTF-8')
            : strtoupper($descricao);

        if (str_contains($upper, 'ROLAMENTO') && preg_match('/\b(\d{4,5})\b/', $upper, $m)) {
            return 'ROLAMENTO ' . $m[1];
        }

        $abreviacoes = [
            '/\bREBOBINAMENTO\b/i' => 'REBOBINAMENTO',
            '/\bSELO\s+MEC[AÂ]NICO\b/iu' => 'SELO MECÂNICO',
            '/\bCENTR[IÍ]FUGO\b/iu' => 'CENTRÍFUGO',
            '/\bROTOR\b/i' => 'ROTOR',
        ];
        foreach ($abreviacoes as $pattern => $label) {
            if (preg_match($pattern, $descricao)) {
                return $label;
            }
        }

        return $descricao;
    }

    /**
     * @param array<string, mixed> $dados
     * @param array<int, array<string, mixed>> $itens
     */
    private function calcularPrazoWhatsapp(array $dados, array $itens): int
    {
        $descricoes = array_map(
            static fn(array $item): string => (string) ($item['descricao'] ?? ''),
            $itens
        );
        $textoItens = $this->normalizarTextoBusca(implode(' ', $descricoes));
        if (preg_match('/\bREBOBINAMENTO\b/u', $textoItens)) {
            return 5;
        }
        if (preg_match('/\bRECONDICIONAD[OA]S?\b/u', $textoItens)) {
            return 20;
        }

        $textoEquipamento = $this->normalizarTextoBusca(implode(' ', [
            (string) ($dados['tipo'] ?? ''),
            (string) ($dados['equip_nome'] ?? ''),
        ]));
        if (preg_match('/\b(MOTOBOMBA|MOTO BOMBA|BOMBA SUBMERSA|MOTOR ELETRICO|MOTOR)\b/u', $textoEquipamento)) {
            return 5;
        }

        if (!empty($descricoes) && $this->todosItensSaoServicoSimples($descricoes)) {
            return 2;
        }

        if (!empty($itens) && $this->todosItensMarcadosEmEstoque($itens)) {
            return 2;
        }

        $fabricante = $this->normalizarTextoBusca((string) ($dados['fabricante'] ?? ''));
        if ($fabricante === 'MAKITA') {
            return 10;
        }

        return 20;
    }

    /**
     * @param array<int, string> $descricoes
     */
    private function todosItensSaoServicoSimples(array $descricoes): bool
    {
        foreach ($descricoes as $descricao) {
            $texto = $this->normalizarTextoBusca($descricao);
            if ($texto === '') {
                return false;
            }
            if (!preg_match('/\b(TROCA DE CABO|CABO|ROLAMENTOS?|JOGO DE CARVAO|CARVAO|CONJUNTO DE CARVAO)\b/u', $texto)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $itens
     */
    private function todosItensMarcadosEmEstoque(array $itens): bool
    {
        foreach ($itens as $item) {
            if ((int) ($item['em_estoque'] ?? 0) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isItemMaoDeObraPrincipal(array $item): bool
    {
        $codigo = trim((string) ($item['codigo'] ?? ''));
        if ($codigo !== '' && in_array($codigo, ['4297', '4298', '4299', '4300', '4301'], true)) {
            return true;
        }

        $descricao = $this->normalizarTextoBusca((string) ($item['descricao'] ?? ''));
        return str_contains($descricao, 'MAO DE OBRA')
            || str_contains($descricao, 'M.O.')
            || preg_match('/\bM\s*O\b/u', $descricao) === 1;
    }

    private function normalizarTextoBusca(string $texto): string
    {
        $texto = trim(preg_replace('/\s+/', ' ', $texto) ?? $texto);
        $texto = strtr($texto, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C',
        ]);

        return function_exists('mb_strtoupper')
            ? mb_strtoupper($texto, 'UTF-8')
            : strtoupper($texto);
    }

    private function saudacaoPorHorario(): string
    {
        return ClienteHelper::saudacaoPorHorario();
    }

    private function primeiroNome(string $nome): string
    {
        $nome = trim($nome);
        if ($nome === '') {
            return '';
        }

        $partes = preg_split('/\s+/', $nome);
        return trim((string) ($partes[0] ?? $nome));
    }

    private function normalizarNomeExibicao(string $nome): string
    {
        $nome = trim(preg_replace('/\s+/', ' ', $nome) ?? '');
        if ($nome === '') {
            return '';
        }

        if (function_exists('mb_convert_case') && function_exists('mb_strtolower')) {
            return mb_convert_case(mb_strtolower($nome, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }

        return ucwords(strtolower($nome));
    }

    /**
     * Tenta promover o equipamento para "montagem" após aprovação.
     * Retorna o status resultante: 'montagem' se promoveu, status atual se não.
     * Em caso de exceção, registra log e retorna 'andamento' (assume não promovido).
     */
    private function tentarPromoverMontagem(string $osId, int $equipIdx): string
    {
        try {
            return $this->tecnicoService->promoverMontagemSeEligivel($osId, $equipIdx);
        } catch (Throwable $e) {
            // Silencioso para o usuário — aprovação não pode ser interrompida.
            error_log("[OrcamentoService] tentarPromoverMontagem({$osId}#{$equipIdx}) falhou: " . $e->getMessage());
            return 'andamento';
        }
    }

    private function sincronizarEquipamentoCancelado(string $osId, int $equipIdx, int $orcId): void
    {
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                "SELECT status_equip
                   FROM os_equipamento
                  WHERE os_id = ?
                    AND ordem_idx = ?
                  LIMIT 1"
            );
            $stmt->execute([$osId, $equipIdx]);
            $statusAnterior = $stmt->fetchColumn();
            if ($statusAnterior === false) {
                return;
            }

            $statusAnterior = (string) $statusAnterior;
            $statusFinal = in_array($statusAnterior, ['retirado', 'devolvido', 'descartado'], true);
            if (!$statusFinal && $statusAnterior !== 'cancelado') {
                $pdo->prepare(
                    "UPDATE os_equipamento
                        SET status_equip = 'cancelado',
                            status_equip_em = NOW()
                      WHERE os_id = ?
                        AND ordem_idx = ?
                      LIMIT 1"
                )->execute([$osId, $equipIdx]);

                $this->auditoria->registrar(
                    'os_equipamento',
                    $osId . '#' . $equipIdx,
                    AuditoriaService::ACAO_UPDATE,
                    [
                        'mensagem' => 'Equipamento cancelado automaticamente após cancelamento do orçamento.',
                        'os_id' => $osId,
                        'equip_idx' => $equipIdx,
                        'orcamento_id' => $orcId,
                        'status_anterior' => $statusAnterior,
                        'status_novo' => 'cancelado',
                    ]
                );
            }

            $this->notifRepo->marcarLidasPorOsEquipTipo($osId, $equipIdx, 'cancelado');
            $this->notificarTecnico(
                $osId,
                $equipIdx,
                'cancelado',
                "Orçamento cancelado na OS #{$osId} — equipamento cancelado. Verificar retirada desmontada."
            );
        } catch (Throwable $e) {
            error_log("[OrcamentoService] sincronizarEquipamentoCancelado({$osId}#{$equipIdx}) falhou: " . $e->getMessage());
        }
    }

    private function finalizarOsSeTodosEquipamentosTerminais(PDO $pdo, string $osId, string $statusAtualOs): string
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM os_equipamento
              WHERE os_id = ?
                AND status_equip NOT IN ('retirado','devolvido','descartado')"
        );
        $stmt->execute([$osId]);
        $pendentes = (int) $stmt->fetchColumn();
        if ($pendentes > 0) {
            return $statusAtualOs;
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM os_equipamento WHERE os_id = ? AND status_equip = 'retirado'"
        );
        $stmt->execute([$osId]);
        $temRetirado = (int) $stmt->fetchColumn() > 0;

        if ($temRetirado) {
            $novoStatus = 'retirado';
        } else {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM os_equipamento WHERE os_id = ? AND status_equip = 'devolvido'"
            );
            $stmt->execute([$osId]);
            $novoStatus = (int) $stmt->fetchColumn() > 0 ? 'cancelado' : 'descartado';
        }

        if ($novoStatus !== $statusAtualOs) {
            $pdo->prepare(
                "UPDATE ordem_servico
                    SET status = ?
                  WHERE id = ?
                  LIMIT 1"
            )->execute([$novoStatus, $osId]);
        }

        return $novoStatus;
    }

    /**
     * Cria notificação no painel técnico (best-effort — nunca quebra o fluxo).
     * Mensagem não contém valores financeiros.
     */
    private function notificarTecnico(string $osId, int $equipIdx, string $tipo, string $mensagem): void
    {
        try {
            $this->notifRepo->criar($osId, $equipIdx, $tipo, $mensagem);
        } catch (Throwable $e) {
            error_log("[OrcamentoService] notificarTecnico({$osId}#{$equipIdx}, {$tipo}) falhou: " . $e->getMessage());
        }
    }

    /**
     * Notifica o cliente após aprovação do orçamento (best-effort).
     */
    private function notificarClienteAprovacao(int $orcId, array $orcAntes): void
    {
        try {
            $dados = $this->repo->buscarParaWhatsapp($orcId);
            if ($dados === null) {
                error_log("[OrcamentoService] notificarClienteAprovacao(orc#{$orcId}): dados do orçamento não encontrados — ignorado.");
                return;
            }

            $telefone = ClienteHelper::telefoneParaWhatsapp($dados, (string) ($dados['contato_telefone'] ?? ''));
            $osId = (string) ($orcAntes['os_id'] ?? '');

            if ($telefone === null || $telefone === '') {
                error_log("[OrcamentoService] notificarClienteAprovacao(orc#{$orcId}): sem telefone WA — ignorado.");
                return;
            }

            $mensagem = "Vamos dar continuidade ao conserto do seu equipamento conforme o orçamento "
                . "aprovado. Assim que o serviço estiver finalizado, entraremos em contato para informar.";

            (new NotificarClienteJob(Database::pdo()))->handle([
                'telefone' => $telefone,
                'mensagem' => $mensagem,
                'os_id'    => $osId,
            ]);
        } catch (Throwable $e) {
            error_log("[OrcamentoService] notificarClienteAprovacao(orc#{$orcId}) falhou: " . $e->getMessage());
        }
    }

    /**
     * Para cada item técnico da OS, cria necessidade de compra quando a peça
     * não está disponível em estoque ou não tem produto cadastrado.
     *
     * Regras:
     *   - produto_id = NULL (item manual): sempre cria — peça física sem cadastro
     *   - produto com controla_estoque = 0: pula (serviço / M.O.)
     *   - produto com estoque_qty >= qtd: pula (estoque suficiente)
     *   - produto com estoque_qty < qtd: cria para a diferença (qtd - estoque_qty)
     *
     * Idempotência: verifica tecnico_item_id antes de criar — seguro para reaprovar.
     * Falhas aqui não propagam para o fluxo principal (best-effort).
     */
    private function gerarNecessidadesDaOs(string $osId): void
    {
        try {
            $itens = $this->itensRepo->listarTodosPorOs($osId);
            if (empty($itens)) return;

            $pdo = Database::pdo();
            foreach ($itens as $item) {
                $tecnicoItemId = (int) $item['id'];
                $qtdNecessaria = (float) ($item['qtd'] ?? 1);

                if ($this->repo->tecnicoItemFornecidoCliente($osId, (int) ($item['equip_idx'] ?? 0), $item)) {
                    continue;
                }

                // Idempotência: tecnico_item_id é a chave lógica de duplicidade
                if ($this->necessidadeRepo->existePendentePorTecnicoItem($tecnicoItemId)) {
                    continue;
                }

                $rawProdutoId = $item['produto_id'];
                $produtoId = ($rawProdutoId !== null && (int) $rawProdutoId > 0)
                    ? (int) $rawProdutoId
                    : null;

                $qtdNecessidade = $qtdNecessaria;

                if ($produtoId !== null) {
                    // Produto cadastrado: verificar estoque
                    $st = $pdo->prepare(
                        "SELECT controla_estoque, estoque_qty FROM produtos WHERE id = ? LIMIT 1"
                    );
                    $st->execute([$produtoId]);
                    $prod = $st->fetch(PDO::FETCH_ASSOC);

                    if ($prod === false) continue; // produto inexistente — skip
                    if ((int) $prod['controla_estoque'] === 0) continue; // serviço/M.O. — skip

                    $estoqueQty = (float) $prod['estoque_qty'];
                    if ($estoqueQty >= $qtdNecessaria) continue; // estoque suficiente — skip

                    // Criar só para a diferença (o que falta no estoque)
                    $qtdNecessidade = round($qtdNecessaria - $estoqueQty, 3);
                    if ($qtdNecessidade <= 0) continue;
                }
                // produto_id = NULL: item manual — cria necessidade com qtd integral

                $this->necessidadeRepo->criarIdempotentePorTecnicoItem([
                    'os_id'           => $osId,
                    'equip_idx'       => (int) ($item['equip_idx'] ?? 0),
                    'produto_id'      => $produtoId,
                    'tecnico_item_id' => $tecnicoItemId,
                    'codigo'          => (string) ($item['codigo'] ?? ''),
                    'descricao'       => (string) ($item['descricao'] ?? ''),
                    'qtd'             => $qtdNecessidade,
                ]);
            }
        } catch (Throwable $e) {
            error_log("[OS {$osId}] geração de necessidades_compra falhou: " . $e->getMessage());
        }
    }

    /**
     * @param array<int, array<string, mixed>> $orcItens
     * @return array{criados:int, atualizados:int, desativados:int}
     */
    private function sincronizarItensTecnicosComOrcamento(string $osId, int $equipIdx, array $orcItens): array
    {
        $tecnicoItens = $this->itensRepo->listarPorEquipamentoIncluindoInativos($osId, $equipIdx);
        $ativos = [];
        foreach ($tecnicoItens as $item) {
            if ((int) ($item['ativo'] ?? 1) === 1) {
                $ativos[(int) $item['id']] = $item;
            }
        }

        $idsUsados = [];
        $resumo = ['criados' => 0, 'atualizados' => 0, 'desativados' => 0];

        $permitirFallbackUnico = count($orcItens) === 1 && count($ativos) === 1;

        foreach ($orcItens as $orcItem) {
            $orcItemId = (int) ($orcItem['id'] ?? 0);
            if ($orcItemId <= 0) {
                continue;
            }

            $dadosTecnico = $this->normalizarItemOrcamentoParaTecnico($orcItem);
            $tecnicoItemId = $this->localizarItemTecnicoParaOrcamento(
                $orcItem,
                $ativos,
                $idsUsados,
                $permitirFallbackUnico
            );

            if ($tecnicoItemId > 0) {
                $this->itensRepo->atualizarPeloOrcamento($tecnicoItemId, $dadosTecnico + [
                    'origem_orcamento_item_id' => $orcItemId,
                ]);
                $resumo['atualizados']++;
            } else {
                $tecnicoItemId = $this->itensRepo->criar([
                    'os_id' => $osId,
                    'equip_idx' => $equipIdx,
                    'codigo' => $dadosTecnico['codigo'],
                    'produto_id' => $dadosTecnico['produto_id'],
                    'descricao' => $dadosTecnico['descricao'],
                    'qtd' => $dadosTecnico['qtd'],
                    'valor_unit' => $dadosTecnico['valor_unit'],
                    'ativo' => 1,
                    'origem_orcamento_item_id' => $orcItemId,
                ]);
                $resumo['criados']++;
            }

            $idsUsados[$tecnicoItemId] = $tecnicoItemId;
            $this->repo->atualizarVinculoItem($orcItemId, $dadosTecnico['produto_id'], $tecnicoItemId);
        }

        foreach ($ativos as $id => $item) {
            if (isset($idsUsados[$id])) {
                continue;
            }
            $this->itensRepo->desativarPeloOrcamento($id);
            $this->cancelarNecessidadesAtivasDoItemTecnico($osId, $equipIdx, $id);
            $resumo['desativados']++;
        }

        return $resumo;
    }

    /**
     * @param array<string, mixed> $orcItem
     * @return array{codigo:string, produto_id:int|null, descricao:string, qtd:float, valor_unit:float, valor_total:float}
     */
    private function normalizarItemOrcamentoParaTecnico(array $orcItem): array
    {
        $qtd = (float) ($orcItem['qtd'] ?? 1);
        $valorUnit = (float) ($orcItem['valor_unit'] ?? 0);
        $valorTotal = isset($orcItem['valor_total'])
            ? (float) $orcItem['valor_total']
            : $qtd * $valorUnit;

        $fornecidoCliente = (int) ($orcItem['fornecido_cliente'] ?? 0) === 1;
        $produtoId = $fornecidoCliente ? null : $this->resolverProdutoIdOrcamento($orcItem);

        return [
            'codigo' => trim((string) ($orcItem['codigo'] ?? '')),
            'produto_id' => $produtoId,
            'descricao' => trim((string) ($orcItem['descricao'] ?? '')),
            'qtd' => $qtd,
            'valor_unit' => $valorUnit,
            'valor_total' => $valorTotal,
        ];
    }

    /**
     * @param array<string, mixed> $orcItem
     * @param array<int, array<string, mixed>> $ativos
     * @param array<int, int> $idsUsados
     */
    private function localizarItemTecnicoParaOrcamento(
        array $orcItem,
        array $ativos,
        array $idsUsados,
        bool $permitirFallbackUnico
    ): int
    {
        $tecnicoItemId = (int) ($orcItem['tecnico_item_id'] ?? 0);
        if ($tecnicoItemId > 0 && isset($ativos[$tecnicoItemId]) && !isset($idsUsados[$tecnicoItemId])) {
            return $tecnicoItemId;
        }

        $codigo = $this->normalizarTextoComparacao((string) ($orcItem['codigo'] ?? ''));
        if ($codigo !== '') {
            foreach ($ativos as $id => $item) {
                if (isset($idsUsados[$id])) continue;
                if ($this->normalizarTextoComparacao((string) ($item['codigo'] ?? '')) === $codigo) {
                    return (int) $id;
                }
            }
        }

        $descricao = $this->normalizarTextoComparacao((string) ($orcItem['descricao'] ?? ''));
        if ($descricao !== '') {
            foreach ($ativos as $id => $item) {
                if (isset($idsUsados[$id])) continue;
                if ($this->normalizarTextoComparacao((string) ($item['descricao'] ?? '')) === $descricao) {
                    return (int) $id;
                }
            }
        }

        $restantes = array_diff_key($ativos, $idsUsados);
        if ($permitirFallbackUnico && count($restantes) === 1) {
            return (int) array_key_first($restantes);
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $orcItem
     */
    private function resolverProdutoIdOrcamento(array $orcItem): ?int
    {
        $produtoId = (int) ($orcItem['produto_id'] ?? 0);
        if ($produtoId > 0) {
            return $produtoId;
        }

        $codigo = trim((string) ($orcItem['codigo'] ?? ''));
        if ($codigo === '') {
            return null;
        }

        $produto = $this->produtoRepo->buscarPorCodigoExato($codigo);
        return $produto !== null ? (int) $produto['id'] : null;
    }

    private function normalizarTextoComparacao(string $texto): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $texto) ?? ''));
    }

    private function cancelarNecessidadesAtivasDoItemTecnico(string $osId, int $equipIdx, int $tecnicoItemId): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE necessidades_compra
                SET status = 'cancelado',
                    atualizado_em = NOW()
              WHERE os_id = :os
                AND equip_idx = :idx
                AND tecnico_item_id = :item_id
                AND status IN ('pendente', 'comprado')"
        );
        $stmt->execute([
            ':os' => $osId,
            ':idx' => $equipIdx,
            ':item_id' => $tecnicoItemId,
        ]);
    }

    /**
     * Busca os itens registrados pelo técnico (tecnico_itens) e os converte
     * para o formato esperado por orcamento_itens, incluindo preço do produto.
     * Usado automaticamente ao criar um orçamento sem itens fornecidos.
     *
     * @return array<int, array<string, mixed>>
     */
    private function autoImportarItensTecnico(string $osId, int $equipIdx): array
    {
        try {
            $tecnicoItens = $this->itensRepo->listarPorEquipamento($osId, $equipIdx);
            if (empty($tecnicoItens)) {
                return [];
            }

            $pdo = Database::pdo();
            $orcItens = [];

            foreach ($tecnicoItens as $ti) {
                $valorUnit = (float) ($ti['valor_unit'] ?? 0);

                // Verifica disponibilidade em estoque para o campo em_estoque
                $emEstoque = 0;
                $produtoId = isset($ti['produto_id']) ? (int) $ti['produto_id'] : 0;
                if ($produtoId > 0) {
                    $stk = $pdo->prepare('SELECT estoque_qty FROM produtos WHERE id = ? AND ativo = 1 LIMIT 1');
                    $stk->execute([$produtoId]);
                    $qty = (float) ($stk->fetchColumn() ?: 0);
                    $emEstoque = $qty >= (float) ($ti['qtd'] ?? 1) ? 1 : 0;
                }

                $orcItens[] = [
                    'descricao'   => (string) ($ti['descricao'] ?? ''),
                    'codigo'      => (string) ($ti['codigo'] ?? ''),
                    'produto_id'  => $produtoId > 0 ? $produtoId : null,
                    'tecnico_item_id' => (int) ($ti['id'] ?? 0),
                    'qtd'         => (float) ($ti['qtd'] ?? 1),
                    'unidade'     => 'un',
                    'valor_unit'  => $valorUnit,
                    'valor_total' => round((float) ($ti['qtd'] ?? 1) * $valorUnit, 2),
                    'em_estoque'  => $emEstoque,
                    'data_pedido' => null,
                    'obs'         => '',
                ];
            }

            return $orcItens;
        } catch (Throwable $e) {
            // Falha na auto-importação não deve impedir a criação do orçamento.
            error_log("[OrcamentoService] autoImportarItensTecnico falhou: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 9H-1: Valida se a transição de status é permitida pela matriz definida.
     * Lança InvalidArgumentException com mensagem orientadora caso seja inválida.
     *
     * 9H-4: status comerciais puros — pronto/retirado removidos do modelo.
     * Matriz de transições permitidas via PATCH:
     *   rascunho → enviado, aprovado, cancelado
     *   enviado  → aprovado, cancelado
     *   aprovado → cancelado
     *   cancelado → (nenhuma — terminal)
     *
     * Transições para 'retirado' são capturadas pelo guard anterior a este método.
     * Qualquer outro destino fora da matriz retorna erro genérico (inclui 'pronto').
     */
    private function validarTransicaoStatusOrcamento(string $de, string $para): void
    {
        // Transições permitidas por estado de origem (status comerciais puros)
        $permitidas = [
            'rascunho' => ['enviado', 'aprovado', 'cancelado'],
            'enviado'  => ['aprovado', 'cancelado'],
            'aprovado' => ['cancelado'],
            'cancelado'=> [],   // terminal — sem retorno via PATCH
        ];

        $destinos = $permitidas[$de] ?? [];

        if (in_array($para, $destinos, true)) {
            return; // transição permitida
        }

        // Mensagens específicas por contexto
        $mensagensEspecificas = [
            'aprovado' => [
                'rascunho' => 'Não é possível regredir um orçamento aprovado para rascunho. Se necessário, cancele-o.',
                'enviado'  => 'Não é possível regredir um orçamento aprovado para enviado. Se necessário, cancele-o.',
            ],
            'cancelado' => [
                'rascunho' => 'Orçamento cancelado não pode ser reaberto. Crie um novo orçamento se o cliente quiser retomar.',
                'enviado'  => 'Orçamento cancelado não pode ser reaberto. Crie um novo orçamento se o cliente quiser retomar.',
                'aprovado' => 'Orçamento cancelado não pode ser aprovado novamente. Crie um novo orçamento se o cliente quiser retomar.',
            ],
        ];

        $msg = $mensagensEspecificas[$de][$para]
            ?? "Transição de status inválida: '{$de}' → '{$para}'.";

        throw new InvalidArgumentException($msg);
    }

    /**
     * @param  array<string, mixed> $cab
     * @return array<string, mixed>
     */
    private function normalizarCabecalho(string $osId, int $equipIdx, array $cab): array
    {
        $tipo = (string) ($cab['tipo'] ?? 'maquina');
        if (!in_array($tipo, self::TIPOS_VALIDOS, true)) $tipo = 'maquina';

        $moValor = (float) ($cab['mo_valor'] ?? 0);
        if ($moValor < 0) $moValor = 0.0;

        $total = (float) ($cab['total'] ?? 0);
        if ($total < 0) $total = 0.0;

        $dataOrc = $cab['data_orcamento'] ?? null;
        if ($dataOrc === '' || $dataOrc === false) $dataOrc = null;

        return [
            'os_id'          => $osId,
            'equip_idx'      => $equipIdx,
            'tipo'           => $tipo,
            'tecnico'        => trim((string) ($cab['tecnico'] ?? '')),
            'gerado_por'     => trim((string) ($cab['gerado_por'] ?? '')),
            'obs_admin'      => trim((string) ($cab['obs_admin'] ?? '')),
            'mo_valor'       => round($moValor, 2),
            'total'          => round($total, 2),
            'data_orcamento' => $dataOrc,
        ];
    }
}
