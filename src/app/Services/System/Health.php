<?php

namespace App\Services\System;

use Illuminate\Support\Facades\DB;

/**
 * État de santé pour la surveillance externe (lot 25, 27.11) : adresse /sante.
 *
 * Répond 200 quand tout va bien, 503 sinon — c'est ce que regardent les services de surveillance
 * gratuits (UptimeRobot, Better Stack…). Aucun contenu des foyers, aucune configuration.
 */
class Health
{
    public function __construct(private readonly TaskRunner $tasks) {}

    /** @return array{status: string, checks: array<string, array{status: string, detail?: string}>} */
    public function check(): array
    {
        $checks = [
            'base' => $this->database(),
            'stockage' => $this->storage(),
            'taches' => $this->taskCheck(),
        ];

        return [
            'status' => collect($checks)->contains(fn ($c) => $c['status'] === 'erreur') ? 'erreur' : 'ok',
            'checks' => $checks,
        ];
    }

    private function database(): array
    {
        try {
            DB::select('select 1');

            return ['status' => 'ok'];
        } catch (\Throwable) {
            return ['status' => 'erreur', 'detail' => 'base injoignable'];
        }
    }

    private function storage(): array
    {
        foreach ([storage_path('app'), storage_path('framework/sessions'), storage_path('framework/cache'), storage_path('logs')] as $path) {
            if (is_dir($path) && ! is_writable($path)) {
                return ['status' => 'erreur', 'detail' => 'écriture impossible'];
            }
        }

        $free = @disk_free_space(storage_path());

        if ($free !== false && $free < 50 * 1024 * 1024) {
            return ['status' => 'erreur', 'detail' => 'disque presque plein'];
        }

        return ['status' => 'ok'];
    }

    /** Tâches : en panne si elles ont déjà tourné mais plus depuis BOUFFE_TASKS_MAX_DELAY minutes. */
    private function taskCheck(): array
    {
        try {
            $last = $this->tasks->lastRun();
        } catch (\Throwable) {
            return ['status' => 'erreur', 'detail' => 'illisible'];
        }

        if (! $last) {
            return ['status' => 'ok', 'detail' => 'jamais lancées'];
        }

        $minutes = (int) floor($last['at']->diffInMinutes(now(), true));
        $max = max(10, (int) config('bouffe.tasks.max_delay', 90));

        return [
            'status' => $minutes > $max ? 'erreur' : 'ok',
            'detail' => "dernier passage il y a {$minutes} min",
        ];
    }
}
