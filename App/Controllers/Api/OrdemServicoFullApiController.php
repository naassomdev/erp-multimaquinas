<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\OrdemServicoRepository;
use App\Services\OrdemServicoService;
use App\Queue\DatabaseQueue;
use DomainException;
use InvalidArgumentException;

final class OrdemServicoFullApiController
{
    private function getService(): OrdemServicoService
    {
        $pdo = Database::pdo();
        return new OrdemServicoService($pdo, new DatabaseQueue($pdo), new OrdemServicoRepository());
    }

    /**
     * PATCH /api/os/{id}/status
     * Atualiza o status de uma OS via AJAX ou altera o status para concluída/cancelada.
     */
    public function atualizarStatus(Request $request, string $id): Response
    {
        $novoStatus = trim((string) $request->input('status', ''));
        if (!in_array($novoStatus, ['aberta', 'andamento', 'pronto', 'retirado', 'cancelado', 'descartado'])) {
            return Response::json(['ok' => false, 'error' => 'Status inválido'], 400);
        }

        $service = $this->getService();

        try {
            if ($novoStatus === 'pronto') {
                $usuario = Auth::user();
                $operadorId = (int) ($usuario['id'] ?? 0);
                $resultado = $service->concluir($id, $operadorId);
                return Response::json(array_merge(['ok' => true, 'status' => 'pronto'], $resultado));
            }

            if ($novoStatus === 'retirado') {
                $usuario = Auth::user();
                $operadorId = (int) ($usuario['id'] ?? 0);
                $formaPgto = trim((string) $request->input('forma_pagamento', ''));
                $numeroPed = trim((string) $request->input('numero_pedido', ''));
                $descontoValor = max(0.0, (float) str_replace(',', '.', (string) $request->input('desconto_valor', '0')));

                if (!in_array($formaPgto, OrdemServicoService::FORMAS_PAGAMENTO, true)) {
                    return Response::json([
                        'ok'    => false,
                        'error' => 'Forma de pagamento obrigatória (dinheiro, pix, cartao, faturado)',
                    ], 400);
                }

                $resultado = $service->retirar(
                    $id,
                    $operadorId,
                    $formaPgto,
                    $numeroPed !== '' ? $numeroPed : null,
                    $descontoValor,
                );
                return Response::json(array_merge(['ok' => true, 'status' => 'retirado'], $resultado));
            }

            if ($novoStatus === 'cancelado') {
                $service->cancelar($id);
                return Response::json(['ok' => true, 'status' => 'cancelado']);
            }

            // Para outros status, se estiver reabrindo, precisa lidar com a reversão financeira se necessário,
            // mas o reabrirOS() da lógica antiga lida com voltar para 'andamento' a partir de 'pronto'.
            // Aqui vamos apenas atualizar o status de forma simples, exceto se estiver reabrindo.
            $repo = new OrdemServicoRepository();
            $statusAtual = $repo->buscarStatus($id);
            
            if (($statusAtual === 'pronto' || $statusAtual === 'retirado') && in_array($novoStatus, ['aberta', 'andamento'])) {
                $service->reabrir($id);
                return Response::json(['ok' => true, 'status' => 'andamento']);
            }

            $service->atualizarStatus($id, $novoStatus);
            return Response::json(['ok' => true, 'status' => $novoStatus]);

        } catch (InvalidArgumentException | DomainException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/os/{os_id}/equip/{equip_idx}/retirar
     * Retirada parcial de um equipamento específico (Etapa 7B).
     * Requer perfil admin ou recepcao.
     */
    public function retirarEquipamento(Request $request, string $os_id, string $equip_idx): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $equipIdx      = (int) $equip_idx;
        $formaPgtoRaw  = trim((string) $request->input('forma_pagamento', ''));
        $formaPgto     = $formaPgtoRaw !== '' ? $formaPgtoRaw : null; // null = retirada intermediária sem encerramento
        $numeroPed     = trim((string) $request->input('numero_pedido', ''));
        $retiradoPor   = trim((string) $request->input('retirado_por', ''));
        $descontoValor = max(0.0, (float) str_replace(',', '.', (string) $request->input('desconto_valor', '0')));

        // Valida formato apenas se fornecida — obrigatoriedade de encerramento é verificada no service.
        if ($formaPgto !== null && !in_array($formaPgto, OrdemServicoService::FORMAS_PAGAMENTO, true)) {
            return Response::json([
                'ok'    => false,
                'error' => 'Forma de pagamento inválida. Use: dinheiro, pix, cartao, faturado',
            ], 400);
        }

        $usuario    = Auth::user();
        $operadorId = (int) ($usuario['id'] ?? 0);

        // Fallback: usa nome do usuário logado se não informado pelo front.
        if ($retiradoPor === '') {
            $retiradoPor = (string) ($usuario['nome'] ?? ('Operador #' . $operadorId));
        }

        try {
            $resultado = $this->getService()->retirarEquipamento(
                $os_id,
                $equipIdx,
                $operadorId,
                $formaPgto,
                $numeroPed !== '' ? $numeroPed : null,
                $retiradoPor,
                $descontoValor,
            );
            return Response::json(array_merge(['ok' => true], $resultado));
        } catch (InvalidArgumentException | DomainException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/os/{os_id}/equip/{equip_idx}/devolver
     * Registra a devolução física de um equipamento ao cliente (Etapa 9C-2).
     * Requer perfil admin ou recepcao.
     */
        /**
     * POST /api/os/{os_id}/equip/{equip_idx}/desfazer-retirada
     * Desfaz a retirada de um equipamento, estornando estoque e financeiro.
     * Requer perfil admin ou recepcao.
     */
    public function desfazerRetiradaEquipamento(Request $request, string $os_id, string $equip_idx): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $justificativa = trim((string) $request->input('justificativa', ''));
        if ($justificativa === '') {
            return Response::json(['ok' => false, 'error' => 'Justificativa é obrigatória.'], 400);
        }

        $usuario    = Auth::user();
        $operadorId = (int) ($usuario['id'] ?? 0);
        $equipIdx   = (int) $equip_idx;

        try {
            $resultado = $this->getService()->desfazerRetiradaEquipamento(
                $os_id,
                $equipIdx,
                $operadorId,
                $justificativa
            );
            return Response::json(array_merge(['ok' => true], $resultado));
        } catch (InvalidArgumentException | DomainException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }


    public function devolverEquipamento(Request $request, string $os_id, string $equip_idx): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $usuario   = Auth::user();
        $usuarioId = (int) ($usuario['id'] ?? 0);
        $equipIdx  = (int) $equip_idx;

        try {
            $resultado = $this->getService()->devolverEquipamento($os_id, $equipIdx, $usuarioId);
            return Response::json(array_merge(['ok' => true], $resultado));
        } catch (DomainException | InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/os/{os_id}/equip/{equip_idx}/autorizar-descarte
     * Registra autorização do cliente para descarte de um equipamento (Etapa 9C-3).
     * NÃO confirma descarte físico nem muda status_equip.
     * Requer perfil admin ou recepcao.
     */
    public function autorizarDescarte(Request $request, string $os_id, string $equip_idx): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $usuario   = Auth::user();
        $usuarioId = (int) ($usuario['id'] ?? 0);
        $equipIdx  = (int) $equip_idx;

        $autorizadoPor = trim((string) $request->input('autorizado_por', ''));
        $meio          = trim((string) $request->input('descarte_meio', ''));

        try {
            $resultado = $this->getService()->autorizarDescarteEquipamento(
                $os_id,
                $equipIdx,
                $usuarioId,
                $autorizadoPor,
                $meio,
            );
            return Response::json(array_merge(['ok' => true], $resultado));
        } catch (DomainException | InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/os/{os_id}/equip/{equip_idx}/confirmar-descarte
     * Confirma a execução física do descarte de um equipamento (Etapa 9C-4).
     * Requer autorização prévia registrada em 9C-3 (descarte_autorizado_em preenchido).
     * Requer perfil admin ou recepcao.
     */
    public function confirmarDescarte(Request $request, string $os_id, string $equip_idx): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $usuario   = Auth::user();
        $usuarioId = (int) ($usuario['id'] ?? 0);
        $equipIdx  = (int) $equip_idx;

        try {
            $resultado = $this->getService()->confirmarDescarteEquipamento($os_id, $equipIdx, $usuarioId);
            return Response::json(array_merge(['ok' => true], $resultado));
        } catch (DomainException | InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/os/{os_id}/equip/{equip_idx}/pagar-antecipado
     * Registra pagamento antecipado de um orçamento aprovado sem retirar o equipamento (Etapa 10D-1).
     * Requer perfil admin ou recepcao.
     */
    public function pagarAntecipado(Request $request, string $os_id, string $equip_idx): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $equipIdx       = (int) $equip_idx;
        $formaPagamento = trim((string) $request->input('forma_pagamento', ''));

        if ($formaPagamento === '') {
            return Response::json([
                'ok'    => false,
                'error' => 'Forma de pagamento obrigatória (dinheiro, pix, cartao).',
            ], 400);
        }

        try {
            $resultado = $this->getService()->registrarPagamentoAntecipado(
                $os_id,
                $equipIdx,
                $formaPagamento,
            );
            return Response::json(array_merge(
                [
                    'ok'  => true,
                    'msg' => 'Pagamento registrado. Equipamento aguardando retirada.',
                ],
                $resultado,
            ));
        } catch (InvalidArgumentException | DomainException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/os/busca
     * Busca rápida de OS por ID ou telefone para auto-complete (opcional).
     */
    public function buscar(Request $request): Response
    {
        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return Response::json(['ok' => true, 'ordens' => []]);
        }

        $repo = new OrdemServicoRepository();

        // Busca unificada: ID da OS, nome/telefone do cliente e equipamento
        // (série, nome, fabricante, modelo).
        $resultados = $repo->buscarGlobal($q, 12);
        return Response::json(['ok' => true, 'ordens' => $resultados]);
    }
}
