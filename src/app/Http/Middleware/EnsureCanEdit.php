<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages réservées aux comptes complets (18.5).
 *
 * Un compte « consultation et courses » voit tout mais ne modifie que la liste de courses ;
 * il est renvoyé à l'accueil avec un message plutôt que sur une page d'erreur.
 */
class EnsureCanEdit
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canEdit()) {
            return redirect()
                ->route('dashboard')
                ->with('status', 'Votre compte est en consultation : demandez à un compte complet de faire cette modification.');
        }

        return $next($request);
    }
}
