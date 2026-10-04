<?php

namespace App\Services\Kitchen;

use App\Models\KitchenTimer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Minuteurs partagés (lot 41, 41.1).
 *
 * Un minuteur lancé sur un appareil (mode cuisine, repas complet, écran de cuisine) est enregistré :
 * chaque page ouverte du foyer le relit toutes les quelques secondes et compte le temps elle-même.
 * La page qui le voit finir sonne à la seconde et le signale (« a sonné ») ; si aucune ne l'a vu,
 * la tâche planifiée envoie une notification à la personne qui l'a lancé — à quelques minutes près.
 * On l'arrête de n'importe quel appareil.
 */
class KitchenTimers
{
    /** 24 heures au plus. */
    public const MAX_SECONDS = 86_400;

    /** Un minuteur fini et jamais arrêté disparaît des écrans au bout d'une heure. */
    public const FORGET_MINUTES = 60;

    /** Au-delà, plus de notification : elle arriverait trop tard pour servir. */
    public const NOTIFY_WITHIN_MINUTES = 30;

    public function start(string $label, int $seconds, ?string $source = null, ?User $by = null): KitchenTimer
    {
        $label = Str::limit(Str::squish($label), 120, '') ?: 'Minuteur';

        if ($seconds < 1 || $seconds > self::MAX_SECONDS) {
            throw new InvalidArgumentException('Durée invalide : de 1 seconde à 24 heures.');
        }

        $this->purge();

        return KitchenTimer::create([
            'user_id' => ($by ?? auth()->user())?->id,
            'label' => $label,
            'duration' => $seconds,
            'ends_at' => now()->addSeconds($seconds),
            'source' => $source !== null && preg_match('/^[a-z0-9:_-]{1,40}$/', $source) ? $source : null,
        ]);
    }

    /** « Arrêter », ou « OK » une fois fini : disparaît de tous les appareils. */
    public function stop(KitchenTimer $timer): void
    {
        if (! $timer->stopped_at) {
            $timer->update(['stopped_at' => now()]);
        }
    }

    /** Une page ouverte et visible l'a fait sonner : pas besoin de notification. */
    public function rang(KitchenTimer $timer): void
    {
        if (! $timer->rang_at && $timer->isFinished(now()->addSeconds(2))) {
            $timer->update(['rang_at' => now()]);
        }
    }

    /**
     * Les minuteurs à montrer : en cours, ou finis depuis moins d'une heure et pas encore arrêtés.
     *
     * @return Collection<int, KitchenTimer>
     */
    public function current(?Carbon $now = null): Collection
    {
        $now ??= now();

        return KitchenTimer::query()->running()
            ->where('ends_at', '>', $now->copy()->subMinutes(self::FORGET_MINUTES)->toDateTimeString())
            ->with('user:id,name')
            ->orderBy('ends_at')
            ->get();
    }

    /**
     * Ce que lisent les pages : heure du serveur et minuteurs (en millisecondes, pour JavaScript).
     *
     * @return array{now: int, timers: list<array{id: int, label: string, endsAt: int, duration: int, by: string|null, mine: bool, source: string|null}>}
     */
    public function payload(?User $viewer = null): array
    {
        return [
            'now' => (int) round(microtime(true) * 1000),
            'timers' => $this->current()->map(fn (KitchenTimer $timer) => [
                'id' => $timer->id,
                'label' => $timer->label,
                'endsAt' => $timer->ends_at->getTimestamp() * 1000,
                'duration' => $timer->duration * 1000,
                'by' => $timer->user?->name,
                'mine' => $viewer !== null && (int) $timer->user_id === (int) $viewer->id,
                'source' => $timer->source,
            ])->values()->all(),
        ];
    }

    /**
     * Minuteurs finis que personne n'a vus sonner, et pas encore signalés (tâche planifiée).
     *
     * @return Collection<int, KitchenTimer>
     */
    public function toNotify(Carbon $now): Collection
    {
        return KitchenTimer::query()->running()
            ->whereNull('rang_at')->whereNull('notified_at')
            ->where('ends_at', '<=', $now->toDateTimeString())
            ->where('ends_at', '>=', $now->copy()->subMinutes(self::NOTIFY_WITHIN_MINUTES)->toDateTimeString())
            ->orderBy('ends_at')
            ->get();
    }

    /** Page à ouvrir depuis la notification : celle où le minuteur a été lancé. */
    public function urlFor(KitchenTimer $timer): string
    {
        if (preg_match('/^recette:(\d+)$/', (string) $timer->source, $m) && ($recipe = \App\Models\Recipe::query()->find($m[1]))) {
            return route('recipes.cook', $recipe);
        }

        if (preg_match('/^repas:(\d{4}-\d{2}-\d{2})-(\d+)$/', (string) $timer->source, $m)) {
            return route('planner.cook', ['date' => $m[1], 'slot' => (int) $m[2]]);
        }

        return route('kitchen');
    }

    /** Les vieux minuteurs (plus d'un jour) sont oubliés. */
    public function purge(): void
    {
        KitchenTimer::query()->where('ends_at', '<', now()->subDay()->toDateTimeString())->delete();
    }
}
