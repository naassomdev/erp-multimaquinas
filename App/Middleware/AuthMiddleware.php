<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;

final class AuthMiddleware implements Middleware
{
    public function handle(Request $request): ?Response
    {
        if (Auth::check()) return null;

        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'error' => 'Não autenticado'], 401);
        }

        Flash::set('redirect_after_login', $request->path);
        Flash::set('login_error', 'Faça login para continuar.');
        return Response::redirect('/login');
    }
}
