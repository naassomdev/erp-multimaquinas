<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;

final class CsrfMiddleware implements Middleware
{
    private const PROTECTED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request): ?Response
    {
        if (!in_array($request->method, self::PROTECTED_METHODS, true)) {
            return null;
        }

        $token = $request->server['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!is_string($token) || $token === '') {
            $token = (string) $request->input('_csrf', '');
        }

        if (!Csrf::check($token)) {
            return Response::json([
                'ok'    => false,
                'error' => 'CSRF inválido — recarregue a página.',
            ], 419);
        }

        return null;
    }
}
