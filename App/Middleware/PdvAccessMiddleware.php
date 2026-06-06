<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\Pdv\PdvSettingsService;

final class PdvAccessMiddleware implements Middleware
{
    public function __construct(
        private readonly PdvSettingsService $settings = new PdvSettingsService(),
    ) {}

    public function handle(Request $request): ?Response
    {
        if (!Auth::check()) {
            return Response::redirect('/login');
        }

        if (!$this->settings->enabled()) {
            return $request->wantsJson()
                ? Response::json(['ok' => false, 'error' => 'Recurso não encontrado.'], 404)
                : Response::html('<h1>404</h1>', 404);
        }

        $mode = $this->settings->mode();
        if ($mode === 'off') {
            return $request->wantsJson()
                ? Response::json(['ok' => false, 'error' => 'Recurso não encontrado.'], 404)
                : Response::html('<h1>404</h1>', 404);
        }

        if ($mode === 'shadow') {
            if (!Auth::temNivel('admin')) {
                return $request->wantsJson()
                    ? Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403)
                    : Response::html('<h1>403</h1>', 403);
            }

            return null;
        }

        if (!Auth::temNivel('admin', 'recepcao')) {
            return $request->wantsJson()
                ? Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403)
                : Response::html('<h1>403</h1>', 403);
        }

        return null;
    }
}
