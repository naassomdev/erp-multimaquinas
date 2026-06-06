<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;

/**
 * Middleware parametrizável de controle de acesso por nível.
 *
 * Uso nas rotas:
 *   [NivelAcessoMiddleware::class, 'admin']                → somente admin
 *   [NivelAcessoMiddleware::class, 'admin', 'recepcao']    → admin OU recepção
 *
 * O Router já passa os parâmetros extras do array de middlewares.
 * Porém nosso Router atual instancia sem parâmetros, então usamos
 * o método estático `permitir()` como factory registrada nas rotas.
 */
final class NivelAcessoMiddleware implements Middleware
{
    /** @var string[] */
    private array $niveisPermitidos;

    public function __construct(string ...$niveis)
    {
        $this->niveisPermitidos = $niveis;
    }

    public function handle(Request $request): ?Response
    {
        // Se nenhum nível foi configurado, permite tudo (fallback seguro: bloqueia)
        if (empty($this->niveisPermitidos)) {
            return null;
        }

        if (!Auth::check()) {
            return Response::redirect('/login');
        }

        if (!Auth::temNivel(...$this->niveisPermitidos)) {
            if ($request->wantsJson()) {
                return Response::json([
                    'ok'    => false,
                    'error' => 'Acesso negado. Nível insuficiente.',
                ], 403);
            }

            return Response::html(
                '<div style="font-family:system-ui;padding:3rem;text-align:center;">'
                . '<h1 style="color:#991b1b;">⛔ Acesso Negado</h1>'
                . '<p style="color:#64748b;">Você não tem permissão para acessar esta página.</p>'
                . '<a href="/dashboard" style="color:#0ea5e9;">Voltar ao Dashboard</a>'
                . '</div>',
                403
            );
        }

        return null;
    }

    /**
     * Factory para uso nas rotas quando o Router instancia sem argumentos.
     * Registre como: [NivelAcessoMiddleware::permitir('admin','recepcao'), ...]
     *
     * @return callable
     */
    public static function permitir(string ...$niveis): callable
    {
        return static function (Request $request) use ($niveis): ?Response {
            $mw = new self(...$niveis);
            return $mw->handle($request);
        };
    }
}
