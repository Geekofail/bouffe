<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Invité sur une page protégée → écran de connexion ;
        // utilisateur connecté sur l'écran de connexion → accueil.
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));

        // Sauvegarde automatique si la dernière est trop ancienne (voir config/bouffe.php).
        $middleware->appendToGroup('web', \App\Http\Middleware\AutomaticBackup::class);

        // Derrière le tunnel privé (Tailscale) ou un reverse proxy : l'en-tête X-Forwarded-Proto
        // dit que la requête d'origine était en https:// (lot 16, 20.1).
        // (config() n'est pas encore disponible ici : on lit l'environnement directement.)
        if ($proxies = env('BOUFFE_TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', (string) $proxies));
        }

        // Pages réservées aux comptes complets (lot 15, 18.5).
        $middleware->alias([
            'can.edit' => \App\Http\Middleware\EnsureCanEdit::class,
            'household' => \App\Http\Middleware\EnsureHousehold::class,   // foyer actif (lot 24)
            'admin' => \App\Http\Middleware\EnsureAdmin::class,           // administration de l'installation (25.7)
            'household.owner' => \App\Http\Middleware\EnsureHouseholdOwner::class,
            'two.factor' => \App\Http\Middleware\EnsureTwoFactor::class,     // double authentification obligatoire (lot 25, 27.4)
        ]);

        // En-têtes de sécurité : politique de contenu, HSTS… (lot 25, 27.7).
        $middleware->appendToGroup('web', \App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Erreur grave : e-mail à l'administrateur, un par heure au plus (lot 25, 27.11).
        $exceptions->report(fn (\Throwable $e) => app(\App\Services\System\ErrorAlert::class)->report($e));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
