<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Queue\DatabaseQueue;
use App\Repositories\NotificacaoTecnicoRepository;
use App\Repositories\OrcamentoRepository;
use App\Repositories\OrdemServicoRepository;
use App\Repositories\ClienteRepository;
use App\Repositories\NecessidadeCompraRepository;
use App\Repositories\TecnicoItemRepository;
use App\Services\TermoService;
use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;

final class OrdemServicoService
{
    public const FORMAS_PAGAMENTO = ['dinheiro', 'pix', 'cartao', 'faturado'];

    /**
     * Estados físicos terminais de um equipamento: destino físico já definido e irreversível.
     * 'cancelado' NÃO está aqui — cancelado significa apenas que o serviço foi recusado,
     * mas o equipamento ainda aguarda destino (devolução ou descarte).
     */
    private const STATUS_EQUIP_FINAL_FISICO = ['retirado', 'devolvido', 'descartado'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabaseQueue $queue,
        private readonly OrdemServicoRepository $repo = new OrdemServicoRepository(),
        private readonly ClienteRepository $clienteRepo = new ClienteRepository(),
        private readonly UploadService $upload = new UploadService(),
        private readonly NotificationService $notifier = new NotificationService(),
        private readonly TermoService $termoService = new TermoService(),
        private readonly TecnicoItemRepository $itensRepo = new TecnicoItemRepository(),
        private readonly NecessidadeCompraRepository $necessidadeRepo = new NecessidadeCompraRepository(),
        private readonly NotificacaoTecnicoRepository $notifRepo = new NotificacaoTecnicoRepository(),
        private readonly OrcamentoRepository $orcRepo = new OrcamentoRepository(),
    ) {}

