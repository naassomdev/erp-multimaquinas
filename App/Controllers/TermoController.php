<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\TermoService;

/**
 * Controller público (sem AuthMiddleware) para aceite digital do Termo.
 *
 * Rotas:
 *  GET  /termo/{slug}         → Exibe o termo completo + botão de aceite
 *  POST /termo/{slug}/aceitar → Registra o aceite (IP, user-agent)
 */
final class TermoController
{
    public function __construct(
        private readonly TermoService $service = new TermoService(),
    ) {}

    /**
     * GET /termo/{slug} — Página pública com o termo completo.
     */
    public function visualizar(Request $request, string $slug): Response
    {
        $aceite = $this->service->buscarPorSlug($slug);

        if ($aceite === null) {
            return Response::html(View::render('termo/visualizar', [
                'titulo' => 'Termo de Responsabilidade',
                'aceite' => null,
                'equipamentos' => [],
                'erro'   => 'Link inválido ou expirado.',
            ]), 404);
        }

        $equipamentos = $this->service->buscarEquipamentosOs($aceite['os_id']);

        return Response::html(View::render('termo/visualizar', [
            'titulo'       => 'Termo de Responsabilidade — OS #' . $aceite['os_id'],
            'aceite'       => $aceite,
            'equipamentos' => $equipamentos,
            'erro'         => null,
        ]));
    }

    /**
     * POST /termo/{slug}/aceitar — Registra o aceite digital.
     */
    public function aceitar(Request $request, string $slug): Response
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';

        // Se vem com múltiplos IPs (proxy), pega o primeiro
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

        $ok = $this->service->registrarAceite($slug, $ip, $userAgent);

        if (!$ok) {
            return Response::json(['ok' => false, 'error' => 'Link inválido ou expirado.'], 404);
        }

        return Response::json(['ok' => true, 'message' => 'Aceite registrado com sucesso.']);
    }
}
