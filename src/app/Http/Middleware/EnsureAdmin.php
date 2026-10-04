<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Administration de l'installation (lot 24, 25.7) : réservée à son administrateur. */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Réservé à l\'administrateur de l\'installation.');

        return $next($request);
    }
}
