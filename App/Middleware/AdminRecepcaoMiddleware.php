<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;

/**
 * Permite usuários com nível 'admin' ou 'recepcao'.
 * Retorna 403 para JSON ou lança HttpException para HTML.
 */
final class AdminRecepcaoMiddleware implements Middleware
{
    public function handle(Request $request): ?Response
    {
        if (Auth::temNivel('admin', 'recepcao')) {
            return null;
        }

        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        throw new HttpException(403, 'Acesso negado.');
    }
}
