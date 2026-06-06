<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\NecessidadeCompraRepository;
use App\Repositories\NotificacaoTecnicoRepository;
use App\Repositories\OrcamentoRepository;
use App\Repositories\OrdemServicoRepository;
use App\Repositories\OsEquipamentoRepository;
use App\Repositories\ServicoTerceiroRepository;
use App\Repositories\TecnicoItemRepository;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class TecnicoService
{
    public const STATUS_EQUIP_VALIDOS = ['aberta', 'andamento', 'montagem', 'pronto', 'cancelado'];

    public function __construct(
        private readonly OsEquipamentoRepository   $equipRepo       = new OsEquipamentoRepository(),
        private readonly OrdemServicoRepository    $osRepo          = new OrdemServicoRepository(),
        private readonly OrcamentoRepository       $orcRepo         = new OrcamentoRepository(),
        private readonly TecnicoItemRepository     $itensRepo       = new TecnicoItemRepository(),
        private readonly NecessidadeCompraRepository $necessidadeRepo = new NecessidadeCompraRepository(),
        private readonly NotificacaoTecnicoRepository $notifRepo    = new NotificacaoTecnicoRepository(),
        private readonly ServicoTerceiroRepository $servicoTerceiroRepo = new ServicoTerceiroRepository(),
        private readonly AuditoriaService $audit = new AuditoriaService(),
    ) {}

    /**
     * Atualiza o status do equipamento e propaga para o status macro da OS.
     * Retorna o status macro resultante da OS.
     */
    public function atualizarStatusEquipamento(
        string  $osId,
        int     $equipIdx,
        string  $novoStatus,
        ?string $obsAppend = null,
    ): string {
        if (!in_array($novoStatus, self::STATUS_EQUIP_VALIDOS, true)) {
            throw new InvalidArgumentException("Status inválido: {$novoStatus}");
        }

        if ($novoStatus === 'pronto' && $this->servicoTerceiroRepo->existeEnviado($osId, $equipIdx)) {
            throw new InvalidArgumentException(
                'Este equipamento possui serviço terceirizado em andamento. Registre o retorno antes de marcar como pronto.'
            );
        }

        $orcamentoAtual = $this->orcRepo->buscarPorOsEquip($osId, $equipIdx);
        if (
            in_array($novoStatus, ['montagem', 'pronto'], true)
            && ($orcamentoAtual['status'] ?? null) === 'aprovado'
        ) {
            $montagem = $this->verificarCondicoesMontagemDetalhada($osId, $equipIdx);
            if (!($montagem['pode_montar'] ?? false)) {
                throw new InvalidArgumentException(
                    'Não foi possível liberar montagem. ' . (string) ($montagem['message'] ?? 'Ainda há peças sem estoque ou necessidades de compra pendentes.')
                );
            }
        }

        // Regra de negócio: qualquer avanço de status exige CX preenchida
        if ($novoStatus !== 'aberta') {
            $cx = $this->equipRepo->buscarCx($osId, $equipIdx);
            if ($cx === '') {
                throw new InvalidArgumentException(
                    'Informe o número da Caixa (CX) antes de avançar o status do equipamento.'
                );
            }
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $this->equipRepo->atualizarStatus($osId, $equipIdx, $novoStatus);

            if ($obsAppend !== null && trim($obsAppend) !== '') {
                $this->equipRepo->appendObsInterno($osId, $equipIdx, trim($obsAppend));
            }

            if ($novoStatus === 'pronto') {
                $this->orcRepo->marcarProntoSeNecessario($osId, $equipIdx);
            }

            $statusList = $this->equipRepo->listarStatusPorOs($osId);
            $statusOsAtual = $this->osRepo->buscarStatus($osId);
            if ($statusOsAtual === null) {
                throw new RuntimeException("OS não encontrada: {$osId}");
            }

            $novoMacro = self::derivarStatusMacro($statusList);
            if ($statusOsAtual === 'retirado') {
                // OS retirada é imutável por ação do técnico.
                $novoMacro = $statusOsAtual;
            } elseif ($novoMacro === 'pronto') {
                // Todos os equipamentos estão prontos, mas OS aguarda conclusão administrativa.
                // OrdemServicoService::concluir() é a única via para OS virar 'pronto' —
                // valida orçamentos, cria lançamento financeiro e registra data_conclusao.
                // O técnico não deve avançar a OS diretamente; preserva status atual.
                $novoMacro = $statusOsAtual;
            } elseif ($novoMacro !== $statusOsAtual) {
                $this->osRepo->atualizarStatus($osId, $novoMacro);
            }

            $pdo->commit();
            return $novoMacro;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Verifica se o equipamento está elegível para o status "montagem":
     *  1. O orçamento deve estar com status 'aprovado'.
     *  2. Não existir nenhuma necessidade_compra bloqueante para o equipamento
     *     (pendente OU comprado sem entrada registrada — incluindo itens manuais).
     *  3. Todos os itens técnicos com produto_id vinculado devem ter
     *     estoque_qty >= qtd solicitada.
     *
     * Retorna true se TODAS as condições forem satisfeitas.
     */
    public function verificarCondicoesMontagem(string $osId, int $equipIdx): bool
    {
        return (bool) $this->verificarCondicoesMontagemDetalhada($osId, $equipIdx)['pode_montar'];
    }

    /**
     * Verifica as condições de montagem e retorna os motivos dos bloqueios reais.
     *
     * @return array{pode_montar:bool, message:string, bloqueios:array<int, array<string, mixed>>}
     */
    public function verificarCondicoesMontagemDetalhada(string $osId, int $equipIdx): array
    {
        $orcamento = $this->orcRepo->buscarPorOsEquip($osId, $equipIdx);
        if ($orcamento === null || $orcamento['status'] !== 'aprovado') {
            return [
                'pode_montar' => false,
                'message' => 'Condições não atendidas: orçamento ainda não aprovado.',
                'bloqueios' => [[
                    'tipo' => 'orcamento',
                    'descricao' => 'Orçamento ainda não aprovado.',
                ]],
            ];
        }

        $bloqueios = [];
        foreach ($this->necessidadeRepo->listarBloqueantesPorEquip($osId, $equipIdx) as $necessidade) {
            $motivo = (string) ($necessidade['motivo_bloqueio'] ?? '');
            $descricao = trim((string) ($necessidade['descricao'] ?? 'Item sem descrição'));
            $qtd = (float) ($necessidade['qtd'] ?? 0);
            $estoque = $necessidade['estoque_qty'] ?? null;

            if ($motivo === 'item_manual') {
                $mensagem = "Item manual sem produto vinculado: {$descricao}.";
            } elseif ($motivo === 'produto_nao_localizado') {
                $mensagem = "Produto vinculado não localizado: {$descricao}.";
            } else {
                $mensagem = "Estoque insuficiente para {$descricao}: necessário {$qtd}, disponível " . (float) $estoque . '.';
            }

            $bloqueios[] = [
                'tipo' => $motivo !== '' ? $motivo : 'necessidade_compra',
                'necessidade_id' => (int) ($necessidade['id'] ?? 0),
                'produto_id' => isset($necessidade['produto_id']) ? (int) $necessidade['produto_id'] : null,
                'descricao' => $descricao,
                'qtd' => $qtd,
                'estoque_qty' => $estoque !== null ? (float) $estoque : null,
                'message' => $mensagem,
            ];
        }

        $itens = $this->itensRepo->listarComProdutoPorEquipamento($osId, $equipIdx);
        $pdo = Database::pdo();
        foreach ($itens as $item) {
            if ($this->orcRepo->tecnicoItemFornecidoCliente($osId, $equipIdx, $item)) {
                continue;
            }

            $stmt = $pdo->prepare(
                'SELECT estoque_qty FROM produtos WHERE id = :id AND ativo = 1 AND controla_estoque = 1 LIMIT 1'
            );
            $stmt->execute([':id' => (int) $item['produto_id']]);
            $row = $stmt->fetchColumn();
            if ($row === false) {
                // Produto não controla estoque (serviço/M.O.) — não bloqueia promoção para montagem.
                continue;
            }
            if ((float) $row < (float) $item['qtd']) {
                $descricao = trim((string) ($item['descricao'] ?? 'Item sem descrição'));
                $bloqueios[] = [
                    'tipo' => 'estoque_insuficiente',
                    'produto_id' => (int) $item['produto_id'],
                    'descricao' => $descricao,
                    'qtd' => (float) $item['qtd'],
                    'estoque_qty' => (float) $row,
                    'message' => "Estoque insuficiente para {$descricao}: necessário " . (float) $item['qtd'] . ', disponível ' . (float) $row . '.',
                ];
            }
        }

        if ($bloqueios !== []) {
            return [
                'pode_montar' => false,
                'message' => $bloqueios[0]['message'] ?? 'Condições não atendidas para montagem.',
                'bloqueios' => $bloqueios,
            ];
        }

        return [
            'pode_montar' => true,
            'message' => 'Equipamento pronto para montagem!',
            'bloqueios' => [],
        ];
    }

    /**
     * Se as condições de montagem forem atendidas E o status atual for
     * 'andamento', promove automaticamente para 'montagem'.
     * Retorna o novo status do equipamento (ou o atual se não promoveu).
     */
    public function promoverMontagemSeEligivel(string $osId, int $equipIdx): string
    {
        $equip = $this->equipRepo->buscar($osId, $equipIdx);
        if ($equip === null) {
            throw new RuntimeException("Equipamento não encontrado: {$osId}#{$equipIdx}");
        }

        $statusAtual = (string) $equip['status_equip'];
        if ($statusAtual !== 'andamento') {
            return $statusAtual;
        }

        if (!$this->verificarCondicoesMontagem($osId, $equipIdx)) {
            return $statusAtual;
        }

        $this->atualizarStatusEquipamento($osId, $equipIdx, 'montagem');
        return 'montagem';
    }

    /** @return array<int, array<string, mixed>> */
    public function listarServicosTerceirosPorEquipamento(string $osId, int $equipIdx): array
    {
        return $this->servicoTerceiroRepo->listarPorEquipamento($osId, $equipIdx);
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    public function listarServicosTerceirosPorOs(string $osId): array
    {
        return $this->servicoTerceiroRepo->listarPorOsAgrupado($osId);
    }

    /**
     * @param array<string, mixed> $dados
     * @return array<string, mixed>
     */
    public function criarServicoTerceiro(string $osId, int $equipIdx, array $dados, int $usuarioId): array
    {
        $equip = $this->equipRepo->buscar($osId, $equipIdx);
        if ($equip === null) {
            throw new RuntimeException("Equipamento não encontrado: {$osId}#{$equipIdx}");
        }

        if (in_array((string) $equip['status_equip'], ['retirado', 'devolvido', 'descartado'], true)) {
            throw new InvalidArgumentException('Equipamento finalizado não aceita serviço terceirizado.');
        }

        $tipo = $this->normalizarTipoServicoTerceiro((string) ($dados['tipo'] ?? 'rebobinamento'));
        $fornecedor = trim((string) ($dados['fornecedor_nome'] ?? ''));
        $saidaEm = $this->normalizarDataHoraSaida((string) ($dados['saida_em'] ?? ''));
        $previsao = $this->normalizarData((string) ($dados['previsao_retorno'] ?? ''));
        $observacao = trim((string) ($dados['observacao'] ?? ''));
        $tecnicoItemId = (int) ($dados['tecnico_item_id'] ?? 0);

        if ($saidaEm !== null && $fornecedor === '') {
            throw new InvalidArgumentException('Informe o fornecedor/terceiro responsável antes de registrar a saída.');
        }

        if ($tecnicoItemId > 0) {
            $item = $this->itensRepo->buscarPorId($tecnicoItemId);
            if ($item === null || (string) $item['os_id'] !== $osId || (int) $item['equip_idx'] !== $equipIdx) {
                throw new InvalidArgumentException('Item técnico inválido para este equipamento.');
            }
        }

        $status = $saidaEm !== null ? 'enviado' : 'aguardando_envio';
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $id = $this->servicoTerceiroRepo->criar([
                'os_id' => $osId,
                'equip_idx' => $equipIdx,
                'tecnico_item_id' => $tecnicoItemId > 0 ? $tecnicoItemId : null,
                'tipo' => $tipo,
                'fornecedor_nome' => $fornecedor !== '' ? $fornecedor : null,
                'status' => $status,
                'saida_em' => $saidaEm,
                'previsao_retorno' => $previsao,
                'observacao' => $observacao !== '' ? $observacao : null,
                'criado_por' => $usuarioId > 0 ? $usuarioId : null,
                'atualizado_por' => $usuarioId > 0 ? $usuarioId : null,
            ]);

            $this->audit->registrar('servicos_terceiros', (string) $id, 'INSERT', [
                'mensagem' => 'Serviço terceirizado registrado para equipamento.',
                'os_id' => $osId,
                'equip_idx' => $equipIdx,
                'tecnico_item_id' => $tecnicoItemId > 0 ? $tecnicoItemId : null,
                'status' => $status,
                'tipo' => $tipo,
                'fornecedor_nome' => $fornecedor !== '' ? $fornecedor : null,
            ]);

            $pdo->commit();
            return $this->servicoTerceiroRepo->buscar($id) ?? ['id' => $id];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    public function registrarRetornoServicoTerceiro(int $id, string $observacaoRetorno, int $usuarioId): array
    {
        $servico = $this->servicoTerceiroRepo->buscar($id);
        if ($servico === null) {
            throw new RuntimeException('Serviço terceirizado não encontrado.');
        }
        if (!in_array((string) $servico['status'], ['aguardando_envio', 'enviado'], true)) {
            throw new InvalidArgumentException('Serviço terceirizado já finalizado.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $this->servicoTerceiroRepo->registrarRetorno($id, $usuarioId, trim($observacaoRetorno) ?: null);
            $atualizado = $this->servicoTerceiroRepo->buscar($id) ?? $servico;

            $this->audit->registrar('servicos_terceiros', (string) $id, 'UPDATE', [
                'mensagem' => 'Retorno de serviço terceirizado registrado.',
                'os_id' => (string) $servico['os_id'],
                'equip_idx' => (int) $servico['equip_idx'],
                'status_anterior' => (string) $servico['status'],
                'status_novo' => 'retornado',
            ]);

            $mensagem = 'Serviço terceirizado retornou — equipamento liberado para montagem.';
            $this->notifRepo->criar((string) $servico['os_id'], (int) $servico['equip_idx'], 'info', $mensagem, 'oficina');
            $this->notifRepo->criar((string) $servico['os_id'], (int) $servico['equip_idx'], 'info', $mensagem, 'recepcao');

            $pdo->commit();
            return $atualizado;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    public function cancelarServicoTerceiro(int $id, string $observacao, int $usuarioId): array
    {
        $servico = $this->servicoTerceiroRepo->buscar($id);
        if ($servico === null) {
            throw new RuntimeException('Serviço terceirizado não encontrado.');
        }
        if (!in_array((string) $servico['status'], ['aguardando_envio', 'enviado'], true)) {
            throw new InvalidArgumentException('Serviço terceirizado já finalizado.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $this->servicoTerceiroRepo->cancelar($id, $usuarioId, trim($observacao) ?: null);
            $atualizado = $this->servicoTerceiroRepo->buscar($id) ?? $servico;

            $this->audit->registrar('servicos_terceiros', (string) $id, 'UPDATE', [
                'mensagem' => 'Serviço terceirizado cancelado.',
                'os_id' => (string) $servico['os_id'],
                'equip_idx' => (int) $servico['equip_idx'],
                'status_anterior' => (string) $servico['status'],
                'status_novo' => 'cancelado',
            ]);

            $pdo->commit();
            return $atualizado;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function normalizarTipoServicoTerceiro(string $tipo): string
    {
        $tipo = strtolower(trim($tipo));
        return in_array($tipo, ['rebobinamento', 'outro'], true) ? $tipo : 'rebobinamento';
    }

    private function normalizarData(string $data): ?string
    {
        $data = trim($data);
        if ($data === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if (!$dt || $dt->format('Y-m-d') !== $data) {
            throw new InvalidArgumentException('Data inválida.');
        }
        return $dt->format('Y-m-d');
    }

    private function normalizarDataHoraSaida(string $data): ?string
    {
        $data = trim($data);
        if ($data === '') {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if (!$dt || $dt->format('Y-m-d') !== $data) {
            throw new InvalidArgumentException('Data de saída inválida.');
        }

        return $dt->format('Y-m-d') . ' 00:00:00';
    }

    /**
     * Transição automática aberta → andamento ao abrir o detalhe do equipamento.
     *
     * Diferente de atualizarStatusEquipamento(), este método:
     *   - Não valida CX (o técnico ainda não preencheu a caixa ao abrir a tela)
     *   - Só age se status_equip for exatamente 'aberta'
     *   - Usa WHERE status_equip = 'aberta' para ser idempotente (corrida segura)
     *   - Não altera orçamento, financeiro, estoque ou M.O.
     */
    public function iniciarDiagnostico(string $osId, int $equipIdx): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // UPDATE condicional — só age se ainda estiver 'aberta'
            $stmt = $pdo->prepare(
                "UPDATE os_equipamento
                    SET status_equip    = 'andamento',
                        status_equip_em = NOW()
                  WHERE os_id = :os
                    AND ordem_idx = :idx
                    AND status_equip = 'aberta'"
            );
            $stmt->execute([':os' => $osId, ':idx' => $equipIdx]);

            if ($stmt->rowCount() === 0) {
                // Já estava em outro status — nada a fazer
                $pdo->rollBack();
                return;
            }

            $statusList    = $this->equipRepo->listarStatusPorOs($osId);
            $statusOsAtual = $this->osRepo->buscarStatus($osId);

            if ($statusOsAtual !== null && $statusOsAtual !== 'retirado') {
                $novoMacro = self::derivarStatusMacro($statusList);
                if ($novoMacro !== 'pronto' && $novoMacro !== $statusOsAtual) {
                    $this->osRepo->atualizarStatus($osId, $novoMacro);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function concluirDiagnostico(string $osId, int $equipIdx, int $usuarioId): void
    {
        $equip = $this->equipRepo->buscar($osId, $equipIdx);
        if ($equip === null) {
            throw new RuntimeException("Equipamento não encontrado: {$osId}#{$equipIdx}");
        }

        $statusAtual = (string) ($equip['status_equip'] ?? '');
        if ($statusAtual !== 'andamento') {
            throw new InvalidArgumentException(
                "Diagnóstico só pode ser concluído com o equipamento em andamento. Status atual: {$statusAtual}."
            );
        }

        $itens = $this->itensRepo->listarPorEquipamento($osId, $equipIdx);
        $temLaudo = trim((string) ($equip['obs_int'] ?? '')) !== ''
            || trim((string) ($equip['obs_cli'] ?? '')) !== '';

        if (empty($itens) && !$temLaudo) {
            throw new InvalidArgumentException(
                'Lance ao menos uma peça, serviço ou observação/laudo antes de concluir o diagnóstico.'
            );
        }

        $this->equipRepo->concluirDiagnostico($osId, $equipIdx, $usuarioId);

        $nomeEquip = trim((string) ($equip['nome'] ?? ''));
        if ($nomeEquip === '') {
            $nomeEquip = 'Equipamento #' . ($equipIdx + 1);
        }

        $this->notifRepo->criar(
            $osId,
            $equipIdx,
            'diagnostico',
            "Diagnóstico concluído na OS #{$osId} — {$nomeEquip}. Aguardando aprovação ou cancelamento do orçamento.",
            'recepcao'
        );
    }

    /**
     * Função pura: dado o conjunto de status_equip de todos os equipamentos
     * de uma OS, retorna o status macro derivado.
     *
     * @param array<int, string> $statusEquipamentos
     */
    public static function derivarStatusMacro(array $statusEquipamentos): string
    {
        if (in_array('andamento', $statusEquipamentos, true)) return 'andamento';
        if (in_array('montagem',  $statusEquipamentos, true)) return 'andamento';
        if (in_array('aberta',    $statusEquipamentos, true)) return 'aberta';
        if (in_array('pronto',    $statusEquipamentos, true)) return 'pronto';
        // Se todos são retirado ou cancelado → OS pode ser considerada retirada
        if (in_array('retirado',  $statusEquipamentos, true)) return 'retirado';
        return 'cancelado';
    }
}
