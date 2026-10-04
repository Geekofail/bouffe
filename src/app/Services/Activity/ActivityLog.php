<?php

namespace App\Services\Activity;

use App\Models\ActivityEvent;
use App\Support\CurrentHousehold;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Journal du foyer (30.2) : « Monique a coché 12 articles », « Pierre a planifié 3 repas ».
 *
 *  - une ligne par geste, mais les gestes répétés d'une même personne sont **regroupés** tant qu'ils
 *    se suivent de moins de 30 minutes (cocher 12 articles fait une ligne, pas douze) ;
 *  - seules les actions faites par quelqu'un de connecté sont notées (pas les tâches planifiées) ;
 *  - gardé 30 jours ;
 *  - noter ne doit jamais empêcher le geste lui-même : une erreur ici est ignorée.
 */
class ActivityLog
{
    public const DAYS = 30;

    public const GROUP_MINUTES = 30;

    /** Types et libellés des filtres du journal. */
    public const TYPES = [
        'planning' => 'Planning',
        'shopping' => 'Courses',
        'stock' => 'Stock',
        'recipe' => 'Recettes',
        'wish' => 'Envies',
        'budget' => 'Budget',
        'undo' => 'Annulations',
    ];

    /**
     * @param  Closure(int): string|null  $grouped  libellé quand plusieurs gestes sont regroupés (reçoit leur nombre)
     */
    public function record(string $type, string $summary, ?Model $subject = null, ?Closure $grouped = null): void
    {
        $user = auth()->user();
        $household = CurrentHousehold::id();

        if (! $user || ! $household) {
            return;
        }

        try {
            if ($grouped) {
                $recent = ActivityEvent::query()
                    ->where('user_id', $user->id)->where('type', $type)
                    ->where('subject_type', $subject ? $subject->getTable() : null)
                    ->where('subject_id', $subject?->getKey())
                    ->where('updated_at', '>=', now()->subMinutes(self::GROUP_MINUTES))
                    ->latest('id')->first();

                if ($recent) {
                    $count = $recent->count + 1;
                    $recent->update(['count' => $count, 'summary' => mb_substr($grouped($count), 0, 255)]);

                    return;
                }
            }

            ActivityEvent::create([
                'user_id' => $user->id,
                'type' => $type,
                'subject_type' => $subject?->getTable(),
                'subject_id' => $subject?->getKey(),
                'count' => 1,
                'summary' => mb_substr($summary, 0, 255),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Lignes du journal, les plus récentes d'abord.
     *
     * @return Collection<int, ActivityEvent>
     */
    public function recent(?int $userId = null, ?string $family = null, ?Carbon $now = null): Collection
    {
        $now ??= now();

        return ActivityEvent::query()
            ->where('updated_at', '>=', $now->copy()->subDays(self::DAYS))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($family, fn ($q) => $q->where(fn ($w) => $w->where('type', $family)->orWhere('type', 'like', $family.'.%')))
            ->with('user')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(300)
            ->get();
    }

    /** Oublie ce qui a plus de 30 jours (tâche planifiée). @return int lignes supprimées */
    public function purge(?Carbon $now = null): int
    {
        $now ??= now();

        return ActivityEvent::query()->where('updated_at', '<', $now->copy()->subDays(self::DAYS))->delete();
    }

    /** « 1 article », « 3 articles ». */
    public static function plural(int $count, string $singular, ?string $plural = null): string
    {
        return $count.' '.($count > 1 ? ($plural ?? $singular.'s') : $singular);
    }
}
