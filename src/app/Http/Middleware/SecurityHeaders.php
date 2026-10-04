<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité (lot 25, 27.7).
 *
 *  - Politique de contenu (CSP) : scripts de Bouffe seulement (fichiers /build et balises
 *    marquées d'un « nonce » tiré à chaque page), aucune ressource d'un autre site, pas d'affichage
 *    dans le cadre d'un autre site. 'unsafe-eval' reste nécessaire à Alpine (expressions x-data…).
 *  - HSTS : le navigateur ne repasse plus jamais en http:// (seulement quand la page est en https).
 *  - Divers : pas de devinette de type de fichier, adresse d'origine limitée, caméra réservée au site.
 *
 * BOUFFE_CSP : enforce (appliquée), report (erreurs seulement signalées dans la console), off.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = (string) config('bouffe.security.csp', 'enforce');

        if ($mode !== 'off') {
            Vite::useCspNonce();
        }

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('Referrer-Policy', 'same-origin', false);
        $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()', false);
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin', false);

        if ($mode !== 'off' && ! $headers->has('Content-Security-Policy')) {
            $headers->set($mode === 'report' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $this->policy(Vite::cspNonce()));
        }

        if (config('bouffe.security.hsts') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age='.(int) config('bouffe.security.hsts_seconds', 31536000), false);
        }

        return $response;
    }

    public function policy(?string $nonce): string
    {
        $script = "'self'".($nonce ? " 'nonce-{$nonce}'" : '')." 'unsafe-eval'";

        // En développement, le serveur Vite (npm run dev) sert les fichiers depuis une autre adresse.
        $dev = '';

        if (is_file(public_path('hot'))) {
            $origin = rtrim((string) file_get_contents(public_path('hot')));
            $ws = preg_replace('#^http#', 'ws', $origin);
            $script .= " {$origin}";
            $dev = " {$origin} {$ws}";
        }

        return implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            // Styles en ligne : attributs style="" (largeurs de barres, Alpine) et balises de Livewire.
            "style-src 'self' 'unsafe-inline'".($dev ? ' '.trim(explode(' ', trim($dev))[0]) : ''),
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'{$dev}",
            "media-src 'self' blob:",
            "worker-src 'self'",
            "manifest-src 'self'",
            "frame-src 'none'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
}