    /**
     * Cria uma nova Ordem de Serviço com equipamentos e (opcionalmente) fotos
     * de recepção. Operação transacional para a parte de DB; uploads e
     * notificação acontecem fora da transação para não bloquear/desperdiçar
     * I/O em caso de rollback.
     *
     * @param array{name:array,type:array,tmp_name:array,error:array,size:array}|null $fotosUploads
     *        Estrutura nativa de $_FILES['fotos_recepcao'] (modo multiple).
     */
    public function criar(array $dadosOs, array $equipamentos, ?array $fotosUploads = null): string
    {
        $this->pdo->beginTransaction();
        try {
            $osId = $this->gerarIdOs();
            $dadosOs['id'] = $osId;

            // Se o cliente vier sem ID, tentar buscar por CPF/CNPJ
            if (empty($dadosOs['cliente_id']) && !empty($dadosOs['doc_cliente'])) {
                $doc = preg_replace('/\D/', '', $dadosOs['doc_cliente']);
                if ($doc !== '') {
                    $cli = $this->clienteRepo->buscarPorCpfCnpj($doc);
                    if ($cli) {
                        $dadosOs['cliente_id'] = $cli['id'];
                    }
                }
            }

            // Se mesmo após a busca o cliente não existir, cria um novo
            if (empty($dadosOs['cliente_id']) && !empty($dadosOs['nome_cliente'])) {
                $novoClienteId = $this->clienteRepo->criar([
                    'nome' => $dadosOs['nome_cliente'],
                    'telefone' => $dadosOs['telefone'] ?? '',
                    'cpf_cnpj' => $dadosOs['doc_cliente'] ?? ''
                ]);
                $dadosOs['cliente_id'] = $novoClienteId;
            }

            // Cria a OS
            $this->repo->criar($dadosOs);

            // Adiciona equipamentos
            foreach ($equipamentos as $idx => $eq) {
                $eq['ordem_idx'] = $idx;
                $this->repo->adicionarEquipamento($osId, $eq);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // ── Pós-commit: uploads e notificação não devem rollback-ear a OS ─
        if ($fotosUploads !== null) {
            $this->salvarFotosRecepcao($osId, $fotosUploads);
        }

        $this->dispararNotificacaoOsCriada($osId, $dadosOs, $equipamentos);

        return $osId;
    }

    /**
     * Move os arquivos via UploadService e grava o array de URLs em
     * os_equipamento.fotos_os_json do equipamento idx=0 (o "bucket" da OS).
     * Falhas individuais de arquivo são logadas — não derrubam a OS.
     */
    private function salvarFotosRecepcao(string $osId, array $files): void
    {
        // Normaliza $_FILES (formato multiple) em uma lista de uploads simples.
        $names = $files['name']     ?? [];
        if (!is_array($names) || empty($names)) return;

        $urls = [];
        foreach ($names as $i => $name) {
            $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) continue;

            $upload = [
                'name'     => (string)$name,
                'type'     => (string)($files['type'][$i] ?? ''),
                'tmp_name' => (string)($files['tmp_name'][$i] ?? ''),
                'error'    => $err,
                'size'     => (int)($files['size'][$i] ?? 0),
            ];

            try {
                $urls[] = $this->upload->salvar($osId, 0, UploadService::KIND_FOTO, $upload);
            } catch (Throwable $e) {
                error_log("[OS {$osId}] foto recepção #{$i} falhou: " . $e->getMessage());
            }
        }

        if (empty($urls)) return;

        // Mescla com fotos pré-existentes do equip[0] (se houver, em modo editar usa atualizar()).
        $eqs = $this->repo->buscarEquipamentos($osId);
        $atual = [];
        if (!empty($eqs[0]['fotos_os_json'])) {
            $decoded = json_decode((string)$eqs[0]['fotos_os_json'], true);
            if (is_array($decoded)) $atual = $decoded;
        }
        $todas = array_values(array_merge($atual, $urls));

        // UPDATE direto — não temos método dedicado no repo e não vale criar outro só pra isso.
        $stmt = Database::pdo()->prepare(
            "UPDATE os_equipamento SET fotos_os_json = :json
             WHERE os_id = :os_id AND ordem_idx = 0
             LIMIT 1"
        );
        $stmt->execute([
            ':json'  => json_encode($todas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':os_id' => $osId,
        ]);
    }

    private function dispararNotificacaoOsCriada(string $osId, array $dadosOs, array $equipamentos): void
    {
        // Gera aceite digital (slug) para o termo de responsabilidade
        $linkTermo = '';
        try {
            $slug = $this->termoService->criarAceite($osId);
            $linkTermo = $this->termoService->gerarUrl($slug);
        } catch (Throwable $e) {
            error_log("[OS {$osId}] falha ao gerar aceite digital: " . $e->getMessage());
        }

        // 10F-1: busca dados completos do cliente para nome_fantasia + prioridade de telefone.
        $email    = '';
        $cliExtra = [];
        if (!empty($dadosOs['cliente_id'])) {
            $cli = $this->clienteRepo->buscarPorId((int)$dadosOs['cliente_id']);
            if ($cli) {
                if (!empty($cli['email']))    $email = (string)$cli['email'];
                $cliExtra = $cli;             // passa todos os campos para o helper
            }
        }

        try {
            $this->notifier->notificarOsCriada($osId, [
                'nome'             => (string)($dadosOs['nome_cliente'] ?? ''),
                'nome_fantasia'    => (string)($cliExtra['nome_fantasia'] ?? ''),
                'contato_nome'     => (string)($dadosOs['contato_nome']     ?? ''),
                'contato_telefone' => (string)($dadosOs['contato_telefone'] ?? ''),
                'telefone'         => (string)($dadosOs['telefone'] ?? ''),
                'celular'          => (string)($cliExtra['celular']   ?? ''),
                'fone'             => (string)($cliExtra['fone']      ?? ''),
                'telefone2'        => (string)($cliExtra['telefone2'] ?? ''),
                'email'            => $email,
                'cliente_id'       => $dadosOs['cliente_id'] ?? null,
                'link_termo'       => $linkTermo,
            ], $equipamentos);
        } catch (Throwable $e) {
            // Notificação é nice-to-have: log e segue.
            error_log("[OS {$osId}] dispatch de notificação falhou: " . $e->getMessage());
        }
    }

    /**
     * Atualiza dados de uma OS existente (re-criando equipamentos).
     */
    public function atualizar(string $id, array $dadosOs, array $equipamentos, ?array $fotosUploads = null): void
    {
        $this->pdo->beginTransaction();
        try {
            // Atualiza dados base
            $this->repo->atualizar($id, $dadosOs);

            // ANTES DE DELETAR, SALVAR O fotos_os_json do equipamento 0
            $equipamentosAntigos = $this->repo->buscarEquipamentos($id);
            $fotosAntigas = null;
            if (!empty($equipamentosAntigos) && isset($equipamentosAntigos[0]['fotos_os_json'])) {
                $fotosAntigas = $equipamentosAntigos[0]['fotos_os_json'];
            }

            // Recria equipamentos
            $this->repo->deletarEquipamentos($id);
            foreach ($equipamentos as $idx => $eq) {
                $eq['ordem_idx'] = $idx;
                $this->repo->adicionarEquipamento($id, $eq);
            }

            // SE HAVIA FOTOS, RESTAURAR NO NOVO EQUIPAMENTO 0
            if ($fotosAntigas !== null) {
                $stmt = $this->pdo->prepare("UPDATE os_equipamento SET fotos_os_json = :json WHERE os_id = :os_id AND ordem_idx = 0 LIMIT 1");
                $stmt->execute([':json' => $fotosAntigas, ':os_id' => $id]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($fotosUploads !== null) {
            $this->salvarFotosRecepcao($id, $fotosUploads);
        }
    }

    /**
     * Atualiza status da OS.
     * Não cascateia para equipamentos — cada equipamento tem seu próprio ciclo de vida.
     */
    public function atualizarStatus(string $id, string $novoStatus): void
    {
        if ($novoStatus === 'pronto') {
            throw new InvalidArgumentException(
                "Status 'pronto' não pode ser definido pela atualização simples da OS. Use o fluxo oficial de conclusão."
            );
        }
        if ($novoStatus === 'retirado') {
            throw new InvalidArgumentException(
                "Status 'retirado' não pode ser definido pela atualização simples da OS. Use o fluxo oficial de retirada."
            );
        }

        $this->repo->atualizarStatus($id, $novoStatus);
    }

    /**
     * Cancela a OS
     */
    public function cancelar(string $id): void
    {
        $this->repo->atualizarStatus($id, 'cancelado');
        $stmt = Database::pdo()->prepare("UPDATE os_equipamento SET status_equip = 'cancelado', status_equip_em = NOW() WHERE os_id = ?");
        $stmt->execute([$id]);
    }

    /**
     * Conclui uma OS, cria o lançamento financeiro e enfileira a emissão da NFS-e.
     */
    public function concluir(string $osId, int $operadorId): array
    {
        $this->pdo->beginTransaction();
        try {
            // 1. Lê a OS (com lock de leitura para evitar dupla conclusão)
            $st = $this->pdo->prepare(
                'SELECT id, cliente_id, nome_cliente, status, data_conclusao
                 FROM ordem_servico
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $st->execute([$osId]);
            $os = $st->fetch(PDO::FETCH_ASSOC);

            if (!$os) {
                throw new DomainException("OS #{$osId} não encontrada.");
            }

            // Guard contra double-call e estados inválidos.
            if (in_array($os['status'], ['pronto', 'concluida', 'retirado'], true)) {
                // OS entregue ao cliente nunca pode ser reconcluída.
                if ($os['status'] === 'retirado') {
                    throw new DomainException("OS #{$osId} já foi entregue ao cliente. Não é possível concluir novamente.");
                }
                // OS com data_conclusao = passou por concluir() anteriormente; bloqueia duplicidade.
                if ($os['data_conclusao'] !== null) {
                    throw new DomainException("OS #{$osId} já foi concluída administrativamente. Use reabrir() se precisar corrigir.");
                }
                // OS 'pronto' sem data_conclusao = status definido por macro técnico sem conclusão administrativa.
                // Permite prosseguir: concluir() vai criar o financeiro e registrar data_conclusao.
            }

            // Pré-condição: todos os equipamentos não-cancelados devem estar prontos.
            // Status aceitos: 'pronto', 'cancelado', 'retirado', 'devolvido', 'descartado'.
            // Status que bloqueiam: 'aberta', 'andamento', 'montagem'.
            $stCheck = $this->pdo->prepare(
                "SELECT COUNT(*) FROM os_equipamento
                  WHERE os_id = ? AND status_equip NOT IN ('pronto','cancelado','retirado','devolvido','descartado')"
            );
            $stCheck->execute([$osId]);
            $pendentes = (int) $stCheck->fetchColumn();
            if ($pendentes > 0) {
                throw new DomainException(
                    "Não é possível concluir a OS #{$osId}: {$pendentes} equipamento(s) ainda não está(ão) pronto(s). " .
                    "Verifique os status individuais antes de concluir."
                );
            }

            $this->assertOrcamentosComValorFinalizadosParaConclusao($osId);

            // Calcula total de orçamentos aprovados
            $total = $this->somarOrcamentosAprovados($osId);

            if ($total > 0.0) {
                $this->assertSemFinanceiroAtivo($osId);
            }

            // 2. Atualiza status da OS — equipamentos mantêm seus status individuais
            $this->pdo->prepare(
                "UPDATE ordem_servico
                 SET status = 'pronto', data_conclusao = NOW(), operador_id = ?
                 WHERE id = ?
                 LIMIT 1"
            )->execute([$operadorId, $osId]);

            // 3. Cria 1 lançamento a receber por orçamento aprovado com valor > 0 (vencimento D+1).
            // 9H-4: apenas 'aprovado' — 'pronto' foi removido do modelo comercial de orcamentos.
            // Exclui equipamentos já retirados: foram faturados em retirarEquipamento() e
            // já possuem lançamento próprio — criar novamente violaria UNIQUE KEY uq_orc_lancamento.
            // 10D-2: exclui orçamentos que já possuem qualquer lançamento (pago antecipadamente,
            // aguardando fatura, ou aberto de fluxo parcial) — UNIQUE KEY uq_orc_lancamento
            // impediria o INSERT e o lançamento existente já representa o financeiro deste orçamento.
            $stOrcs = $this->pdo->prepare(
                "SELECT o.id, o.equip_idx, o.total
                   FROM orcamentos o
                   JOIN os_equipamento eq
                     ON eq.os_id     = o.os_id
                    AND eq.ordem_idx = o.equip_idx
                  WHERE o.os_id  = ?
                    AND o.status = 'aprovado'
                    AND o.total  > 0
                    AND eq.status_equip NOT IN ('retirado','devolvido','descartado')
                    AND NOT EXISTS (
                        SELECT 1 FROM lancamentos_receber lr WHERE lr.orcamento_id = o.id
                    )
                  ORDER BY o.equip_idx ASC"
            );
            $stOrcs->execute([$osId]);
            $orcsComValor = $stOrcs->fetchAll(PDO::FETCH_ASSOC);

            $notaIds = [];
            $lancamentoIds = [];
            $nfseSettings = (new NfseSettingsService())->obter();
            $nfseStatusInicial = (($nfseSettings['write_enabled'] ?? '0') === '1'
                && ($nfseSettings['exigir_conferencia_manual'] ?? '1') !== '1')
                ? 'pendente'
                : 'rascunho';

            foreach ($orcsComValor as $orc) {
                $equipIdx  = (int) $orc['equip_idx'];
                $valorOrc  = (float) $orc['total'];
                $orcId     = (int) $orc['id'];
                $descricao = "OS #{$osId} equip.{$equipIdx} — " . ($os['nome_cliente'] ?? 'Cliente');

                $this->pdo->prepare(
                    "INSERT INTO lancamentos_receber
                     (os_id, equip_idx, orcamento_id, cliente_id, valor, vencimento, status, descricao, criado_em)
                     VALUES (?, ?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'aberto', ?, NOW())"
                )->execute([$osId, $equipIdx, $orcId, $os['cliente_id'], $valorOrc, $descricao]);

                $lancamentoId = (int) $this->pdo->lastInsertId();
                $lancamentoIds[] = $lancamentoId;

                // 4. Registra rascunho/pendência fiscal sem transmitir automaticamente.
                $this->pdo->prepare(
                    "INSERT INTO notas_fiscais
                     (os_id, lancamento_id, orcamento_id, cliente_id, tipo_documento, ambiente, status,
                      valor_total, descricao_servico, serie_dps, competencia, created_by, updated_by, criado_em, atualizado_em)
                     VALUES (?, ?, ?, ?, 'nfse', ?, ?, ?, ?, ?, CURDATE(), ?, ?, NOW(), NOW())"
                )->execute([
                    $osId,
                    $lancamentoId,
                    $orcId,
                    $os['cliente_id'],
                    $nfseSettings['ambiente'] ?? 'homologacao',
                    $nfseStatusInicial,
                    $valorOrc,
                    $descricao,
                    $nfseSettings['serie_dps'] ?? '1',
                    $operadorId,
                    $operadorId,
                ]);

                $notaIds[] = (int) $this->pdo->lastInsertId();
            }

            // COMMIT
            $this->pdo->commit();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // 5. Enfileira emissão fora da transação (uma tarefa por NFS-e)
        $nfseSettings = (new NfseSettingsService())->obter();
        if (($nfseSettings['write_enabled'] ?? '0') === '1'
            && ($nfseSettings['exigir_conferencia_manual'] ?? '1') !== '1'
            && ($nfseSettings['contador_aprova_total_os'] ?? '0') === '1') {
            foreach ($notaIds as $notaId) {
                $this->queue->enqueue('emitir_nfse', [
                    'nota_id'     => $notaId,
                    'os_id'       => $osId,
                    'operador_id' => $operadorId,
                ]);
            }
        }

        return [
            'ok'             => true,
            'nota_ids'       => $notaIds,
            'lancamento_ids' => $lancamentoIds,
            // Compatibilidade retroativa: expõe o primeiro ID (ou null se sem valor)
            'nota_id'        => $notaIds[0]       ?? null,
            'lancamento_id'  => $lancamentoIds[0] ?? null,
        ];
    }

    /**
     * Registra a retirada de uma OS pelo cliente em uma única transação:
     *   1. Atualiza ordem_servico → status='retirado', data_retirada, forma_pagamento, operador
     *   2. Quita o lançamento financeiro (à vista) ou marca aguardando_fatura (B2B)
     *   3. Baixa o estoque dos itens com produto_id e registra movimentações
     *
     * Formas: dinheiro, pix, cartao → status='pago' / faturado → status='aguardando_fatura'
     */
    public function retirar(string $osId, int $operadorId, string $formaPagamento, ?string $numeroPedido = null, float $descontoValor = 0.0): array
    {
        if (!in_array($formaPagamento, self::FORMAS_PAGAMENTO, true)) {
            throw new InvalidArgumentException("Forma de pagamento inválida: {$formaPagamento}");
        }
        if ($descontoValor < 0.0) {
            throw new InvalidArgumentException('Desconto não pode ser negativo.');
        }

        // Guard: este fluxo é exclusivo de OS com 1 equipamento.
        // OS com múltiplos equipamentos usa retirarEquipamento() por equip.
        // Verificado antes de qualquer transação para não gerar estado parcial.
        $stQtdEq = $this->pdo->prepare(
            "SELECT COUNT(*) FROM os_equipamento WHERE os_id = ?"
        );
        $stQtdEq->execute([$osId]);
        if ((int) $stQtdEq->fetchColumn() > 1) {
            throw new DomainException(
                "OS com múltiplos equipamentos deve usar retirada por equipamento. " .
                "Utilize o botão 'Retirar' em cada equipamento individualmente."
            );
        }

        $itensBaixados = 0;

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                "SELECT id, status, cliente_id FROM ordem_servico WHERE id = ? LIMIT 1 FOR UPDATE"
            );
            $st->execute([$osId]);
            $os = $st->fetch(PDO::FETCH_ASSOC);

            if (!$os) {
                throw new DomainException("OS #{$osId} não encontrada.");
            }
            if ($os['status'] !== 'pronto') {
                throw new DomainException("OS #{$osId} não está pronta para retirada (status atual: {$os['status']}).");
            }

            $totalAprovado = $this->somarOrcamentosAprovados($osId);
            if ($totalAprovado > 0.0 && !$this->existeLancamentoReceberEmAberto($osId)) {
                throw new DomainException(
                    'Há valor a cobrar, mas nenhum lançamento financeiro foi encontrado. ' .
                    'Conclua a OS corretamente antes da retirada.'
                );
            }
            if ($descontoValor > $totalAprovado && $totalAprovado > 0.0) {
                throw new InvalidArgumentException(
                    'Desconto (R$ ' . number_format($descontoValor, 2, ',', '.') . ') ' .
                    'não pode ser maior que o valor a receber ' .
                    '(R$ ' . number_format($totalAprovado, 2, ',', '.') . ').'
                );
            }

            // 1. Atualiza OS
            $this->pdo->prepare(
                "UPDATE ordem_servico
                    SET status = 'retirado',
                        data_retirada = NOW(),
                        forma_pagamento = ?,
                        numero_pedido = ?,
                        operador_retirada_id = ?
                  WHERE id = ?
                  LIMIT 1"
            )->execute([$formaPagamento, $numeroPedido, $operadorId, $osId]);

            // Cascata para equipamentos — retirado marca que o item saiu fisicamente da loja.
            // Não sobrescreve status terminais: cancelado, devolvido, descartado.
            $this->pdo->prepare("UPDATE os_equipamento SET status_equip = 'retirado', status_equip_em = NOW() WHERE os_id = ? AND status_equip NOT IN ('cancelado','retirado','devolvido','descartado')")->execute([$osId]);

            // 2. Financeiro — quita cada lançamento individualmente e atualiza orcamentos.pago
            $stLancsOs = $this->pdo->prepare(
                "SELECT id AS lanc_id, orcamento_id
                   FROM lancamentos_receber
                  WHERE os_id = ? AND status = 'aberto'"
            );
            $stLancsOs->execute([$osId]);
            $lancsAbertosOs = $stLancsOs->fetchAll(PDO::FETCH_ASSOC);

            foreach ($lancsAbertosOs as $lanc) {
                $lancId    = (int) $lanc['lanc_id'];
                $orcIdLanc = $lanc['orcamento_id'] !== null ? (int) $lanc['orcamento_id'] : null;

                if ($formaPagamento === 'faturado') {
                    $this->pdo->prepare(
                        "UPDATE lancamentos_receber
                            SET status = 'aguardando_fatura',
                                desconto_valor = ?,
                                forma_pagamento = 'faturado'
                          WHERE id = ? LIMIT 1"
                    )->execute([$descontoValor, $lancId]);
                } else {
                    $this->pdo->prepare(
                        "UPDATE lancamentos_receber
                            SET status = 'pago',
                                desconto_valor = ?,
                                valor_pago = valor - ?,
                                data_pagamento = CURDATE(),
                                forma_pagamento = ?
                          WHERE id = ? LIMIT 1"
                    )->execute([$descontoValor, $descontoValor, $formaPagamento, $lancId]);
                }

                if ($orcIdLanc !== null) {
                    $this->pdo->prepare(
                        "UPDATE orcamentos SET pago = 1 WHERE id = ? LIMIT 1"
                    )->execute([$orcIdLanc]);
                }
            }

            // 3. Estoque — OS com 1 equipamento continua usando o mesmo fluxo,
            // mas agora registra origem/equipamento por item técnico.
            $stEquipIdx = $this->pdo->prepare(
                "SELECT ordem_idx FROM os_equipamento WHERE os_id = ? ORDER BY ordem_idx ASC LIMIT 1"
            );
            $stEquipIdx->execute([$osId]);
            $equipIdxRetirada = $stEquipIdx->fetchColumn();
            if ($equipIdxRetirada === false) {
                throw new DomainException("OS #{$osId} não possui equipamento para baixa de estoque.");
            }

            $itensBaixados = $this->baixarEstoqueItensEquipamento($osId, (int) $equipIdxRetirada, $operadorId);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return [
            'os_id'           => $osId,
            'forma_pagamento' => $formaPagamento,
            'itens_baixados'  => $itensBaixados,
        ];
    }

    /**
     * Baixa estoque físico dos itens técnicos de um equipamento.
     *
     * Idempotência:
     *   origem_tipo = os_equipamento_item
     *   origem_id   = tecnico_itens.id
     *
     * Mantém as regras atuais: só produto cadastrado, só controla_estoque=1,
     * permite saldo negativo e ignora peças fornecidas pelo cliente.
     */
    private function baixarEstoqueItensEquipamento(string $osId, int $equipIdx, int $operadorId): int
    {
        $itensBaixados = 0;
        $itens = $this->itensRepo->listarComProdutoPorEquipamento($osId, $equipIdx);

        foreach ($itens as $item) {
            $tecnicoItemId = (int) ($item['id'] ?? 0);
            $produtoId     = (int) ($item['produto_id'] ?? 0);
            $qtd           = (float) ($item['qtd'] ?? 0);

            if ($tecnicoItemId <= 0 || $produtoId <= 0 || $qtd <= 0) {
                continue;
            }
            if ($this->orcRepo->tecnicoItemFornecidoCliente($osId, $equipIdx, $item)) {
                continue;
            }

            $stProd = $this->pdo->prepare(
                "SELECT estoque_qty, controla_estoque
                   FROM produtos
                  WHERE id = ?
                  LIMIT 1
                  FOR UPDATE"
            );
            $stProd->execute([$produtoId]);
            $prod = $stProd->fetch(PDO::FETCH_ASSOC);
            if ($prod === false) {
                continue;
            }

            if (!(int) $prod['controla_estoque']) {
                continue;
            }

            $origemTipo = 'os_equipamento_item';
            $origemId = (string) $tecnicoItemId;

            $stMov = $this->pdo->prepare(
                "SELECT id
                   FROM estoque_movimentacoes
                  WHERE origem_tipo = ?
                    AND origem_id = ?
                    AND tipo = 'saida'
                  LIMIT 1
                  FOR UPDATE"
            );
            $stMov->execute([$origemTipo, $origemId]);
            if ($stMov->fetchColumn() !== false) {
                continue;
            }

            $saldoAnt = (float) $prod['estoque_qty'];
            $saldoPos = $saldoAnt - $qtd;

            $this->pdo->prepare(
                "UPDATE produtos SET estoque_qty = ? WHERE id = ? LIMIT 1"
            )->execute([$saldoPos, $produtoId]);

            $this->pdo->prepare(
                "INSERT INTO estoque_movimentacoes
                   (produto_id, os_id, equip_idx, tipo, qtd, saldo_ant, saldo_pos,
                    descricao, usuario_id, origem_tipo, origem_id, criado_em)
                 VALUES (?, ?, ?, 'saida', ?, ?, ?, ?, ?, ?, ?, NOW())"
            )->execute([
                $produtoId,
                $osId,
                $equipIdx,
                $qtd,
                $saldoAnt,
                $saldoPos,
                "Saída por retirada — OS #{$osId} equip. #{$equipIdx} item técnico #{$tecnicoItemId}",
                $operadorId,
                $origemTipo,
                $origemId,
            ]);

            $itensBaixados++;
        }

        return $itensBaixados;
    }

    /**
     * Registra a retirada de um equipamento específico (Etapa 7B / 7D.1).
     *
     * Regras financeiras (Etapa 7D.1):
     *   - Cada equipamento com orçamento aprovado e total > 0 tem o seu próprio
     *     lancamentos_receber (criado em concluir()). A quitação ocorre no momento
     *     da retirada de cada equipamento, não em lote no encerramento da OS.
     *   - forma_pagamento obrigatória quando o orçamento do equipamento tem total > 0.
     *   - Equipamentos sem cobrança (total = 0 ou sem orçamento aprovado) não exigem
     *     forma_pagamento e não movimentam financeiro.
     *   - Encerramento da OS (último equip) não executa quitação em massa — todos os
     *     lançamentos já foram quitados individualmente por equipamento.
     *
     * Limitações conhecidas:
     *   - data_retirada e operador_retirada_id existem apenas em ordem_servico
     *     (modelo por equip reservado para etapa futura).
     *   - numero_pedido é gravado na OS ao encerrar; por equip não disponível ainda.
     */
    public function retirarEquipamento(
        string  $osId,
        int     $equipIdx,
        int     $operadorId,
        ?string $formaPagamento  = null, // Obrigatória quando equip tem orçamento aprovado com total > 0
        ?string $numeroPedido    = null,
        ?string $retiradoPorNome = null,
        float   $descontoValor   = 0.0,
    ): array {
        if ($descontoValor < 0.0) {
            throw new InvalidArgumentException('Desconto não pode ser negativo.');
        }
        // Valida formato se fornecida; obrigatoriedade por valor é verificada no passo 6.5.
        if ($formaPagamento !== null && !in_array($formaPagamento, self::FORMAS_PAGAMENTO, true)) {
            throw new InvalidArgumentException("Forma de pagamento inválida: {$formaPagamento}");
        }

        $itensBaixados = 0;
        $osEncerrada   = false;

        $this->pdo->beginTransaction();
        try {
            // ── 1. Travar e validar OS ────────────────────────────────────────
            $stOs = $this->pdo->prepare(
                "SELECT id, status, cliente_id, nome_cliente
                   FROM ordem_servico
                  WHERE id = ?
                  LIMIT 1 FOR UPDATE"
            );
            $stOs->execute([$osId]);
            $os = $stOs->fetch(PDO::FETCH_ASSOC);

            if (!$os) {
                throw new DomainException("OS #{$osId} não encontrada.");
            }
            if (in_array($os['status'], ['cancelado', 'retirado', 'descartado'], true)) {
                throw new DomainException(
                    "OS #{$osId} está encerrada/cancelada (status: {$os['status']}) " .
                    "e não permite mais movimentações de retirada."
                );
            }

            // ── 2. Travar e validar equipamento ──────────────────────────────
            $stEq = $this->pdo->prepare(
                "SELECT id, status_equip, nome
                   FROM os_equipamento
                  WHERE os_id = ? AND ordem_idx = ?
                  LIMIT 1 FOR UPDATE"
            );
            $stEq->execute([$osId, $equipIdx]);
            $equip = $stEq->fetch(PDO::FETCH_ASSOC);

            if (!$equip) {
                throw new DomainException("Equipamento #{$equipIdx} não encontrado na OS #{$osId}.");
            }
            if ($equip['status_equip'] === 'retirado') {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) já foi retirado anteriormente."
                );
            }
            if ($equip['status_equip'] !== 'pronto') {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) não está pronto para retirada " .
                    "(status atual: {$equip['status_equip']})."
                );
            }

            // ── 3. Ler orçamento vinculado ao equipamento ─────────────────────
            $stOrc = $this->pdo->prepare(
                "SELECT id, status, total
                   FROM orcamentos
                  WHERE os_id = ? AND equip_idx = ?
                  LIMIT 1"
            );
            $stOrc->execute([$osId, $equipIdx]);
            $orc = $stOrc->fetch(PDO::FETCH_ASSOC) ?: null;

            // ── 3.5 Validar orçamento aprovado — bloquear retirada sem aprovação ─
            // 9H-4: apenas 'aprovado' — 'pronto' foi removido do modelo comercial de orcamentos.
            // Bloqueia: null (sem orçamento), rascunho, enviado, cancelado.
            if ($orc === null || (string) $orc['status'] !== 'aprovado') {
                throw new DomainException(
                    'Este equipamento precisa ter orçamento aprovado para retirada. ' .
                    'Aprove o orçamento antes da retirada.'
                );
            }

            // ── 4. Marcar equipamento como retirado ───────────────────────────
            $this->pdo->prepare(
                "UPDATE os_equipamento
                    SET status_equip    = 'retirado',
                        status_equip_em = NOW()
                  WHERE os_id = ? AND ordem_idx = ?
                  LIMIT 1"
            )->execute([$osId, $equipIdx]);

            // ── 5. Registrar quem retirou no orçamento ────────────────────────
            if ($orc) {
                $nome = ($retiradoPorNome !== '' && $retiradoPorNome !== null)
                    ? $retiradoPorNome
                    : ('Operador #' . $operadorId);
                $this->pdo->prepare(
                    "UPDATE orcamentos SET retirado_por = ? WHERE id = ? LIMIT 1"
                )->execute([$nome, (int) $orc['id']]);
            }

            // ── 6. Baixar estoque dos itens deste equipamento ─────────────────
            $itensBaixados = $this->baixarEstoqueItensEquipamento($osId, $equipIdx, $operadorId);

            // ── 6.5 Quitação financeira do equipamento ────────────────────────
            // Executada imediatamente para cada equipamento com cobrança — não aguarda o último.
            // 9H-4: apenas 'aprovado' — 'pronto' foi removido do modelo comercial de orcamentos.
            if ($orc !== null && (string) $orc['status'] === 'aprovado' && (float) $orc['total'] > 0.0) {
                // 10D-3: busca o lançamento antes de exigir forma_pagamento, pois se já
                // está 'pago' (pagamento antecipado via 10D-1) não há novo pagamento a processar.
                $stLancEq = $this->pdo->prepare(
                    "SELECT id, status FROM lancamentos_receber WHERE orcamento_id = ? LIMIT 1"
                );
                $stLancEq->execute([(int) $orc['id']]);
                $lancEq = $stLancEq->fetch(PDO::FETCH_ASSOC) ?: null;

                $lancamentoJaPago = $lancEq !== null
                    && in_array((string) $lancEq['status'], ['pago', 'aguardando_fatura'], true);

                // Forma de pagamento obrigatória apenas quando ainda há cobrança a processar.
                if (!$lancamentoJaPago && $formaPagamento === null) {
                    throw new DomainException(
                        'Forma de pagamento obrigatória: o orçamento deste equipamento tem ' .
                        'R$ ' . number_format((float) $orc['total'], 2, ',', '.') . ' a receber.'
                    );
                }

                if ($lancEq === null) {
                    // Retirada parcial com OS ainda em andamento: cria o lançamento agora.
                    // A UNIQUE KEY uq_orc_lancamento(orcamento_id) garante idempotência.
                    $descricao = "OS #{$osId} equip.{$equipIdx} — " . ($os['nome_cliente'] ?? 'Cliente');
                    $this->pdo->prepare(
                        "INSERT INTO lancamentos_receber
                           (os_id, equip_idx, orcamento_id, cliente_id, valor, vencimento, status, descricao, criado_em)
                         VALUES (?, ?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'aberto', ?, NOW())"
                    )->execute([
                        $osId,
                        $equipIdx,
                        (int) $orc['id'],
                        (int) $os['cliente_id'],
                        (float) $orc['total'],
                        $descricao,
                    ]);
                    $lancEq = ['id' => (int) $this->pdo->lastInsertId(), 'status' => 'aberto'];
                }

                // Validar desconto contra o total do orçamento deste equipamento.
                if ($descontoValor > (float) $orc['total']) {
                    throw new InvalidArgumentException(
                        'Desconto (R$ ' . number_format($descontoValor, 2, ',', '.') . ') ' .
                        'não pode ser maior que o valor do orçamento ' .
                        '(R$ ' . number_format((float) $orc['total'], 2, ',', '.') . ').'
                    );
                }

                // Quitar apenas se ainda estiver em aberto (idempotência: não duplicar).
                if ($lancEq['status'] === 'aberto') {
                    $lancId = (int) $lancEq['id'];
                    if ($formaPagamento === 'faturado') {
                        $this->pdo->prepare(
                            "UPDATE lancamentos_receber
                                SET status = 'aguardando_fatura',
                                    desconto_valor = ?,
                                    forma_pagamento = 'faturado'
                              WHERE id = ? LIMIT 1"
                        )->execute([$descontoValor, $lancId]);
                    } else {
                        $this->pdo->prepare(
                            "UPDATE lancamentos_receber
                                SET status = 'pago',
                                    desconto_valor = ?,
                                    valor_pago = valor - ?,
                                    data_pagamento = CURDATE(),
                                    forma_pagamento = ?
                              WHERE id = ? LIMIT 1"
                        )->execute([$descontoValor, $descontoValor, $formaPagamento, $lancId]);
                    }

                    // Reflexo: marcar orçamento como pago.
                    $this->pdo->prepare(
                        "UPDATE orcamentos SET pago = 1 WHERE id = ? LIMIT 1"
                    )->execute([(int) $orc['id']]);
                }
                // Se já pago/aguardando_fatura: não duplicar — retirada continua normalmente.
            }
            // Se total = 0 ou orçamento não aprovado: sem cobrança, sem lançamento, sem bloqueio.

            // ── 7. Verificar se é o último equipamento ─────────────────────────
            // Estados terminais físicos: retirado, devolvido, descartado.
            // 'cancelado' NÃO conta — equipamento cancelado ainda aguarda destino físico.
            $stPend = $this->pdo->prepare(
                "SELECT COUNT(*) FROM os_equipamento
                  WHERE os_id = ? AND status_equip NOT IN ('retirado','devolvido','descartado')"
            );
            $stPend->execute([$osId]);
            $pendentes = (int) $stPend->fetchColumn();

            if ($pendentes === 0) {
                // Último equipamento retirado — encerrar a OS.
                $osEncerrada = true;

                // Guarda de segurança: nenhum lançamento desta OS deve permanecer aberto.
                // Com o modelo por equipamento, todos já foram quitados em 6.5.
                $stOpenLancs = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM lancamentos_receber WHERE os_id = ? AND status = 'aberto'"
                );
                $stOpenLancs->execute([$osId]);
                $abertos = (int) $stOpenLancs->fetchColumn();
                if ($abertos > 0) {
                    throw new DomainException(
                        "Existem {$abertos} lançamento(s) financeiro(s) ainda em aberto para esta OS. " .
                        "Verifique o fluxo de conclusão antes de encerrar."
                    );
                }

                // Encerrar OS — financeiro já quitado por equipamento em 6.5.
                $this->pdo->prepare(
                    "UPDATE ordem_servico
                        SET status = 'retirado',
                            data_retirada = NOW(),
                            forma_pagamento = ?,
                            numero_pedido = ?,
                            operador_retirada_id = ?
                      WHERE id = ?
                      LIMIT 1"
                )->execute([$formaPagamento, $numeroPedido, $operadorId, $osId]);
            }

            $this->pdo->commit();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return [
            'os_id'          => $osId,
            'equip_idx'      => $equipIdx,
            'itens_baixados' => $itensBaixados,
            'os_encerrada'   => $osEncerrada,
        ];
    }

    /**
     * Registra a devolução física de um equipamento ao cliente (Etapa 9C-2).
     *
     * Pré-condição:
     *   - status_equip = 'cancelado' (orçamento recusado, destino ainda não finalizado).
     *
     * Efeitos:
     *   - status_equip → 'devolvido'
     *   - devolucao_em  = NOW()
     *   - devolucao_uid = $usuarioId
     *   - Se todos os equipamentos da OS estiverem em estado terminal:
     *     encerra a OS automaticamente:
     *       → 'retirado'  se algum equipamento foi efetivamente retirado após conserto
     *       → 'cancelado' se nenhum foi retirado (todos cancelados/devolvidos/descartados)
     *
     * NÃO altera: estoque, financeiro, orçamentos, outros equipamentos.
     */
        /**
     * Desfaz a retirada de um equipamento.
     *
     * Efeitos:
     *   - status_equip → 'pronto'
     *   - orcamentos: pago = 0, retirado_por = null
     *   - estoque: gera movimentação de 'entrada' estornando os itens
     *   - financeiro: reverte o lancamentos_receber (se houver) para 'aberto' e zera os valores pagos.
     *   - OS: se a OS estiver 'retirado' (pois este foi o último), volta para 'pronto' limpando os dados de retirada.
     *   - OS obs_int: adiciona a justificativa da reversão.
     */
    public function desfazerRetiradaEquipamento(
        string $osId,
        int    $equipIdx,
        int    $operadorId,
        string $justificativa
    ): array {
        $justificativa = trim($justificativa);
        if ($justificativa === '') {
            throw new InvalidArgumentException("A justificativa é obrigatória para desfazer uma retirada.");
        }

        $itensEstornados = 0;
        $osReaberta      = false;

        $this->pdo->beginTransaction();
        try {
            // ── 1. Travar e validar OS ────────────────────────────────────────
            $stOs = $this->pdo->prepare(
                "SELECT id, status
                   FROM ordem_servico
                  WHERE id = ?
                  LIMIT 1 FOR UPDATE"
            );
            $stOs->execute([$osId]);
            $os = $stOs->fetch(PDO::FETCH_ASSOC);

            if (!$os) {
                throw new DomainException("OS #{$osId} não encontrada.");
            }
            if (in_array($os['status'], ['cancelado', 'descartado'], true)) {
                throw new DomainException(
                    "OS #{$osId} está com status global {$os['status']}, impossível desfazer retirada individual."
                );
            }

            // ── 2. Travar e validar equipamento ──────────────────────────────
            $stEq = $this->pdo->prepare(
                "SELECT id, status_equip, nome
                   FROM os_equipamento
                  WHERE os_id = ? AND ordem_idx = ?
                  LIMIT 1 FOR UPDATE"
            );
            $stEq->execute([$osId, $equipIdx]);
            $equip = $stEq->fetch(PDO::FETCH_ASSOC);

            if (!$equip) {
                throw new DomainException("Equipamento #{$equipIdx} não encontrado na OS #{$osId}.");
            }
            if ($equip['status_equip'] !== 'retirado') {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) não está 'retirado' (status atual: {$equip['status_equip']})."
                );
            }

            // ── 3. Ler orçamento vinculado ao equipamento ─────────────────────
            $stOrc = $this->pdo->prepare(
                "SELECT id, status, total
                   FROM orcamentos
                  WHERE os_id = ? AND equip_idx = ?
                  LIMIT 1 FOR UPDATE"
            );
            $stOrc->execute([$osId, $equipIdx]);
            $orc = $stOrc->fetch(PDO::FETCH_ASSOC) ?: null;

            // ── 4. Marcar equipamento como pronto ───────────────────────────
            $this->pdo->prepare(
                "UPDATE os_equipamento
                    SET status_equip = 'pronto'
                  WHERE os_id = ? AND ordem_idx = ?
                  LIMIT 1"
            )->execute([$osId, $equipIdx]);

            // ── 5. Reverter pagamento no orçamento ────────────────────────
            if ($orc) {
                $this->pdo->prepare(
                    "UPDATE orcamentos SET pago = 0, retirado_por = NULL WHERE id = ? LIMIT 1"
                )->execute([(int) $orc['id']]);
            }

            // ── 6. Estornar estoque dos itens deste equipamento ─────────────────
            $itens = $this->itensRepo->listarComProdutoPorEquipamento($osId, $equipIdx);
            foreach ($itens as $item) {
                $tecnicoItemId = (int) ($item['id'] ?? 0);
                $produtoId = (int) $item['produto_id'];
                $qtd       = (float) $item['qtd'];
                if ($tecnicoItemId <= 0 || $produtoId <= 0 || $qtd <= 0) continue;
                if ($this->orcRepo->tecnicoItemFornecidoCliente($osId, $equipIdx, $item)) {
                    continue;
                }

                $stProd = $this->pdo->prepare(
                    "SELECT estoque_qty, controla_estoque
                       FROM produtos WHERE id = ? LIMIT 1 FOR UPDATE"
                );
                $stProd->execute([$produtoId]);
                $prod = $stProd->fetch(PDO::FETCH_ASSOC);
                if ($prod === false) continue;

                if (!(int) $prod['controla_estoque']) {
                    continue;
                }

                $origemTipo = 'os_equipamento_item';
                $origemId = (string) $tecnicoItemId;

                $stMovEstorno = $this->pdo->prepare(
                    "SELECT id
                       FROM estoque_movimentacoes
                      WHERE origem_tipo = ?
                        AND origem_id = ?
                        AND tipo = 'entrada'
                      LIMIT 1
                      FOR UPDATE"
                );
                $stMovEstorno->execute([$origemTipo, $origemId]);
                if ($stMovEstorno->fetchColumn() !== false) {
                    continue;
                }

                $saldoAnt = (float) $prod['estoque_qty'];
                $saldoPos = $saldoAnt + $qtd;

                $this->pdo->prepare(
                    "UPDATE produtos SET estoque_qty = ? WHERE id = ? LIMIT 1"
                )->execute([$saldoPos, $produtoId]);

                $this->pdo->prepare(
                    "INSERT INTO estoque_movimentacoes
                       (produto_id, os_id, equip_idx, tipo, qtd, saldo_ant, saldo_pos,
                        descricao, usuario_id, origem_tipo, origem_id, criado_em)
                     VALUES (?, ?, ?, 'entrada', ?, ?, ?, ?, ?, ?, ?, NOW())"
                )->execute([
                    $produtoId,
                    $osId,
                    $equipIdx,
                    $qtd,
                    $saldoAnt,
                    $saldoPos,
                    "Estorno (Desfazer Retirada) — OS #{$osId} equip. #{$equipIdx} item técnico #{$tecnicoItemId}",
                    $operadorId,
                    $origemTipo,
                    $origemId,
                ]);

                $itensEstornados++;
            }

            // ── 6.5 Estornar financeiro do equipamento ────────────────────────
            if ($orc !== null) {
                // Localizar o lançamento específico deste orçamento.
                $stLancEq = $this->pdo->prepare(
                    "SELECT id, status FROM lancamentos_receber WHERE orcamento_id = ? LIMIT 1 FOR UPDATE"
                );
                $stLancEq->execute([(int) $orc['id']]);
                $lancEq = $stLancEq->fetch(PDO::FETCH_ASSOC) ?: null;

                if ($lancEq !== null && in_array($lancEq['status'], ['pago', 'aguardando_fatura'], true)) {
                    $this->pdo->prepare(
                        "UPDATE lancamentos_receber
                            SET status = 'aberto',
                                desconto_valor = 0,
                                valor_pago = 0,
                                data_pagamento = NULL,
                                forma_pagamento = NULL
                          WHERE id = ? LIMIT 1"
                    )->execute([(int) $lancEq['id']]);
                }
            }

            // ── 7. Se a OS estava 'retirado', reabrir para 'pronto' ───────────
            if ($os['status'] === 'retirado') {
                $osReaberta = true;
                $this->pdo->prepare(
                    "UPDATE ordem_servico
                        SET status = 'pronto',
                            data_retirada = NULL,
                            forma_pagamento = NULL,
                            numero_pedido = NULL,
                            operador_retirada_id = NULL
                      WHERE id = ?
                      LIMIT 1"
                )->execute([$osId]);
            }

            $this->pdo->commit();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return [
            'os_id'            => $osId,
            'equip_idx'        => $equipIdx,
            'itens_estornados' => $itensEstornados,
            'os_reaberta'      => $osReaberta,
        ];
    }


    public function devolverEquipamento(string $osId, int $equipIdx, int $usuarioId): array
    {
        $this->pdo->beginTransaction();
        try {
            // 1. Travar e validar OS
            $stOs = $this->pdo->prepare(
                "SELECT id, status FROM ordem_servico WHERE id = ? LIMIT 1 FOR UPDATE"
            );
            $stOs->execute([$osId]);
            $os = $stOs->fetch(PDO::FETCH_ASSOC);

            if (!$os) {
                throw new DomainException("OS #{$osId} não encontrada.");
            }
            if (in_array($os['status'], ['retirado', 'descartado'], true)) {
                throw new DomainException(
                    "OS #{$osId} já está encerrada (status: {$os['status']}) e não permite devolução."
                );
            }

            // 2. Travar e validar equipamento
            $stEq = $this->pdo->prepare(
                "SELECT id, status_equip, nome, descarte_autorizado_em FROM os_equipamento
                  WHERE os_id = ? AND ordem_idx = ? LIMIT 1 FOR UPDATE"
            );
            $stEq->execute([$osId, $equipIdx]);
            $equip = $stEq->fetch(PDO::FETCH_ASSOC);

            if (!$equip) {
                throw new DomainException("Equipamento #{$equipIdx} não encontrado na OS #{$osId}.");
            }
            if ($equip['status_equip'] === 'devolvido') {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) já foi devolvido anteriormente."
                );
            }
            if (!empty($equip['descarte_autorizado_em'])) {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) possui descarte autorizado. " .
                    "Cancele ou resolva o descarte antes de devolver."
                );
            }
            $statusEquipAtual = $equip['status_equip'];
            if ($statusEquipAtual === 'pronto') {
                // Aceita 'pronto' apenas se o orçamento do equipamento está cancelado
                // (técnico remontou para devolução após cancelamento do conserto).
                $stOrc = $this->pdo->prepare(
                    "SELECT id FROM orcamentos WHERE os_id = ? AND equip_idx = ? AND status = 'cancelado' LIMIT 1"
                );
                $stOrc->execute([$osId, $equipIdx]);
                if (!$stOrc->fetch(PDO::FETCH_ASSOC)) {
                    throw new DomainException(
                        "Equipamento #{$equipIdx} ({$equip['nome']}) está pronto para conserto aprovado. " .
                        "Use a opção de Retirada, não de Devolução."
                    );
                }
                // pronto + orç cancelado → remontagem para devolução concluída → permitido
            } elseif ($statusEquipAtual !== 'cancelado') {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) não pode ser devolvido: " .
                    "status atual '{$statusEquipAtual}'. " .
                    "Apenas equipamentos cancelados ou prontos com orçamento cancelado podem ser devolvidos."
                );
            }

            // 3. Marcar como devolvido com rastreamento
            $this->pdo->prepare(
                "UPDATE os_equipamento
                    SET status_equip    = 'devolvido',
                        status_equip_em = NOW(),
                        devolucao_em    = NOW(),
                        devolucao_uid   = ?
                  WHERE os_id = ? AND ordem_idx = ? LIMIT 1"
            )->execute([$usuarioId, $osId, $equipIdx]);

            // 4. Verificar se todos os equipamentos estão em estado terminal físico
            // 'cancelado' NÃO conta — equipamento cancelado ainda aguarda destino físico.
            $stPend = $this->pdo->prepare(
                "SELECT COUNT(*) FROM os_equipamento
                  WHERE os_id = ? AND status_equip NOT IN ('retirado','devolvido','descartado')"
            );
            $stPend->execute([$osId]);
            $pendentes = (int) $stPend->fetchColumn();

            $osEncerrada   = false;
            $osStatusFinal = null;

            if ($pendentes === 0) {
                $osEncerrada = true;

                // Regra de closure:
                // 1. Algum retirado → OS = 'retirado'
                // 2. Nenhum retirado, algum devolvido → OS = 'cancelado' (mix sem serviço prestado)
                // 3. Nenhum retirado, nenhum devolvido (todos descartados) → OS = 'descartado'
                $stTemRetirado = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM os_equipamento WHERE os_id = ? AND status_equip = 'retirado'"
                );
                $stTemRetirado->execute([$osId]);
                $temRetirado = (int) $stTemRetirado->fetchColumn() > 0;

                if ($temRetirado) {
                    $osStatusFinal = 'retirado';
                } else {
                    $stTemDevolvido = $this->pdo->prepare(
                        "SELECT COUNT(*) FROM os_equipamento WHERE os_id = ? AND status_equip = 'devolvido'"
                    );
                    $stTemDevolvido->execute([$osId]);
                    $temDevolvido  = (int) $stTemDevolvido->fetchColumn() > 0;
                    $osStatusFinal = $temDevolvido ? 'cancelado' : 'descartado';
                }

                $this->pdo->prepare(
                    "UPDATE ordem_servico SET status = ? WHERE id = ? LIMIT 1"
                )->execute([$osStatusFinal, $osId]);
            }

            $this->pdo->commit();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return [
            'os_id'           => $osId,
            'equip_idx'       => $equipIdx,
            'os_encerrada'    => $osEncerrada,
            'os_status_final' => $osStatusFinal,
        ];
    }

    /**
     * Meios válidos para autorização de descarte.
     */
    public const DESCARTE_MEIOS = ['presencial', 'telefone', 'whatsapp', 'email'];

    /**
     * Registra a autorização do cliente para descarte de um equipamento (Etapa 9C-3).
     *
     * Esta etapa NÃO confirma o descarte físico nem muda status_equip para 'descartado'.
     * Apenas registra a autorização e notifica o técnico para que realize o descarte.
     *
     * Pré-condições:
     *   - status_equip = 'cancelado' OU existe orçamento cancelado para o equipamento.
     *   - Não existe orçamento aprovado para o equipamento.
     *   - Não existe lançamento financeiro aberto para o equipamento.
     *   - descarte_autorizado_em ainda não preenchido (sem duplicatas).
     *   - OS não está em estado terminal definitivo (retirado/descartado).
     *   - Equipamento não está em estado terminal (retirado/devolvido/descartado).
     *
     * Efeitos:
     *   - descarte_autorizado_em  = NOW()
     *   - descarte_autorizado_por = $autorizadoPor
     *   - descarte_autorizado_uid = $usuarioId
     *   - descarte_meio           = $meio
     *   - status_equip NÃO muda (permanece 'cancelado' até confirmação física futura).
     *   - Notificação tipo 'descarte' criada para o técnico.
     *
     * NÃO altera: status_equip, estoque, financeiro, outros equipamentos.
     */
    public function autorizarDescarteEquipamento(
        string $osId,
        int    $equipIdx,
        int    $usuarioId,
        string $autorizadoPor,
        string $meio
    ): array {
        $autorizadoPor = trim($autorizadoPor);
        $meio          = trim($meio);

        if ($autorizadoPor === '') {
            throw new InvalidArgumentException('O nome de quem autorizou é obrigatório.');
        }
        if (!in_array($meio, self::DESCARTE_MEIOS, true)) {
            throw new InvalidArgumentException(
                'Meio de autorização inválido. Use: ' . implode(', ', self::DESCARTE_MEIOS)
            );
        }

        $this->pdo->beginTransaction();
        try {
            // 1. Travar e validar OS
            $stOs = $this->pdo->prepare(
                "SELECT id, status FROM ordem_servico WHERE id = ? LIMIT 1 FOR UPDATE"
            );
            $stOs->execute([$osId]);
            $os = $stOs->fetch(PDO::FETCH_ASSOC);

            if (!$os) {
                throw new DomainException("OS #{$osId} não encontrada.");
            }
            if (in_array($os['status'], ['retirado', 'descartado'], true)) {
                throw new DomainException(
                    "OS #{$osId} está encerrada (status: {$os['status']}) e não permite novos registros."
                );
            }

            // 2. Travar e validar equipamento
            $stEq = $this->pdo->prepare(
                "SELECT id, status_equip, nome, descarte_autorizado_em FROM os_equipamento
                  WHERE os_id = ? AND ordem_idx = ? LIMIT 1 FOR UPDATE"
            );
            $stEq->execute([$osId, $equipIdx]);
            $equip = $stEq->fetch(PDO::FETCH_ASSOC);

            if (!$equip) {
                throw new DomainException("Equipamento #{$equipIdx} não encontrado na OS #{$osId}.");
            }
            if (in_array($equip['status_equip'], ['retirado', 'devolvido', 'descartado'], true)) {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) já está em estado terminal " .
                    "({$equip['status_equip']}) e não pode receber autorização de descarte."
                );
            }
            if (!empty($equip['descarte_autorizado_em'])) {
                throw new DomainException(
                    "Descarte já autorizado para este equipamento ({$equip['nome']})."
                );
            }

            // 3. Verificar orçamento aprovado (bloqueia) e cancelado (permite)
            $stOrc = $this->pdo->prepare(
                "SELECT status FROM orcamentos WHERE os_id = ? AND equip_idx = ? LIMIT 1"
            );
            $stOrc->execute([$osId, $equipIdx]);
            $orc = $stOrc->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($orc !== null && (string) $orc['status'] === 'aprovado') {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) possui orçamento aprovado. " .
                    "Cancele o orçamento antes de registrar autorização de descarte."
                );
            }

            // Permitir somente se equip cancelado OU orçamento cancelado
            $equipCancelado = ($equip['status_equip'] === 'cancelado');
            $orcCancelado   = ($orc !== null && (string) $orc['status'] === 'cancelado');
            if (!$equipCancelado && !$orcCancelado) {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) não está em condições de descarte. " .
                    "O equipamento ou seu orçamento deve estar cancelado."
                );
            }

            // 4. Verificar lançamento financeiro ativo para este equipamento
            $stLanc = $this->pdo->prepare(
                "SELECT COUNT(*) FROM lancamentos_receber
                  WHERE os_id = ? AND equip_idx = ? AND status = 'aberto'"
            );
            $stLanc->execute([$osId, $equipIdx]);
            if ((int) $stLanc->fetchColumn() > 0) {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) possui lançamento financeiro em aberto. " .
                    "Quite ou cancele o lançamento antes de registrar autorização de descarte."
                );
            }

            // 5. Gravar autorização (apenas neste equipamento)
            $this->pdo->prepare(
                "UPDATE os_equipamento
                    SET descarte_autorizado_em  = NOW(),
                        descarte_autorizado_por = ?,
                        descarte_autorizado_uid = ?,
                        descarte_meio           = ?
                  WHERE os_id = ? AND ordem_idx = ? LIMIT 1"
            )->execute([$autorizadoPor, $usuarioId, $meio, $osId, $equipIdx]);

            $this->pdo->commit();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        // 6. Notificar técnico (best-effort fora da transação)
        try {
            // Descarta notificação de "remontar para devolução" caso já exista
            // (cenário: cancelamento de orçamento antes da autorização de descarte).
            $this->notifRepo->marcarLidasPorOsEquipTipo($osId, $equipIdx, 'cancelado');
            $this->notifRepo->criar(
                $osId,
                $equipIdx,
                'descarte',
                'Cliente autorizou descarte — realizar descarte do equipamento.'
            );
        } catch (Throwable $e) {
            error_log("[autorizarDescarteEquipamento] Falha ao notificar técnico OS={$osId} equip={$equipIdx}: " . $e->getMessage());
        }

        return [
            'os_id'                 => $osId,
            'equip_idx'             => $equipIdx,
            'descarte_autorizado_em' => date('Y-m-d H:i:s'),
            'descarte_autorizado_por' => $autorizadoPor,
            'descarte_meio'         => $meio,
        ];
    }

    /**
     * Confirma a execução física do descarte de um equipamento (Etapa 9C-4).
     *
     * Pré-condições:
     *   - descarte_autorizado_em preenchido (autorização registrada em 9C-3).
     *   - status_equip não é ainda 'descartado' (idempotência — bloqueia duplicata).
     *   - Equipamento não está 'retirado' nem 'devolvido'.
     *   - OS não está em estado terminal definitivo.
     *
     * Efeitos:
     *   - status_equip → 'descartado'
     *   - descarte_executado_em  = NOW()
     *   - descarte_executado_uid = $usuarioId
     *   - Se todos os equipamentos em estado terminal: encerra OS.
     *       → 'retirado'  se algum equipamento foi efetivamente retirado.
     *       → 'cancelado' se nenhum foi retirado (todos cancelados/devolvidos/descartados).
     *
     * NÃO altera: estoque, financeiro, orçamentos, outros equipamentos.
     */
    public function confirmarDescarteEquipamento(string $osId, int $equipIdx, int $usuarioId): array
    {
        $this->pdo->beginTransaction();
        try {
            // 1. Travar e validar OS
            $stOs = $this->pdo->prepare(
                "SELECT id, status FROM ordem_servico WHERE id = ? LIMIT 1 FOR UPDATE"
            );
            $stOs->execute([$osId]);
            $os = $stOs->fetch(PDO::FETCH_ASSOC);

            if (!$os) {
                throw new DomainException("OS #{$osId} não encontrada.");
            }
            if (in_array($os['status'], ['retirado', 'descartado'], true)) {
                throw new DomainException(
                    "OS #{$osId} já está encerrada (status: {$os['status']}) e não permite movimentações."
                );
            }

            // 2. Travar e validar equipamento
            $stEq = $this->pdo->prepare(
                "SELECT id, status_equip, nome, descarte_autorizado_em, descarte_executado_em
                   FROM os_equipamento
                  WHERE os_id = ? AND ordem_idx = ? LIMIT 1 FOR UPDATE"
            );
            $stEq->execute([$osId, $equipIdx]);
            $equip = $stEq->fetch(PDO::FETCH_ASSOC);

            if (!$equip) {
                throw new DomainException("Equipamento #{$equipIdx} não encontrado na OS #{$osId}.");
            }
            if ($equip['status_equip'] === 'descartado') {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) já foi descartado anteriormente."
                );
            }
            if (in_array($equip['status_equip'], ['retirado', 'devolvido'], true)) {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) está em estado terminal " .
                    "'{$equip['status_equip']}' e não pode ser descartado."
                );
            }
            if (empty($equip['descarte_autorizado_em'])) {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) não possui autorização de descarte registrada. " .
                    "Registre a autorização do cliente antes de confirmar o descarte."
                );
            }

            // 3. Marcar como descartado com rastreamento
            $this->pdo->prepare(
                "UPDATE os_equipamento
                    SET status_equip          = 'descartado',
                        status_equip_em       = NOW(),
                        descarte_executado_em  = NOW(),
                        descarte_executado_uid = ?
                  WHERE os_id = ? AND ordem_idx = ? LIMIT 1"
            )->execute([$usuarioId, $osId, $equipIdx]);

            // 4. Verificar se todos os equipamentos estão em estado terminal físico
            // 'cancelado' NÃO conta — equipamento cancelado ainda aguarda destino físico.
            $stPend = $this->pdo->prepare(
                "SELECT COUNT(*) FROM os_equipamento
                  WHERE os_id = ? AND status_equip NOT IN ('retirado','devolvido','descartado')"
            );
            $stPend->execute([$osId]);
            $pendentes = (int) $stPend->fetchColumn();

            $osEncerrada   = false;
            $osStatusFinal = null;

            if ($pendentes === 0) {
                $osEncerrada = true;

                // Regra de closure:
                // 1. Algum retirado → OS = 'retirado'
                // 2. Nenhum retirado, algum devolvido → OS = 'cancelado' (mix sem serviço prestado)
                // 3. Nenhum retirado, nenhum devolvido (todos descartados) → OS = 'descartado'
                $stTemRetirado = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM os_equipamento WHERE os_id = ? AND status_equip = 'retirado'"
                );
                $stTemRetirado->execute([$osId]);
                $temRetirado = (int) $stTemRetirado->fetchColumn() > 0;

                if ($temRetirado) {
                    $osStatusFinal = 'retirado';
                } else {
                    $stTemDevolvido = $this->pdo->prepare(
                        "SELECT COUNT(*) FROM os_equipamento WHERE os_id = ? AND status_equip = 'devolvido'"
                    );
                    $stTemDevolvido->execute([$osId]);
                    $temDevolvido  = (int) $stTemDevolvido->fetchColumn() > 0;
                    $osStatusFinal = $temDevolvido ? 'cancelado' : 'descartado';
                }

                $this->pdo->prepare(
                    "UPDATE ordem_servico SET status = ? WHERE id = ? LIMIT 1"
                )->execute([$osStatusFinal, $osId]);
            }

            $this->pdo->commit();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return [
            'os_id'           => $osId,
            'equip_idx'       => $equipIdx,
            'os_encerrada'    => $osEncerrada,
            'os_status_final' => $osStatusFinal,
        ];
    }

    /**
     * Gera registros em necessidades_compra para itens da OS cujo produto
     * está marcado como sob_encomenda. Idempotente — só cria pendência se
     * ainda não existe pendente para a mesma combinação OS + produto.
     */
    public function gerarNecessidadesDeCompra(string $osId): int
    {
        $itens = $this->itensRepo->listarItensComProdutoPorOs($osId);
        if (empty($itens)) return 0;

        $criados = 0;
        foreach ($itens as $item) {
            $produtoId = (int) $item['produto_id'];

            $stProd = $this->pdo->prepare(
                "SELECT sob_encomenda FROM produtos WHERE id = ? LIMIT 1"
            );
            $stProd->execute([$produtoId]);
            $sob = (int) $stProd->fetchColumn();
            if ($sob !== 1) continue;

            $existe = $this->necessidadeRepo->existePendente($osId, $produtoId);
            if ($existe) continue;

            $this->necessidadeRepo->criar([
                'os_id'           => $osId,
                'equip_idx'       => (int) ($item['equip_idx'] ?? 0),
                'produto_id'      => $produtoId,
                'tecnico_item_id' => (int) $item['id'],
                'codigo'          => (string) ($item['codigo'] ?? ''),
                'descricao'       => (string) ($item['descricao'] ?? ''),
                'qtd'             => (float) ($item['qtd'] ?? 1),
            ]);
            $criados++;
        }
        return $criados;
    }

    /**
     * Reabre uma OS que foi concluída indevidamente.
     */
    public function reabrir(string $osId): void
    {
        $this->pdo->beginTransaction();
        try {
            // 10D-2: bloquear reabertura se existir qualquer lançamento com status='pago'.
            // Cancelar um pagamento real silenciosamente causaria inconsistência financeira.
            // O operador deve fazer o estorno financeiro manualmente antes de reabrir.
            $stPago = $this->pdo->prepare(
                "SELECT COUNT(*)
                   FROM lancamentos_receber
                  WHERE os_id  = ?
                    AND status = 'pago'"
            );
            $stPago->execute([$osId]);
            if ((int) $stPago->fetchColumn() > 0) {
                throw new DomainException(
                    "Não é possível reabrir a OS #{$osId} porque há pagamento registrado. " .
                    "Faça o estorno financeiro antes de reabrir."
                );
            }

            $this->pdo->prepare(
                "UPDATE ordem_servico SET status = 'andamento', data_conclusao = NULL WHERE id = ? LIMIT 1"
            )->execute([$osId]);

            // Equipamentos mantêm seus status individuais ao reabrir — não cascateia.
            // Cancela TODOS os lançamentos da OS (sem LIMIT — pode haver N após migração 7D).
            // Seguro aqui: o guard acima garantiu que nenhum está 'pago'.
            $this->pdo->prepare(
                "UPDATE lancamentos_receber SET status = 'cancelado' WHERE os_id = ?"
            )->execute([$osId]);

            $this->pdo->prepare(
                "UPDATE notas_fiscais SET status = 'cancelada' WHERE os_id = ?"
            )->execute([$osId]);

            // Reseta reflexo financeiro nos orçamentos.
            // Seguro aqui: nenhum orçamento tem pago=1 real (guard acima protegeu).
            $this->pdo->prepare(
                "UPDATE orcamentos SET pago = 0 WHERE os_id = ?"
            )->execute([$osId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    // ── 10D-1: Pagamento antecipado ────────────────────────────────────────

    /**
     * Registra pagamento antecipado de um orçamento aprovado sem retirar o equipamento.
     *
     * Pré-condições obrigatórias:
     *   - OS existe e não está encerrada/cancelada/descartada.
     *   - Equipamento existe, status_equip = 'pronto'.
     *   - Orçamento existe, status = 'aprovado', total > 0, pago = 0.
     *
     * Efeitos:
     *   - Cria ou quita lancamentos_receber com forma_pagamento, valor_pago = total, data_pagamento = hoje.
     *   - Marca orcamentos.pago = 1.
     *
     * NÃO altera (garantia):
     *   - os_equipamento.status_equip — permanece 'pronto'.
     *   - produtos.estoque_qty / estoque_movimentacoes — sem baixa de estoque.
     *   - ordem_servico.status — OS permanece no status atual.
     *   - ordem_servico.data_retirada / operador_retirada_id.
     *   - orcamentos.retirado_por / data_retirada / status.
     *
     * Risco documentado:
     *   Se chamado antes de concluir() (sem lancamento_receber criado ainda), a conclusão
     *   administrativa posterior será bloqueada por assertSemFinanceiroAtivo() porque o
     *   lançamento recém-criado estará com status='pago' mas o equip ainda está 'pronto'.
     *   Correção prevista na Etapa 10D.2 (ajuste no assertSemFinanceiroAtivo e reabrir).
     *
     * 'faturado' bloqueado: contradiz a semântica de "pagar agora antes de retirar".
     *
     * @return array{os_id:string, equip_idx:int, orcamento_id:int, lancamento_id:int, pago:bool, forma_pagamento:string}
     */
    public function registrarPagamentoAntecipado(
        string $osId,
        int    $equipIdx,
        string $formaPagamento,
    ): array {
        $formasPermitidas = ['dinheiro', 'pix', 'cartao'];
        if (!in_array($formaPagamento, $formasPermitidas, true)) {
            throw new InvalidArgumentException(
                "Forma de pagamento inválida para pagamento antecipado: '{$formaPagamento}'. " .
                "Use: dinheiro, pix, cartao."
            );
        }

        $orcId  = 0;
        $lancId = 0;

        $this->pdo->beginTransaction();
        try {
            // ── 1. Travar e validar OS ────────────────────────────────────────
            $stOs = $this->pdo->prepare(
                "SELECT id, status, cliente_id, nome_cliente
                   FROM ordem_servico
                  WHERE id = ?
                  LIMIT 1 FOR UPDATE"
            );
            $stOs->execute([$osId]);
            $os = $stOs->fetch(PDO::FETCH_ASSOC);

            if (!$os) {
                throw new DomainException("OS #{$osId} não encontrada.");
            }
            if (in_array((string) $os['status'], ['retirado', 'descartado', 'cancelado'], true)) {
                throw new DomainException(
                    "OS #{$osId} está encerrada (status: {$os['status']}) e não permite movimentações."
                );
            }

            // ── 2. Travar e validar equipamento ──────────────────────────────
            $stEq = $this->pdo->prepare(
                "SELECT id, status_equip, nome
                   FROM os_equipamento
                  WHERE os_id = ? AND ordem_idx = ?
                  LIMIT 1 FOR UPDATE"
            );
            $stEq->execute([$osId, $equipIdx]);
            $equip = $stEq->fetch(PDO::FETCH_ASSOC);

            if (!$equip) {
                throw new DomainException("Equipamento #{$equipIdx} não encontrado na OS #{$osId}.");
            }
            $statusEquip = (string) $equip['status_equip'];
            if (in_array($statusEquip, ['retirado', 'devolvido', 'descartado', 'cancelado'], true)) {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) não permite pagamento antecipado " .
                    "(status: {$statusEquip})."
                );
            }
            if ($statusEquip !== 'pronto') {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) precisa estar pronto para " .
                    "receber pagamento antecipado (status atual: {$statusEquip})."
                );
            }

            // ── 3. Travar e validar orçamento ─────────────────────────────────
            $stOrc = $this->pdo->prepare(
                "SELECT id, status, total, pago
                   FROM orcamentos
                  WHERE os_id = ? AND equip_idx = ?
                  LIMIT 1 FOR UPDATE"
            );
            $stOrc->execute([$osId, $equipIdx]);
            $orc = $stOrc->fetch(PDO::FETCH_ASSOC);

            if (!$orc) {
                throw new DomainException(
                    "Orçamento não encontrado para o equipamento #{$equipIdx} da OS #{$osId}."
                );
            }
            if ((string) $orc['status'] !== 'aprovado') {
                throw new DomainException(
                    "O orçamento precisa estar aprovado para receber pagamento antecipado " .
                    "(status atual: {$orc['status']})."
                );
            }
            if ((float) $orc['total'] <= 0.0) {
                throw new DomainException(
                    "Orçamento com valor zero não requer pagamento."
                );
            }
            if ((int) $orc['pago'] === 1) {
                throw new DomainException("Este orçamento já está pago.");
            }

            $orcId     = (int) $orc['id'];
            $totalOrc  = (float) $orc['total'];
            $clienteId = (int) ($os['cliente_id'] ?? 0);

            // ── 4. Buscar lançamento existente (com lock) ─────────────────────
            $stLanc = $this->pdo->prepare(
                "SELECT id, status
                   FROM lancamentos_receber
                  WHERE orcamento_id = ?
                  LIMIT 1 FOR UPDATE"
            );
            $stLanc->execute([$orcId]);
            $lanc = $stLanc->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($lanc === null) {
                // Nenhum lançamento: criar direto como pago.
                // ⚠ RISCO 10D.2: concluir() posterior será bloqueado por assertSemFinanceiroAtivo().
                $descricao = "OS #{$osId} equip.{$equipIdx} — "
                           . ($os['nome_cliente'] ?? 'Cliente')
                           . ' (pago antecipado)';
                $this->pdo->prepare(
                    "INSERT INTO lancamentos_receber
                       (os_id, equip_idx, orcamento_id, cliente_id, valor, valor_pago, desconto_valor,
                        vencimento, status, data_pagamento, forma_pagamento, descricao, criado_em)
                     VALUES (?, ?, ?, ?, ?, ?, 0.00, CURDATE(), 'pago', CURDATE(), ?, ?, NOW())"
                )->execute([
                    $osId,
                    $equipIdx,
                    $orcId,
                    $clienteId,
                    $totalOrc,
                    $totalOrc,
                    $formaPagamento,
                    $descricao,
                ]);
                $lancId = (int) $this->pdo->lastInsertId();

            } elseif ((string) $lanc['status'] === 'aberto') {
                // Lançamento existente em aberto: quitar agora.
                $lancId = (int) $lanc['id'];
                $this->pdo->prepare(
                    "UPDATE lancamentos_receber
                        SET status          = 'pago',
                            valor_pago      = valor,
                            desconto_valor  = 0.00,
                            data_pagamento  = CURDATE(),
                            forma_pagamento = ?
                      WHERE id = ? LIMIT 1"
                )->execute([$formaPagamento, $lancId]);

            } elseif ((string) $lanc['status'] === 'pago') {
                throw new DomainException("Este orçamento já está pago.");

            } elseif ((string) $lanc['status'] === 'aguardando_fatura') {
                throw new DomainException(
                    "Este lançamento está aguardando fatura. " .
                    "Não é possível registrar pagamento antecipado."
                );

            } else {
                // 'cancelado' ou estado desconhecido — não reativar sem regra clara.
                throw new DomainException(
                    "Lançamento financeiro com status '{$lanc['status']}' não permite " .
                    "pagamento antecipado. Contate o administrador."
                );
            }

            // ── 5. Marcar orçamento como pago ─────────────────────────────────
            // Não altera: status, retirado_por, data_retirada, wpp_enviado_em.
            $this->pdo->prepare(
                "UPDATE orcamentos SET pago = 1 WHERE id = ? LIMIT 1"
            )->execute([$orcId]);

            // ── 6. Garantir nota fiscal pendente para este lançamento ─────────
            // concluir() cria notas_fiscais para cada lancamento que ele mesmo criou.
            // Quando o pagamento antecipado cria/quita o lançamento ANTES de concluir(),
            // concluir() pula esse orçamento (10D-2) e a nota_fiscal nunca é criada.
            // Criamos aqui para cobrir esse gap. Verificamos existência porque:
            //   a) quando o lançamento foi criado por concluir() e apenas quitado aqui,
            //      a nota_fiscal já existe (criada por concluir()) — não duplicar;
            //   b) notas_fiscais não tem UNIQUE em lancamento_id — checar explicitamente.
            $stNotaExiste = $this->pdo->prepare(
                "SELECT COUNT(*) FROM notas_fiscais
                  WHERE lancamento_id = ? AND status != 'cancelada'"
            );
            $stNotaExiste->execute([$lancId]);
            if ((int) $stNotaExiste->fetchColumn() === 0) {
                $this->pdo->prepare(
                    "INSERT INTO notas_fiscais
                       (os_id, lancamento_id, status, criado_em)
                     VALUES (?, ?, 'pendente', NOW())"
                )->execute([$osId, $lancId]);
            }

            // Confirmar: status_equip NÃO foi tocado; estoque NÃO foi tocado; OS NÃO foi encerrada.

            $this->pdo->commit();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'os_id'          => $osId,
            'equip_idx'      => $equipIdx,
            'orcamento_id'   => $orcId,
            'lancamento_id'  => $lancId,
            'pago'           => true,
            'forma_pagamento' => $formaPagamento,
        ];
    }

    /**
     * Gera ID no formato AAAAMMDD-NNN (ex: 20231025-001).
     */
    private function gerarIdOs(): string
    {
        $hoje = date('Ymd');
        $prefixo = $hoje . '-';

        $st = $this->pdo->prepare("SELECT id FROM ordem_servico WHERE id LIKE ? ORDER BY id DESC LIMIT 1");
        $st->execute([$prefixo . '%']);
        $ultimoId = $st->fetchColumn();

        if ($ultimoId) {
            $seq = (int) substr($ultimoId, 9);
            $seq++;
        } else {
            $seq = 1;
        }

        return $prefixo . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
    }

    private function assertOrcamentosComValorFinalizadosParaConclusao(string $osId): void
    {
        // Exclui orçamentos de equipamentos já retirados — esses são sempre 'aprovado'
        // (exigência de 9A-1), mas a exclusão é defensiva caso algum estado inválido exista.
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
               FROM orcamentos o
               JOIN os_equipamento eq
                 ON eq.os_id     = o.os_id
                AND eq.ordem_idx = o.equip_idx
              WHERE o.os_id  = ?
                AND o.total  > 0
                AND o.status NOT IN ('aprovado', 'cancelado')  -- 9H-4: pronto/retirado removidos do modelo
                AND eq.status_equip NOT IN ('retirado','devolvido','descartado')"
        );
        $stmt->execute([$osId]);
        $pendentes = (int) $stmt->fetchColumn();

        if ($pendentes > 0) {
            throw new DomainException(
                'Há orçamento(s) com valor pendente não aprovados. Aprove ou cancele antes de concluir.'
            );
        }
    }

    private function somarOrcamentosAprovados(string $osId): float
    {
        // Exclui orçamentos de equipamentos já retirados individualmente:
        // esses equipamentos foram faturados em retirarEquipamento() e não
        // devem ser processados novamente na conclusão administrativa da OS.
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(o.total), 0)
               FROM orcamentos o
               JOIN os_equipamento eq
                 ON eq.os_id     = o.os_id
                AND eq.ordem_idx = o.equip_idx
              WHERE o.os_id   = ?
                AND o.status  = 'aprovado'  -- 9H-4: pronto removido do modelo comercial
                AND eq.status_equip NOT IN ('retirado','devolvido','descartado')"
        );
        $stmt->execute([$osId]);
        return (float) $stmt->fetchColumn();
    }

    private function assertSemFinanceiroAtivo(string $osId): void
    {
        // Ignora lançamentos de equipamentos já retirados individualmente:
        // esses foram criados e quitados em retirarEquipamento() — são esperados
        // e não constituem duplicidade para a conclusão administrativa da OS.
        //
        // 10D-2: verifica apenas status='aberto' — lançamentos 'pago' (incluindo pagamento
        // antecipado de 10D-1) e 'aguardando_fatura' já têm destino financeiro definido
        // e não representam conflito para concluir(). Apenas um lançamento realmente em
        // aberto e sem equipamento retirado é sinal de fluxo inconsistente.
        $stmtReceber = $this->pdo->prepare(
            "SELECT COUNT(*)
               FROM lancamentos_receber lr
               LEFT JOIN os_equipamento eq
                 ON eq.os_id     = lr.os_id
                AND eq.ordem_idx = lr.equip_idx
              WHERE lr.os_id  = ?
                AND lr.status = 'aberto'
                AND (eq.status_equip IS NULL OR eq.status_equip NOT IN ('retirado','devolvido','descartado'))"
        );
        $stmtReceber->execute([$osId]);
        $lancamentosAtivos = (int) $stmtReceber->fetchColumn();

        if ($lancamentosAtivos > 0) {
            throw new DomainException(
                "Já existe lançamento financeiro em aberto para a OS #{$osId}. Reabra e revise o fluxo antes de concluir novamente."
            );
        }

        // Ignora NFS-e vinculadas a lançamentos de equipamentos já retirados.
        $stmtNotas = $this->pdo->prepare(
            "SELECT COUNT(*)
               FROM notas_fiscais nf
               JOIN lancamentos_receber lr ON lr.id = nf.lancamento_id
               LEFT JOIN os_equipamento eq
                 ON eq.os_id     = lr.os_id
                AND eq.ordem_idx = lr.equip_idx
              WHERE nf.os_id  = ?
                AND nf.status != 'cancelada'
                AND (eq.status_equip IS NULL OR eq.status_equip NOT IN ('retirado','devolvido','descartado'))"
        );
        $stmtNotas->execute([$osId]);
        $notasAtivas = (int) $stmtNotas->fetchColumn();

        if ($notasAtivas > 0) {
            throw new DomainException(
                "Já existe NFS-e ativa vinculada à OS #{$osId}. Reabra e revise o fluxo antes de concluir novamente."
            );
        }
    }

    private function existeLancamentoReceberEmAberto(string $osId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
               FROM lancamentos_receber
              WHERE os_id = ?
                AND status = 'aberto'"
        );
        $stmt->execute([$osId]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
