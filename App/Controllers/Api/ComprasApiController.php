<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\NecessidadeCompraRepository;
use App\Repositories\NotificacaoTecnicoRepository;
use App\Services\TecnicoService;
use InvalidArgumentException;
use PDO;
use Throwable;

final class ComprasApiController
{
    public function __construct(
        private readonly NecessidadeCompraRepository $repo           = new NecessidadeCompraRepository(),
        private readonly TecnicoService              $tecnicoService = new TecnicoService(),
        private readonly NotificacaoTecnicoRepository $notifRepo     = new NotificacaoTecnicoRepository(),
    ) {}

    /**
     * PATCH /api/compras/necessidades/{id}/status
     * Body: { "status": "comprado"|"cancelado"|"pendente" }
     * Permissão: admin, recepcao
     */
    public function atualizarStatus(Request $request, string $id): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $necId     = (int) $id;
        $novoStatus = trim((string) $request->input('status', ''));

        if ($necId <= 0) {
            return Response::json(['ok' => false, 'error' => 'ID inválido.'], 400);
        }

        $validos = ['pendente', 'comprado', 'cancelado'];
        if (!in_array($novoStatus, $validos, true)) {
            return Response::json([
                'ok'    => false,
                'error' => "Status inválido. Use: " . implode(', ', $validos),
            ], 400);
        }

        $necessidade = $this->repo->buscarPorId($necId);
        if ($necessidade === null) {
            return Response::json(['ok' => false, 'error' => 'Necessidade não encontrada.'], 404);
        }

        if ((string) $necessidade['status'] === $novoStatus) {
            return Response::json([
                'ok'     => true,
                'status' => $novoStatus,
                'msg'    => 'Status já está como solicitado.',
            ]);
        }

        try {
            $this->repo->atualizarStatus($necId, $novoStatus);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao atualizar status.'], 500);
        }

