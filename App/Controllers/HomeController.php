<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class HomeController
{
    public function index(Request $request): Response
    {
        if (\App\Core\Auth::check()) {
            return Response::redirect('/dashboard');
        }
        return Response::redirect('/login');
    }

    public function ola(Request $request): Response
    {
        return Response::json([
            'ok'       => true,
            'mensagem' => 'API respondendo',
            'agora'    => date('c'),
        ]);
    }

    public function ping(Request $request): Response
    {
        return Response::json(['pong' => true]);
    }
}
