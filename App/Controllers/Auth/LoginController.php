<?php
declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\AuthService;

final class LoginController
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
    ) {}

    public function show(Request $request): Response
    {
        return Response::html(View::render('auth/login', [
            'titulo'    => 'Entrar — ERP Multimáquinas',
            'erro'      => Flash::get('login_error'),
            'sucesso'   => Flash::get('login_success'),
            'email_old' => Flash::old('email'),
        ]));
    }

    public function authenticate(Request $request): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('login_error', 'Sessão expirada. Tente novamente.');
            return Response::redirect('/login');
        }

        $email = trim((string) $request->input('email', ''));
        $senha = (string) $request->input('senha', '');

        if ($email === '' || $senha === '') {
            Flash::keepOld(['email' => $email]);
            Flash::set('login_error', 'Informe e-mail e senha.');
            return Response::redirect('/login');
        }

        $usuario = $this->auth->tentarLogin($email, $senha);

        if ($usuario === null) {
            Flash::keepOld(['email' => $email]);
            Flash::set('login_error', 'E-mail ou senha incorretos.');
            return Response::redirect('/login');
        }

        Csrf::rotate();
        Flash::clearOld();

        $destino = (string) (Flash::get('redirect_after_login') ?? '/dashboard');
        if ($destino === '' || $destino === '/login') $destino = '/dashboard';

        return Response::redirect($destino);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return Response::redirect('/login');
    }
}