        return Response::json([
            'ok'     => true,
            'id'     => $necId,
            'status' => $novoStatus,
        ]);
    }

    /**
     * POST /api/compras/necessidades/{id}/vincular-produto
     * Body: { "produto_id": int }
     * Permissão: admin, recepcao
     *
     * Resolve necessidade manual vinculando-a ao produto cadastrado.
     * Não baixa estoque, não cria movimentação e não altera status da OS.
     */
    public function vincularProduto(Request $request, string $id): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $necId = (int) $id;
        $produtoId = (int) $request->input('produto_id', 0);

        if ($necId <= 0 || $produtoId <= 0) {
            return Response::json(['ok' => false, 'error' => 'Necessidade ou produto inválido.'], 400);
        }

        try {
            $resultado = $this->repo->vincularProduto($necId, $produtoId);
            $necessidade = $this->repo->buscarPorId($necId);
            $temBloqueantes = true;
            if ($necessidade !== null) {
                $temBloqueantes = $this->repo->temBloqueantesPorEquip(
                    (string) $necessidade['os_id'],
                    (int) $necessidade['equip_idx']
                );
            }
        } catch (\RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao vincular produto.'], 500);
        }

        return Response::json([
            'ok' => true,
            'id' => $necId,
            'produto_id' => $produtoId,
            'produto' => $resultado['produto'] ?? null,
            'tem_bloqueantes' => $temBloqueantes,
        ]);
    }

    /**
     * POST /api/compras/necessidades/{id}/entrada-estoque
     * Body: { "qtd_entrada": float, "chave_nfe": string?, "observacao": string? }
     * Permissão: admin, recepcao
     *
     * Registra entrada de estoque para uma necessidade de compra com produto cadastrado.
     * Idempotente: bloqueia dupla entrada via estoque_movimentacoes.origem_tipo/origem_id.
     * Não altera OS, financeiro, retirada, desconto, M.O., PDV, pre_pedido.
     */
    public function entradaEstoque(Request $request, string $id): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $necId      = (int) $id;
        $qtdEntrada = (float) $request->input('qtd_entrada', 0);
        $chaveNfe   = trim((string) $request->input('chave_nfe', ''));
        $observacao = trim((string) $request->input('observacao', ''));

        if ($necId <= 0) {
            return Response::json(['ok' => false, 'error' => 'ID inválido.'], 400);
        }
        if ($qtdEntrada <= 0) {
            return Response::json(['ok' => false, 'error' => 'Quantidade de entrada deve ser maior que zero.'], 400);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            // 1. Buscar necessidade com lock
            $stmt = $pdo->prepare(
                "SELECT id, os_id, equip_idx, produto_id, descricao, qtd, status, chave_nfe
                   FROM necessidades_compra WHERE id = ? LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$necId]);
            $necessidade = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($necessidade === false) {
                $pdo->rollBack();
                return Response::json(['ok' => false, 'error' => 'Necessidade não encontrada.'], 404);
            }

            // 2. Validar status
            if ((string) $necessidade['status'] !== 'comprado') {
                $pdo->rollBack();
                return Response::json([
                    'ok'    => false,
                    'error' => 'Entrada permitida apenas para necessidades com status "comprado".',
                ], 422);
            }

            // 3. Validar produto_id
            $produtoId = $necessidade['produto_id'];
            if ($produtoId === null || (int) $produtoId <= 0) {
                $pdo->rollBack();
                return Response::json([
                    'ok'    => false,
                    'error' => 'Item manual — cadastre o produto no estoque antes de dar entrada.',
                ], 422);
            }
            $produtoId = (int) $produtoId;

            // 4. Idempotência — evitar dupla entrada
            if ($this->repo->entradaJaRegistrada($necId)) {
                $pdo->rollBack();
                return Response::json([
                    'ok'    => false,
                    'error' => 'Entrada já registrada para esta necessidade. Consulte o histórico de movimentações.',
                ], 409);
            }

            // 5. Buscar produto com lock e validar
            $stProd = $pdo->prepare(
                "SELECT id, descricao, controla_estoque, estoque_qty, ativo
                   FROM produtos WHERE id = ? LIMIT 1 FOR UPDATE"
            );
            $stProd->execute([$produtoId]);
            $produto = $stProd->fetch(PDO::FETCH_ASSOC);

            if ($produto === false) {
                $pdo->rollBack();
                return Response::json(['ok' => false, 'error' => 'Produto não encontrado no catálogo.'], 404);
            }
            if ((int) $produto['controla_estoque'] === 0) {
                $pdo->rollBack();
                return Response::json(['ok' => false, 'error' => 'Produto não controla estoque (serviço/M.O.).'], 422);
            }

            // 6. Calcular saldos
            $saldoAnt = (float) $produto['estoque_qty'];
            $saldoPos = round($saldoAnt + $qtdEntrada, 3);

            // 7. Atualizar estoque_qty
            $pdo->prepare(
                "UPDATE produtos SET estoque_qty = ? WHERE id = ? LIMIT 1"
            )->execute([$saldoPos, $produtoId]);

            // 8. Montar descrição da movimentação
            $descMovimentacao = sprintf(
                'Entrada por necessidade de compra — OS %s equip. #%d',
                (string) $necessidade['os_id'],
                (int) $necessidade['equip_idx']
            );
            if ($observacao !== '') {
                $descMovimentacao .= ' — ' . $observacao;
            }

            // 9. Registrar movimentação
            $pdo->prepare(
                "INSERT INTO estoque_movimentacoes
                   (produto_id, os_id, tipo, qtd, saldo_ant, saldo_pos,
                    descricao, usuario_id, origem_tipo, origem_id, criado_em)
                 VALUES (?, ?, 'entrada', ?, ?, ?, ?, ?, 'necessidade_compra', ?, NOW())"
            )->execute([
                $produtoId,
                (string) $necessidade['os_id'],
                $qtdEntrada,
                $saldoAnt,
                $saldoPos,
                $descMovimentacao,
                Auth::id(),
                (string) $necId,
            ]);

            $movimentacaoId = (int) $pdo->lastInsertId();

            // 10. Salvar chave_nfe na necessidade se informada
            if ($chaveNfe !== '') {
                $pdo->prepare(
                    "UPDATE necessidades_compra SET chave_nfe = ? WHERE id = ? LIMIT 1"
                )->execute([$chaveNfe, $necId]);
            }

            // Capturar antes do commit para uso após a transação
            $necOsId     = (string) $necessidade['os_id'];
            $necEquipIdx = (int) $necessidade['equip_idx'];

            $pdo->commit();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return Response::json(['ok' => false, 'error' => 'Erro interno ao registrar entrada.'], 500);
        }

        // Tentar promoção para montagem (best-effort, fora da transação de estoque)
        $promovido      = false;
        $temBloqueantes = true;
        $statusEquip    = null;

        try {
            $temBloqueantes = $this->repo->temBloqueantesPorEquip($necOsId, $necEquipIdx);
            if (!$temBloqueantes) {
                $statusEquip = $this->tecnicoService->promoverMontagemSeEligivel($necOsId, $necEquipIdx);
                $promovido   = ($statusEquip === 'montagem');
            }
        } catch (Throwable $e) {
            error_log("[9D-9] Promoção para montagem falhou após entrada #{$necId}: " . $e->getMessage());
        }

        // Notificar técnico quando a promoção automática para montagem ocorreu (best-effort).
        // Idempotência da entrada garante que esta notificação só é criada uma vez por necessidade.
        // Não contém valores financeiros, preços, custo ou desconto.
        $notificacaoCriada = false;
        if ($promovido) {
            try {
                $this->notifRepo->criar(
                    $necOsId,
                    $necEquipIdx,
                    'info',
                    'Peças recebidas — equipamento liberado para montagem.'
                );
                $notificacaoCriada = true;
            } catch (Throwable $e) {
                error_log("[9D-11] Notificação técnico falhou após promoção montagem #{$necId}: " . $e->getMessage());
            }
        }

        $msgExtra = $promovido
            ? ' Equipamento promovido para montagem automaticamente. Técnico notificado.'
            : '';

        return Response::json([
            'ok'                   => true,
            'id'                   => $necId,
            'produto_id'           => $produtoId,
            'qtd_entrada'          => $qtdEntrada,
            'saldo_ant'            => $saldoAnt,
            'saldo_pos'            => $saldoPos,
            'movimentacao_id'      => $movimentacaoId,
            'promovido_montagem'   => $promovido,
            'bloqueantes_restantes'=> $temBloqueantes,
            'status_equip'         => $statusEquip,
            'notificacao_criada'   => $notificacaoCriada,
            'msg'                  => "Entrada de {$qtdEntrada} un. registrada. Novo saldo: {$saldoPos}.{$msgExtra}",
        ]);
    }
}
