<?php

namespace App\Services\Planning;

use App\Enums\MealType;
use App\Models\MealOccasion;
use App\Models\PlannedMeal;
use App\Models\Reminder;
use App\Models\StayParticipant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Le rendez-vous du soir (lot 37, 37.1, règle R38).
 *
 * Une fois par jour, à l'heure choisie par chacun (par défaut 1 h 30 après l'heure du dîner), une
 * seule notification regroupe ce qui reste à faire : clôturer le dernier repas (« Chili con carne :
 * c'était mangé ? »), ce qu'il faut préparer pour demain (décongeler, faire tremper) et les gamelles.
 * Elle ouvre la page « Ce soir » (/ce-soir), où tout se règle d'un geste ; rien n'est fait sans geste.
 *
 * - Elle ne part pas s'il n'y a rien à faire, ni si la personne était absente des repas à clôturer
 *   (convives de la case, gamelle de quelqu'un d'autre) ou en séjour ce jour-là.
 * - Les rappels de la veille (14.6) dus le soir sont regroupés dedans au lieu d'arriver un par un ;
 *   si le rendez-vous n'a pas pu partir (ordinateur éteint, heures calmes), ils repartent seuls.
 */
class EveningRendezvous
{
    /** Après l'heure choisie, le message peut encore partir pendant 3 heures (tâche toutes les 5 à 15 min). */
    public const WINDOW_HOURS = 3;

    /** Un rappel dû à partir de 16 h est un rappel « du soir », regroupé dans le rendez-vous. */
    public const EVENING_FROM_HOUR = 16;

    public function __construct(
        private readonly MealClosing $closing,
        private readonly Lunchboxes $lunchboxes,
        private readonly WeekPlanner $planner,
    ) {}

    /* ================================================================ Réglages (Q53) */

    /** Heure par défaut : 1 h 30 après l'heure supposée du dîner (19 h → 20 h 30). */
    public static function defaultTime(): string
    {
        $minutes = (int) config('bouffe.planning.meal_hour', 19) * 60 + 90;

        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }

    /**
     * Réglage d'une personne : activé par défaut pour les comptes complets.
     *
     * @return array{on: bool, time: string}
     */
    public static function settings(User $user): array
    {
        $time = (string) $user->preference('notify.evening_at', self::defaultTime());

        return [
            'on' => (bool) $user->preference('notify.evening', $user->canEdit()),
            'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : self::defaultTime(),
        ];
    }

    /** Heure du rendez-vous pour un jour donné. */
    public function dueAt(User $user, Carbon $day): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', self::settings($user)['time']));

        return $day->copy()->setTime($hour, $minute);
    }

    /** Fin de la fenêtre d'envoi (jamais après minuit). */
    public function windowEnd(User $user, Carbon $day): Carbon
    {
        return $this->dueAt($user, $day)->addHours(self::WINDOW_HOURS)->min($day->copy()->endOfDay());
    }

    public function isDue(User $user, Carbon $now): bool
    {
        return self::settings($user)['on']
            && $now->gte($this->dueAt($user, $now))
            && $now->lt($this->windowEnd($user, $now));
    }

    /* ================================================================ Contenu */

    /**
     * Repas à clôturer : ceux du jour, puis les jours précédents (14 jours au plus, R23).
     * Avec une personne : sans les repas dont elle était absente.
     *
     * @return Collection<int, PlannedMeal>
     */
    public function toClose(Carbon $now, ?User $user = null): Collection
    {
        $today = PlannedMeal::query()
            ->whereIn('type', [MealType::Recipe->value, MealType::Leftover->value])
            ->whereNull('cooked_at')->whereNull('skipped_at')
            ->whereDate('date', $now->toDateString())
            ->with('recipe', 'leftoverOf.recipe', 'slot', 'forPerson')
            ->get()
            // Le dernier repas de la journée d'abord : c'est celui qu'on vient de finir.
            ->sortByDesc(fn (PlannedMeal $meal) => [$meal->slot?->sort_order ?? 0, $meal->position])
            ->values();

        // Après minuit, la journée de la veille n'est pas encore « hier » : pending() la reprend sans doublon.
        $meals = $today->concat($this->closing->pending($now)->reject(fn (PlannedMeal $m) => $today->contains('id', $m->id)))->values();

        if (! $user) {
            return $meals;
        }

        $absent = $this->absentCells($meals, $user);

        return $meals->reject(fn (PlannedMeal $meal) => isset($absent[$meal->date->toDateString().'|'.$meal->meal_slot_id])
            || ($meal->isLunchbox() && $meal->forPerson?->user_id !== null && (int) $meal->forPerson->user_id !== (int) $user->id))->values();
    }

    /**
     * Ce qu'il faut faire ce soir pour les repas des jours suivants (rappels 14.6 encore à faire).
     *
     * @return Collection<int, Reminder>
     */
    public function preparations(Carbon $now): Collection
    {
        return Reminder::query()->pending()
            ->where('due_at', '<=', $now->copy()->endOfDay()->toDateTimeString())
            ->whereHas('meal', fn ($q) => $q->whereDate('date', '>', $now->toDateString()))
            ->with('meal.recipe', 'meal.slot', 'meal.leftoverOf.recipe')
            ->orderBy('due_at')
            ->get();
    }

    /** Gamelles du lendemain, à préparer ce soir (32.3). @return Collection<int, PlannedMeal> */
    public function lunchboxes(Carbon $now): Collection
    {
        return $this->lunchboxes->toPrepareOn($now->copy()->startOfDay());
    }

    /**
     * Restes encore à placer : repas du jour et de la veille déjà mangés, avec des portions en trop.
     *
     * @return Collection<int, array{meal: PlannedMeal, remaining: float}>
     */
    public function leftovers(Carbon $now): Collection
    {
        return $this->planner->availableLeftovers($now->copy()->startOfDay(), 1)
            ->filter(fn (array $row) => $row['meal']->cooked_at !== null)
            ->values();
    }

    /** Menu du lendemain, pour mémoire. @return Collection<int, PlannedMeal> */
    public function tomorrow(Carbon $now): Collection
    {
        return PlannedMeal::query()->whereDate('date', $now->copy()->addDay()->toDateString())
            ->whereNull('skipped_at')
            ->with('recipe', 'leftoverOf.recipe', 'slot', 'forPerson')
            ->get()
            ->sortBy(fn (PlannedMeal $meal) => [$meal->slot?->sort_order ?? 0, $meal->position])
            ->values();
    }

    /** La personne participe-t-elle à un séjour de son foyer ce jour-là (lot 34) ? */
    public function awayOn(User $user, Carbon $day): bool
    {
        return StayParticipant::query()->where('user_id', $user->id)
            ->whereHas('stay', fn ($q) => $q->whereDate('starts_on', '<=', $day->toDateString())->whereDate('ends_on', '>=', $day->toDateString()))
            ->with('stay')->get()
            ->contains(fn (StayParticipant $p) => $p->stay && $p->presentOn($day, $p->stay));
    }

    /* ================================================================ Notification */

    /**
     * Le message du soir pour une personne, ou null s'il n'y a rien à faire (R38).
     *
     * @return array{key: string, type: string, title: string, body: string|null, url: string, tag: string, badge: int}|null
     */
    public function message(User $user, Carbon $now, string $key): ?array
    {
        if ($this->awayOn($user, $now)) {
            return null;
        }

        $meals = $this->toClose($now, $user);
        $preparations = $this->preparations($now);
        $boxes = $this->lunchboxes($now);

        if ($meals->isEmpty() && $preparations->isEmpty() && $boxes->isEmpty()) {
            return null;
        }

        $parts = [];

        if ($meals->isNotEmpty()) {
            $title = $meals->first()->label().' : c\'était mangé ?';

            if ($meals->count() > 1) {
                $others = $meals->count() - 1;
                $parts[] = '+ '.$others.' autre'.($others > 1 ? 's' : '').' repas à clôturer';
            }
        } elseif ($preparations->isNotEmpty()) {
            $title = 'Pour demain : '.mb_strtolower(mb_substr($preparations->first()->title, 0, 1)).mb_substr($preparations->first()->title, 1);
            $preparations = $preparations->slice(1);
        } else {
            $title = $boxes->count() === 1 ? 'Une gamelle à préparer pour demain' : $boxes->count().' gamelles à préparer pour demain';
            $boxes = collect();
        }

        if ($preparations->isNotEmpty()) {
            $parts[] = ($meals->isNotEmpty() ? 'Pour demain : ' : 'Aussi : ')
                .$preparations->take(3)->map(fn (Reminder $r) => mb_strtolower(mb_substr($r->title, 0, 1)).mb_substr($r->title, 1))->join(', ')
                .($preparations->count() > 3 ? '…' : '');
        }

        if ($boxes->isNotEmpty()) {
            $parts[] = $boxes->count() === 1 ? '1 gamelle à préparer' : $boxes->count().' gamelles à préparer';
        }

        return [
            'key' => $key, 'type' => 'evening',
            'title' => $title,
            'body' => $parts === [] ? null : implode(' · ', $parts),
            'url' => route('evening'),
            'tag' => 'evening',
            // Pastille sur l'icône (iOS 16.4+) : les repas à clôturer.
            'badge' => $meals->count(),
        ];
    }

    /**
     * Ce rappel est-il regroupé dans le rendez-vous du soir (et donc pas envoyé seul) ?
     *
     * Oui si la personne a le rendez-vous, que le rappel tombe le soir, et que le rendez-vous de ce
     * jour-là est encore à venir ou est parti. Si la fenêtre est passée sans envoi, il repart seul.
     */
    public function groups(User $user, Reminder $reminder, Carbon $now, callable $delivered): bool
    {
        if (! self::settings($user)['on'] || $reminder->due_at->hour < self::EVENING_FROM_HOUR) {
            return false;
        }

        $day = $reminder->due_at->copy()->startOfDay();

        return $now->lt($this->windowEnd($user, $day)) || $delivered($day);
    }

    /* ================================================================ Outils */

    /** Cases où la personne est notée absente (convives, lot 18). @return array<string, true> */
    private function absentCells(Collection $meals, User $user): array
    {
        if ($meals->isEmpty()) {
            return [];
        }

        $dates = $meals->map(fn (PlannedMeal $m) => $m->date->copy()->startOfDay());

        return MealOccasion::query()
            ->between($dates->min(), $dates->max())
            ->get()
            ->filter(fn (MealOccasion $o) => in_array((int) $user->id, array_map('intval', $o->absent_user_ids ?? []), true))
            ->mapWithKeys(fn (MealOccasion $o) => [$o->date->toDateString().'|'.$o->meal_slot_id => true])
            ->all();
    }
}
