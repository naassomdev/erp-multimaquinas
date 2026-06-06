<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\AlertaRetiradaService;
use App\Services\TemplateService;

/**
 * Controller para o Painel de Alertas de Retirada e Abandono.
 *
 * Rotas:
 *  GET  /alertas/retirada                                           → Painel visual (por equipamento)
 *  POST /api/alertas/retirada/{os_id}/notificar                    → Registra notificação de retirada (por OS)
 *  POST /api/alertas/retirada/{os_id}/comprovante                  → Upload de comprovante (por OS)
 *  POST /api/alertas/retirada/{os_id}/equip/{equip_idx}/descarte   → Descarte por abandono (por equipamento)
 *  GET  /api/alertas/retirada/contadores                           → JSON com contadores para badges
 */
final class AlertaRetiradaController
{
    public function __construct(
        private readonly AlertaRetiradaService $service = new AlertaRetiradaService(),
        private readonly TemplateService       $tplSvc  = new TemplateService(),
    ) {}

    /**
     * GET /alertas/retirada — Painel dedicado.
     */
    public function index(Request $request): Response
    {
        $filtros = [
            'q'            => trim((string) $request->input('q', '')),
            'nivel'        => trim((string) $request->input('nivel', '')),
            'status'       => trim((string) $request->input('status', '')),
            'renotificar'  => (string) $request->input('renotificar', '') === '1',
        ];

        $pendentes  = $this->service->listarPendentes($filtros);
        $contadores = $this->service->contarAlertas();

        return Response::html(View::render('alertas/retirada', [
            'titulo'      => 'Alertas de Retirada',
            'activeMenu'  => 'alertas',
            'pendentes'   => $pendentes,
            'contadores'  => $contadores,
            'filtroNivel' => $filtros['nivel'],
            'filtros'     => $filtros,
        ]));
    }

    /**
     * POST /api/alertas/retirada/{os_id}/notificar — Registra notificação (por OS).
     */
    public function notificar(Request $request, string $os_id): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            return Response::json(['ok' => false, 'error' => 'Sessão expirada.'], 403);
        }

        $tipo     = (string) $request->input('tipo', 'whatsapp');
        $mensagem = trim((string) $request->input('mensagem', ''));
        $obs      = trim((string) $request->input('obs', ''));

        if ($mensagem === '') {
            $mensagem = 'Notificação de retirada de equipamento enviada ao cliente.';
        }

        $usuario = Auth::user();
        $id = $this->service->registrarNotificacao(
            $os_id,
            $tipo,
            $mensagem,
            isset($usuario['id']) ? (int) $usuario['id'] : null,
            $obs !== '' ? $obs : null,
        );

        return Response::json(['ok' => true, 'id' => $id]);
    }

    /**
     * POST /api/alertas/retirada/{os_id}/comprovante — Upload de print/comprovante.
     */
    public function comprovante(Request $request, string $os_id): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            return Response::json(['ok' => false, 'error' => 'Sessão expirada.'], 403);
        }

        $notifId = (int) $request->input('notificacao_id', 0);
        $file    = $_FILES['comprovante'] ?? null;

        if ($notifId <= 0 || $file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return Response::json(['ok' => false, 'error' => 'Arquivo não enviado ou inválido.'], 400);
        }

        try {
            $url = $this->service->anexarComprovante($notifId, $file);
            return Response::json(['ok' => true, 'url' => $url]);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/alertas/retirada/{os_id}/equip/{equip_idx}/descarte
     * Marca UM equipamento específico como descartado por abandono legal.
     * Não altera outros equipamentos da mesma OS.
     */
    public function descarteEquipamento(Request $request, string $os_id, string $equip_idx): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            return Response::json(['ok' => false, 'error' => 'Sessão expirada.'], 403);
        }

        $usuario   = Auth::user();
        $usuarioId = isset($usuario['id']) ? (int) $usuario['id'] : 0;
        $equipIdx  = (int) $equip_idx;

        try {
            $resultado = $this->service->marcarDescarteEquipamentoPorAbandono($os_id, $equipIdx, $usuarioId);
            return Response::json(array_merge(['ok' => true], $resultado));
        } catch (\DomainException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/alertas/retirada/contadores — Contadores para badges.
     */
    public function contadores(Request $request): Response
    {
        return Response::json($this->service->contarAlertas());
    }
}
