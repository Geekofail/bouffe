<?php

namespace App\Services\System;

use App\Mail\Notice;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * E-mail à l'administrateur quand une erreur grave survient (lot 25, 27.11) : un par heure au plus,
 * pour ne pas remplir la boîte si une panne se répète à chaque page. Le détail reste dans
 * storage/logs/laravel.log.
 */
class ErrorAlert
{
    public const THROTTLE_KEY = 'bouffe.error-alert';

    public function report(\Throwable $e): void
    {
        if (! config('bouffe.security.error_email')) {
            return;
        }

        try {
            if (! Cache::add(self::THROTTLE_KEY, true, now()->addHour())) {
                return;
            }

            $admins = User::query()->where('is_admin', true)->get();

            if ($admins->isEmpty()) {
                return;
            }

            $where = app()->runningInConsole() ? 'Commande : '.implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 3)) : 'Page : '.request()->method().' '.$this->path();

            Mail::to($admins)->send(new Notice(
                'erreur sur '.parse_url((string) config('app.url'), PHP_URL_HOST),
                'Une erreur est survenue',
                [
                    'Bouffe a rencontré une erreur le '.now()->locale('fr')->isoFormat('D MMMM YYYY [à] HH:mm:ss').'.',
                    class_basename($e).' : '.Str::limit($e->getMessage(), 400),
                    'Fichier : '.Str::after($e->getFile(), base_path().DIRECTORY_SEPARATOR).':'.$e->getLine(),
                    $where,
                ],
                null,
                'Les erreurs suivantes de l\'heure ne sont pas envoyées : le détail complet est dans storage/logs/laravel.log. Paramètres → Diagnostic aide à trouver la cause.',
            ));
        } catch (\Throwable) {
            // Jamais d'erreur en cascade : l'alerte est un plus, le journal reste la référence.
        }
    }

    /** Chemin de la page, sans les jetons (invitation, réinitialisation, tâches) ni les paramètres. */
    private function path(): string
    {
        return preg_replace('#/[A-Za-z0-9]{20,}#', '/…', '/'.ltrim(request()->path(), '/'));
    }
}
