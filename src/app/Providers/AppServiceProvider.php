<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Foyers reliés (lot 26) : liens mémorisés le temps d'une requête.
        $this->app->scoped(\App\Services\Linked\HouseholdLinks::class);

        // Cantine (lot 39) : jours et menus relus une fois par requête (une case du planning les consulte souvent).
        $this->app->scoped(\App\Services\People\CanteenCalendar::class);
        // Remplacements (lot 40) : lus une fois par requête (chaque ligne d'une liste les consulte).
        $this->app->scoped(\App\Services\Recipes\Substitutions::class);
        $this->app->scoped(\App\Services\Stays\StayCoorganizers::class);   // lot 42 : rôles mémorisés le temps d'une requête

        // Assistant culinaire (lot 33) : Mistral Small derrière une interface commune (§7.4).
        $this->app->bind(\App\Services\Assistant\CookingAssistant::class, \App\Services\Assistant\MistralAssistant::class);
    }

    /**
     * Accès sécurisé (lot 16, point 20.1).
     *
     * Bouffe tourne sur le PC de la maison, en http:// sur le Wi-Fi. Pour y accéder de
     * l'extérieur, un tunnel privé (Tailscale) apporte le HTTPS ; c'est lui qui le termine,
     * et Laravel doit alors savoir que la requête d'origine était bien en https://, sinon
     * les liens et les formulaires repartent en http://.
     */
    public function boot(): void
    {
        if (config('bouffe.security.force_https')) {
            URL::forceScheme('https');
        }

        // Balises <script> écrites dans les vues : @nonce les autorise (politique de contenu, lot 25).
        Blade::directive('nonce', fn () => '<?php if ($__nonce = \\'.Vite::class.'::cspNonce()) echo \'nonce="\'.e($__nonce).\'"\'; ?>');

        // Journal du foyer (lot 30, 30.2).
        \App\Services\Activity\ActivityWatcher::register();
    }
}
