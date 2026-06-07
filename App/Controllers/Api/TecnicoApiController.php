<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\OrdemServicoRepository;
use App\Repositories\NecessidadeCompraRepository;
use App\Repositories\OrcamentoRepository;
use App\Repositories\OsEquipamentoRepository;
use App\Repositories\ProdutoRepository;
use App\Repositories\TecnicoItemRepository;
use App\Services\TecnicoService;
use App\Services\UploadService;
use InvalidArgumentException;
use Throwable;

final class TecnicoApiController
{
    public function __construct(
        private readonly OsEquipamentoRepository $equipRepo  = new OsEquipamentoRepository(),
        private readonly OrdemServicoRepository  $osRepo     = new OrdemServicoRepository(),
        private readonly OrcamentoRepository     $orcRepo    = new OrcamentoRepository(),
        private readonly TecnicoItemRepository   $itensRepo  = new TecnicoItemRepository(),
        private readonly ProdutoRepository       $produtoRepo= new ProdutoRepository(),
        private readonly NecessidadeCompraRepository $necessidadeRepo = new NecessidadeCompraRepository(),
        private readonly TecnicoService          $service    = new TecnicoService(),
        private readonly UploadService           $upload     = new UploadService(),
    ) {}

    public function buscar(Request $request, string $os_id, string $idx): Response
    {
        $equipIdx = (int) $idx;

        $equip = $this->equipRepo->buscar($os_id, $equipIdx);
        if ($equip === null) {
            return Response::json(['ok' => false, 'error' => 'Equipamento não encontrado'], 404);
        }

        $os = $this->osRepo->buscarPorId($os_id);
        $itens = $this->filtrarItensSemValores(
            $this->necessidadeRepo->anexarStatusAosItens(
                $this->itensRepo->listarPorEquipamento($os_id, $equipIdx)
            )
        );

        return Response::json([
            'ok'              => true,
            'os'              => $os,
            'equip'           => $equip,
            'itens'           => $itens,
        ]);
    }

