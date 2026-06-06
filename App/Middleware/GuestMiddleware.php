<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;

final class GuestMiddleware implements Middleware
{
    public function handle(Request $request): ?Response
    {
        if (Auth::check()) {
            return Response::redirect('/dashboard');
        }
        return null;
    }
}
