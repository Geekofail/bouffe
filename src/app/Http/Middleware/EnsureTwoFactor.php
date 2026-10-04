<?php

namespace App\Http\Middleware;

use App\Services\Security\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Double authentification obligatoire (lot 25, 27.4 ; Q41) : l'administrateur et les responsables
 * de foyer sans double authentification ne voient que « Mon compte » tant qu'ils ne l'ont pas activée.
 * Actif quand BOUFFE_2FA_REQUIRED est vrai (par défaut : en production seulement).
 */
class EnsureTwoFactor
{
    /** Pages ouvertes même sans double authentification. */
    private const ALLOWED = ['account.*', 'privacy', 'logout', 'no-household', 'households.switch', 'offline'];

    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->hasTwoFactor() || $request->routeIs(...self::ALLOWED) || ! $this->twoFactor->required($user)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Double authentification à activer dans « Mon compte ».');
        }

        return redirect()->route('account.show')->with('status', 'Activez d\'abord la double authentification.');
    }
}