    public function atualizarStatus(Request $request, string $os_id, string $idx): Response
    {
        $equipIdx   = (int) $idx;
        $novoStatus = trim((string) $request->input('status', ''));
        $obsAppend  = (string) $request->input('obs_int_append', '');

        try {
            $this->assertEquipEditavel($os_id, $equipIdx);
            $macro = $this->service->atualizarStatusEquipamento(
                $os_id,
                $equipIdx,
                $novoStatus,
                $obsAppend !== '' ? $obsAppend : null,
            );
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return Response::json([
            'ok'             => true,
            'status_equip'   => $novoStatus,
            'os_status'      => $macro,
        ]);
    }

    public function criarServicoTerceiro(Request $request, string $os_id, string $idx): Response
    {
        if (!Auth::temNivel('admin', 'oficina')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $equipIdx = (int) $idx;
        try {
            $servico = $this->service->criarServicoTerceiro($os_id, $equipIdx, [
                'tipo' => $request->input('tipo', 'rebobinamento'),
                'tecnico_item_id' => $request->input('tecnico_item_id'),
                'fornecedor_nome' => $request->input('fornecedor_nome', ''),
                'saida_em' => $request->input('saida_em', ''),
                'previsao_retorno' => $request->input('previsao_retorno', ''),
                'observacao' => $request->input('observacao', ''),
            ], Auth::id() ?? 0);

            return Response::json([
                'ok' => true,
                'servico' => $servico,
                'servicos' => $this->service->listarServicosTerceirosPorEquipamento($os_id, $equipIdx),
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function registrarRetornoServicoTerceiro(Request $request, string $id): Response
    {
        if (!Auth::temNivel('admin', 'oficina')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        try {
            $servico = $this->service->registrarRetornoServicoTerceiro(
                (int) $id,
                (string) $request->input('observacao_retorno', ''),
                Auth::id() ?? 0
            );

            return Response::json(['ok' => true, 'servico' => $servico]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function cancelarServicoTerceiro(Request $request, string $id): Response
    {
        if (!Auth::temNivel('admin')) {
            return Response::json(['ok' => false, 'error' => 'Acesso restrito a administradores.'], 403);
        }

        $observacao = trim((string) $request->input('observacao', ''));
        if ($observacao === '') {
            return Response::json(['ok' => false, 'error' => 'Informe o motivo do cancelamento.'], 400);
        }

        try {
            $servico = $this->service->cancelarServicoTerceiro(
                (int) $id,
                $observacao,
                Auth::id() ?? 0
            );

            return Response::json(['ok' => true, 'servico' => $servico]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function atualizarNome(Request $request, string $os_id, string $idx): Response
    {
        $equipIdx = (int) $idx;
        $nome = trim((string) $request->input('nome', ''));

        if ($nome === '') {
            return Response::json(['ok' => false, 'error' => 'Nome é obrigatório'], 400);
        }

        try {
            $this->assertEquipEditavel($os_id, $equipIdx);
            $this->equipRepo->atualizarNome($os_id, $equipIdx, $nome);
            return Response::json(['ok' => true, 'nome' => mb_strtoupper($nome)]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Atualiza serie, voltagem, caixa (cx), fabricante e/ou modelo do equipamento — sempre em CAIXA ALTA.
     * Demand 1: Edição de Equipamento e Validação da Caixa.
     */
    public function atualizarDadosEquipamento(Request $request, string $os_id, string $idx): Response
    {
        $equipIdx = (int) $idx;
        $campos = [];

        foreach (['serie', 'voltagem', 'cx', 'fabricante', 'modelo'] as $campo) {
            $val = $request->input($campo);
            if ($val !== null) {
                $campos[$campo] = $val;
            }
        }

        if (empty($campos)) {
            return Response::json(['ok' => false, 'error' => 'Nenhum campo informado'], 400);
        }

        try {
            $this->assertEquipEditavel($os_id, $equipIdx);
            $this->equipRepo->atualizarDados($os_id, $equipIdx, $campos);
            $equip = $this->equipRepo->buscar($os_id, $equipIdx);
            return Response::json([
                'ok'         => true,
                'serie'      => $equip['serie']      ?? '',
                'voltagem'   => $equip['voltagem']   ?? '',
                'cx'         => $equip['cx']         ?? '',
                'fabricante' => $equip['fabricante'] ?? '',
                'modelo'     => $equip['modelo']     ?? '',
            ]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Verifica condições para promover a "Montagem" e, se atender, promove automaticamente.
     * Demand 2: Refatoração do Status do Equipamento: "Montagem".
     */
    public function verificarMontagem(Request $request, string $os_id, string $idx): Response
    {
        $equipIdx = (int) $idx;

        try {
            $resultado = $this->service->verificarCondicoesMontagemDetalhada($os_id, $equipIdx);
            $podeMontar = (bool) ($resultado['pode_montar'] ?? false);

            if (!$podeMontar) {
                return Response::json([
                    'ok'         => true,
                    'pode_montar' => false,
                    'message'    => (string) ($resultado['message'] ?? 'Condições não atendidas para montagem.'),
                    'bloqueios'  => $resultado['bloqueios'] ?? [],
                ]);
            }

            $statusAtual = $this->service->promoverMontagemSeEligivel($os_id, $equipIdx);

            return Response::json([
                'ok'         => true,
                'pode_montar' => true,
                'status'     => $statusAtual,
                'message'    => 'Equipamento pronto para montagem!',
            ]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Retorna itens técnicos para importação no orçamento.
     *
     * Acesso: apenas admin/recepção — oficina não deve obter preços via este endpoint.
     *
     * Inclui valor_unit e unidade para que a recepção/admin receba preços automáticos.
     * Para itens com produto_id: usa valor_unit gravado no item; se zerado, recalcula
     * pelo produto (valor_venda_calculado → valor → 0).
     * Para itens manuais (sem produto_id): valor_unit = 0, unidade = 'un'.
     */
    public function itensParaOrcamento(Request $request, string $os_id, string $idx): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $equipIdx = (int) $idx;

        try {
            $itens = $this->itensRepo->listarPorEquipamento($os_id, $equipIdx);

            $itensParaOrc = array_map(function (array $item): array {
                $produtoId = isset($item['produto_id']) && (int) $item['produto_id'] > 0
                    ? (int) $item['produto_id']
                    : null;

                $valorUnit = (float) ($item['valor_unit'] ?? 0);
                $unidade   = 'un';

                // Para itens vinculados a produto: busca unidade e recalcula preço se zerado.
                if ($produtoId !== null) {
                    $produto = $this->produtoRepo->buscarPorId($produtoId);
                    if ($produto) {
                        $unidade = (string) ($produto['unidade'] ?? 'un');
                        if ($valorUnit <= 0) {
                            $valorUnit = (float) ($produto['valor_venda_calculado'] ?? 0);
                            if ($valorUnit <= 0) {
                                $valorUnit = (float) ($produto['valor'] ?? 0);
                            }
                        }
                    }
                }

                return [
                    'id'         => (int) ($item['id'] ?? 0),
                    'codigo'     => (string) ($item['codigo'] ?? ''),
                    'descricao'  => (string) ($item['descricao'] ?? ''),
                    'qtd'        => (float) ($item['qtd'] ?? 1),
                    'unidade'    => $unidade,
                    'produto_id' => $produtoId,
                    'valor_unit' => round($valorUnit, 2),
                    'tem_produto' => $produtoId !== null,
                ];
            }, $itens);

            return Response::json([
                'ok'    => true,
                'itens' => $itensParaOrc,
                'count' => count($itensParaOrc),
            ]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function salvarLaudo(Request $request, string $os_id, string $idx): Response
    {
        $equipIdx = (int) $idx;

        $equip = $this->equipRepo->buscar($os_id, $equipIdx);
        if ($equip === null) {
            return Response::json(['ok' => false, 'error' => 'Equipamento não encontrado'], 404);
        }

        try {
            $this->assertEquipEditavel($os_id, $equipIdx);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $obsInt = (string) $request->input('obs_int', '');
        $obsCli = $request->input('obs_cli');
        $obsCli = $obsCli === null ? null : (string) $obsCli;

        $this->equipRepo->atualizarLaudo($os_id, $equipIdx, $obsInt, $obsCli);

        return Response::json(['ok' => true]);
    }

    /**
     * Mensagem interna recepção <-> técnico por equipamento (canal de mão dupla).
     * Campo próprio (obs_recepcao), isolado do laudo e da observação ao cliente.
     * Qualquer usuário autenticado com acesso ao equipamento pode registrar.
     */
    public function salvarObsRecepcao(Request $request, string $os_id, string $idx): Response
    {
        $equipIdx = (int) $idx;

        $equip = $this->equipRepo->buscar($os_id, $equipIdx);
        if ($equip === null) {
            return Response::json(['ok' => false, 'error' => 'Equipamento não encontrado'], 404);
        }

        $texto = (string) $request->input('obs_recepcao', '');
        $this->equipRepo->atualizarObsRecepcao($os_id, $equipIdx, $texto);

        return Response::json(['ok' => true]);
    }

    public function concluirDiagnostico(Request $request, string $os_id, string $idx): Response
    {
        if (!Auth::temNivel('admin', 'oficina')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $equipIdx = (int) $idx;
        $user = Auth::user();
        $usuarioId = (int) ($user['id'] ?? $user['usuario_id'] ?? 0);

        try {
            $this->assertEquipEditavel($os_id, $equipIdx);
            $this->service->concluirDiagnostico($os_id, $equipIdx, $usuarioId);
            $equip = $this->equipRepo->buscar($os_id, $equipIdx);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return Response::json([
            'ok' => true,
            'message' => 'Diagnóstico concluído e enviado para a recepção.',
            'diagnostico_concluido_em' => $equip['diagnostico_concluido_em'] ?? null,
        ]);
    }

    public function adicionarItem(Request $request, string $os_id, string $idx): Response
    {
        $equipIdx  = (int) $idx;
        $descricao = trim((string) $request->input('descricao', ''));

        if ($descricao === '') {
            return Response::json(['ok' => false, 'error' => 'Descrição é obrigatória'], 400);
        }

        $qtd       = (float) $request->input('qtd', 1);
        $produtoId = (int) $request->input('produto_id', 0);

        if ($qtd <= 0) {
            return Response::json(['ok' => false, 'error' => 'Quantidade deve ser positiva'], 400);
        }

        try {
            $this->assertEquipEditavel($os_id, $equipIdx);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        // Produtos de M.O. principal não podem ser lançados como item técnico.
        // A mão de obra principal deve ser aplicada no orçamento via tabela M.O. (campo mo_valor).
        if ($produtoId > 0 && in_array($produtoId, [4297, 4298, 4299, 4300, 4301], true)) {
            return Response::json([
                'ok'    => false,
                'error' => 'Mão de obra principal deve ser aplicada no orçamento pela tabela M.O., não como item técnico.',
            ], 422);
        }

        // Buscar preço automaticamente do banco de dados, jamais aceitar do frontend.
        // Fallback: valor_venda_calculado → valor (cobre serviços com preco_custo=0).
        $valorUnit = 0.0;
        if ($produtoId > 0) {
            $produto = $this->produtoRepo->buscarPorId($produtoId);
            if ($produto) {
                $valorUnit = (float) ($produto['valor_venda_calculado'] ?? 0);
                if ($valorUnit <= 0) {
                    $valorUnit = (float) ($produto['valor'] ?? 0);
                }
            }
        }

        $id = $this->itensRepo->criar([
            'os_id'      => $os_id,
            'equip_idx'  => $equipIdx,
            'codigo'     => (string) $request->input('codigo', ''),
            'produto_id' => $produtoId > 0 ? $produtoId : null,
            'descricao'  => $descricao,
            'qtd'        => $qtd,
            // Regra de negócio: técnico não manipula valores financeiros.
            // Preço é buscado no banco de dados para produtos válidos.
            'valor_unit' => $valorUnit,
        ]);
        $this->equipRepo->limparConclusaoDiagnostico($os_id, $equipIdx);

        $itens = $this->filtrarItensSemValores(
            $this->necessidadeRepo->anexarStatusAosItens(
                $this->itensRepo->listarPorEquipamento($os_id, $equipIdx)
            )
        );

        return Response::json([
            'ok'          => true,
            'id'          => $id,
            'itens'       => $itens,
        ]);
    }

    public function solicitarCompra(Request $request, string $id): Response
    {
        if (!Auth::temNivel('admin', 'oficina')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $itemId = (int) $id;
        if ($itemId <= 0) {
            return Response::json(['ok' => false, 'error' => 'Item técnico inválido.'], 400);
        }

        $item = $this->itensRepo->buscarPorId($itemId);
        if ($item === null) {
            return Response::json(['ok' => false, 'error' => 'Item técnico não encontrado.'], 404);
        }

        $osId = (string) $item['os_id'];
        $equipIdx = (int) $item['equip_idx'];

        try {
            $this->assertEquipEditavel($osId, $equipIdx);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        if ($this->orcRepo->tecnicoItemFornecidoCliente($osId, $equipIdx, $item)) {
            return Response::json([
                'ok' => false,
                'error' => 'Este item foi fornecido pelo cliente e não deve gerar compra.',
            ], 422);
        }

        $produtoId = isset($item['produto_id']) && (int) $item['produto_id'] > 0
            ? (int) $item['produto_id']
            : null;

        if ($produtoId !== null) {
            $produto = $this->produtoRepo->buscarPorId($produtoId);
            if ($produto === null) {
                return Response::json(['ok' => false, 'error' => 'Produto vinculado não encontrado.'], 404);
            }
            if ((int) ($produto['controla_estoque'] ?? 1) === 0) {
                return Response::json([
                    'ok' => false,
                    'error' => 'Serviço/M.O. não deve gerar solicitação de compra.',
                ], 422);
            }
        }

        try {
            $resultado = $this->necessidadeRepo->criarIdempotentePorTecnicoItem([
                'os_id'           => $osId,
                'equip_idx'       => $equipIdx,
                'produto_id'      => $produtoId,
                'tecnico_item_id' => $itemId,
                'codigo'          => (string) ($item['codigo'] ?? ''),
                'descricao'       => (string) ($item['descricao'] ?? ''),
                'qtd'             => (float) ($item['qtd'] ?? 1),
            ]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao solicitar compra.'], 500);
        }

        $necessidade = $resultado['necessidade'];
        $created = (bool) $resultado['created'];

        return Response::json([
            'ok' => true,
            'created' => $created,
            'message' => $created
                ? 'Solicitação de compra registrada.'
                : 'Este item já possui solicitação de compra pendente.',
            'necessidade' => [
                'id' => (int) ($necessidade['id'] ?? 0),
                'status' => (string) ($necessidade['status'] ?? 'pendente'),
            ],
        ]);
    }

    public function removerItem(Request $request, string $id): Response
    {
        $item = $this->itensRepo->buscarPorId((int) $id);
        if ($item !== null) {
            $this->equipRepo->limparConclusaoDiagnostico((string) $item['os_id'], (int) $item['equip_idx']);
        }
        $this->itensRepo->excluir((int) $id);
        return Response::json(['ok' => true]);
    }

    public function adicionarFoto(Request $request, string $os_id, string $idx): Response
    {
        $equipIdx = (int) $idx;

        if ($this->equipRepo->buscar($os_id, $equipIdx) === null) {
            return Response::json(['ok' => false, 'error' => 'Equipamento não encontrado'], 404);
        }

        try {
            $this->assertEquipEditavel($os_id, $equipIdx);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $file = $request->file('file');
        if ($file === null) {
            return Response::json(['ok' => false, 'error' => 'Campo "file" ausente'], 400);
        }

        try {
            $url   = $this->upload->salvar($os_id, $equipIdx, UploadService::KIND_FOTO, $file);
            $fotos = $this->equipRepo->adicionarFoto($os_id, $equipIdx, $url);
            return Response::json(['ok' => true, 'url' => $url, 'fotos' => $fotos]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function removerFoto(Request $request, string $os_id, string $idx): Response
    {
        $equipIdx = (int) $idx;
        $url = trim((string) $request->input('url', ''));
        if ($url === '') {
            return Response::json(['ok' => false, 'error' => 'URL da foto ausente'], 400);
        }

        try {
            $this->assertEquipEditavel($os_id, $equipIdx);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $this->upload->deletarPorUrl($url);
        $fotos = $this->equipRepo->removerFoto($os_id, $equipIdx, $url);
        return Response::json(['ok' => true, 'fotos' => $fotos]);
    }

    public function setVista(Request $request, string $os_id, string $idx): Response
    {
        $equipIdx = (int) $idx;

        $equip = $this->equipRepo->buscar($os_id, $equipIdx);
        if ($equip === null) {
            return Response::json(['ok' => false, 'error' => 'Equipamento não encontrado'], 404);
        }

        try {
            $this->assertEquipEditavel($os_id, $equipIdx);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $file = $request->file('file');
        if ($file === null) {
            return Response::json(['ok' => false, 'error' => 'Campo "file" ausente'], 400);
        }

        try {
            $url = $this->upload->salvar($os_id, $equipIdx, UploadService::KIND_VISTA, $file);

            $vistaAnterior = (string) ($equip['vista_explodida'] ?? '');
            if ($vistaAnterior !== '' && $vistaAnterior !== $url) {
                $this->upload->deletarPorUrl($vistaAnterior);
            }

            $this->equipRepo->setVistaExplodida($os_id, $equipIdx, $url);
            return Response::json(['ok' => true, 'url' => $url]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function removerVista(Request $request, string $os_id, string $idx): Response
    {
        $equipIdx = (int) $idx;
        $equip = $this->equipRepo->buscar($os_id, $equipIdx);
        if ($equip === null) {
            return Response::json(['ok' => false, 'error' => 'Equipamento não encontrado'], 404);
        }

        try {
            $this->assertEquipEditavel($os_id, $equipIdx);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        // deletarPorUrl() só apaga se a URL pertencer ao storage local —
        // URLs externas (catálogo Felap/Bosch/etc.) caem no no-op com segurança.
        $vista = (string) ($equip['vista_explodida'] ?? '');
        if ($vista !== '') {
            $this->upload->deletarPorUrl($vista);
        }
        $this->equipRepo->setVistaExplodida($os_id, $equipIdx, null);
        return Response::json(['ok' => true]);
    }

    /**
     * Vincula uma URL externa (catálogo Felap/TSN/Bosch/Milwaukee) como
     * vista explodida do equipamento — sem download, sem upload, apenas grava
     * a URL na coluna vista_explodida (já é varchar 512).
     *
     * Body JSON: { "url": "https://...", "fonte": "felap|tsn|bosch|milwaukee" (opcional) }
     */
    public function setVistaUrl(Request $request, string $os_id, string $idx): Response
    {
        $equipIdx = (int) $idx;
        $equip    = $this->equipRepo->buscar($os_id, $equipIdx);
        if ($equip === null) {
            return Response::json(['ok' => false, 'error' => 'Equipamento não encontrado'], 404);
        }

        try {
            $this->assertEquipEditavel($os_id, $equipIdx);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $url = trim((string) $request->input('url', ''));
        if ($url === '') {
            return Response::json(['ok' => false, 'error' => 'Campo "url" obrigatório'], 400);
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            return Response::json(['ok' => false, 'error' => 'URL inválida (precisa ser http:// ou https://)'], 400);
        }
        if (mb_strlen($url) > 512) {
            return Response::json(['ok' => false, 'error' => 'URL excede 512 caracteres'], 400);
        }

        // Se a vista anterior era um arquivo no storage local, apaga (libera disco).
        // URLs externas anteriores são deixadas (deletarPorUrl ignora silenciosamente).
        $vistaAnterior = (string) ($equip['vista_explodida'] ?? '');
        if ($vistaAnterior !== '' && $vistaAnterior !== $url) {
            $this->upload->deletarPorUrl($vistaAnterior);
        }

        $this->equipRepo->setVistaExplodida($os_id, $equipIdx, $url);
        return Response::json(['ok' => true, 'url' => $url]);
    }

    /**
     * Lança InvalidArgumentException se o equipamento estiver em estado físico finalizado
     * (retirado, devolvido ou descartado) — bloqueia qualquer alteração técnica.
     * 10B-0: guard unificado para todos os endpoints de escrita.
     */
    private function assertEquipEditavel(string $os_id, int $equipIdx): void
    {
        $equip  = $this->equipRepo->buscar($os_id, $equipIdx);
        $status = (string) ($equip['status_equip'] ?? '');
        if (in_array($status, ['retirado', 'devolvido', 'descartado'], true)) {
            throw new InvalidArgumentException(
                "Equipamento '{$status}' — alterações técnicas bloqueadas após finalização física."
            );
        }
    }

    /** @param array<int, array<string, mixed>> $itens */
    private function filtrarItensSemValores(array $itens): array
    {
        return array_map(static function (array $i): array {
            return [
                'id'        => (int) ($i['id'] ?? 0),
                'os_id'     => (string) ($i['os_id'] ?? ''),
                'equip_idx' => (int) ($i['equip_idx'] ?? 0),
                'codigo'    => (string) ($i['codigo'] ?? ''),
                'produto_id' => isset($i['produto_id']) ? (int) $i['produto_id'] : null,
                'controla_estoque' => isset($i['controla_estoque']) ? (int) $i['controla_estoque'] : null,
                'descricao' => (string) ($i['descricao'] ?? ''),
                'qtd'       => (float) ($i['qtd'] ?? 0),
                'created_at' => (string) ($i['created_at'] ?? ''),
                'necessidade_compra' => isset($i['necessidade_compra']) && is_array($i['necessidade_compra'])
                    ? [
                        'id' => (int) ($i['necessidade_compra']['id'] ?? 0),
                        'status' => (string) ($i['necessidade_compra']['status'] ?? ''),
                    ]
                    : null,
            ];
        }, $itens);
    }
}
