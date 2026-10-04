<?php

namespace App\Http\Middleware;

use App\Services\Shortcuts\ShortcutTokens;
use App\Support\CurrentHousehold;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Raccourcis (lot 38, règle R39) : l'appel porte le jeton personnel dans l'en-tête
 * « Authorization: Bearer bouffe_… ». Pas de session ni de cookie : la personne et son foyer sont
 * ceux du jeton, le temps de la demande. 60 demandes par heure et par jeton.
 */
class AuthenticateShortcutToken
{
    public const PER_HOUR = 60;

    public function __construct(private readonly ShortcutTokens $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokens->find($request->bearerToken() ?? $request->header('X-Bouffe-Jeton'));

        if (! $token || ! $token->user || ! $token->user->households()->whereKey($token->household_id)->whereNull('disabled_at')->exists()) {
            return $this->say('Jeton inconnu ou révoqué : créez-en un nouveau dans Bouffe, Mon compte, Raccourcis.', 401);
        }

        $key = 'raccourci:'.$token->id;

        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            return $this->say('Trop de demandes : 60 par heure au plus. Réessayez plus tard.', 429);
        }

        RateLimiter::hit($key, 3600);
        $this->tokens->touch($token);
        auth()->setUser($token->user);

        return CurrentHousehold::run($token->household_id, fn () => $next($request));
    }

    private function say(string $message, int $status): Response
    {
        return response($message, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
