<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\NotificacaoTecnicoRepository;

final class NotificacaoApiController
{
    public function __construct(
        private readonly NotificacaoTecnicoRepository $notifRepo = new NotificacaoTecnicoRepository(),
    ) {}

    public function listar(Request $request): Response
    {
        $limit = max(1, min(100, (int) $request->input('limit', 50)));
        $destino = $this->destinoAtual();
        return Response::json([
            'ok'            => true,
            'notificacoes'  => $this->notifRepo->listarNaoLidas($limit, $destino),
            'total_naolidas'=> $this->notifRepo->contarNaoLidas($destino),
        ]);
    }

    public function marcarLida(Request $request, string $id): Response
    {
        $this->notifRepo->marcarLida((int) $id, $this->destinoAtual());
        return Response::json(['ok' => true]);
    }

    public function marcarTodasComoLidas(Request $request): Response
    {
        $this->notifRepo->marcarTodasComoLidas($this->destinoAtual());
        return Response::json(['ok' => true]);
    }

    private function destinoAtual(): string
    {
        return Auth::temNivel('admin', 'recepcao') ? 'recepcao' : 'oficina';
    }
}
