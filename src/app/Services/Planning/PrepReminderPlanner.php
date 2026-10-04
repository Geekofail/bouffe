<?php

namespace App\Services\Planning;

use App\Enums\ReminderType;
use App\Models\PlannedMeal;
use App\Models\Reminder;
use App\Models\StockItem;
use App\Support\NameNormalizer;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Préparation anticipée (14.6, règle R21).
 *
 * Les rappels ne se saisissent pas : ils se déduisent du repas prévu — un ingrédient qui n'est
 * qu'au congélateur, un temps de repos long, une étape qui dit « la veille », « tremper »,
 * « mariner ». Ils sont recalculés à chaque passage : déplacer ou supprimer un repas suffit
 * à les mettre à jour, et « fait » / « ignoré » sont conservés tant que le rappel a du sens.
 */
class PrepReminderPlanner
{
    /** Repos à partir duquel il faut s'y prendre à l'avance. */
    public const LONG_REST_MINUTES = 360;

    /** Mots des étapes qui demandent de s'y prendre la veille. */
    public const KEYWORDS = [
        // Le geste précis d'abord : « la veille » ne sert que si rien de plus parlant n'est trouvé.
        'tremper' => ReminderType::Soak,
        'trempage' => ReminderType::Soak,
        'mariner' => ReminderType::Marinate,
        'marinade' => ReminderType::Marinate,
        'macerer' => ReminderType::Marinate,
        'decongeler' => ReminderType::Thaw,
        'sortir du congelateur' => ReminderType::Thaw,
        'la veille' => ReminderType::DayBefore,
    ];

    /** Recalcule les rappels des repas d'une période. */
    public function sync(Carbon $from, Carbon $to): int
    {
        $meals = PlannedMeal::query()
            ->between($from->copy()->startOfDay(), $to->copy()->startOfDay())
            ->with(['recipe.steps', 'recipe.ingredients.ingredient', 'leftoverOf.recipe', 'slot'])
            ->get();

        // Rappels de repas disparus ou déjà mangés : plus de raison d'exister.
        Reminder::query()
            ->whereHas('meal', fn ($q) => $q->between($from->copy()->startOfDay(), $to->copy()->startOfDay())->whereNotNull('cooked_at'))
            ->delete();

        $frozen = $this->frozenIngredients();
        $count = 0;

        foreach ($meals as $meal) {
            $count += $this->syncMeal($meal, $frozen);
        }

        return $count;
    }

    /**
     * @param  array<int, string>|null  $frozen  ingrédients présents uniquement au congélateur
     * @return int nombre de rappels en attente pour ce repas
     */
    public function syncMeal(PlannedMeal $meal, ?array $frozen = null): int
    {
        if ($meal->cooked_at) {
            $meal->reminders()->delete();

            return 0;
        }

        $expected = $this->expectedFor($meal, $frozen ?? $this->frozenIngredients());

        DB::transaction(function () use ($meal, $expected) {
            $existing = $meal->reminders()->get()->keyBy('key');

            foreach ($expected as $key => $data) {
                $reminder = $existing->get($key);

                if ($reminder) {
                    // Le texte et l'heure suivent le repas ; « fait » et « ignoré » restent.
                    $reminder->update(['title' => $data['title'], 'detail' => $data['detail'], 'due_at' => $data['due_at'], 'type' => $data['type']]);

                    continue;
                }

                Reminder::create([...$data, 'planned_meal_id' => $meal->id, 'key' => $key, 'status' => Reminder::PENDING]);
            }

            $meal->reminders()->whereNotIn('key', array_keys($expected))->delete();
        });

        return collect($expected)->count();
    }

    /**
     * Rappels attendus pour un repas.
     *
     * @param  array<int, string>  $frozen
     * @return array<string, array{type: string, title: string, detail: string|null, due_at: Carbon}>
     */
    public function expectedFor(PlannedMeal $meal, array $frozen, bool $withReception = true): array
    {
        $recipe = $meal->eatenRecipe();

        if (! $recipe || $meal->date->lt(Carbon::today()->subDay())) {
            return [];
        }

        $expected = [];
        $label = $meal->isLeftover() ? 'Restes : '.$recipe->title : $recipe->title;
        $occasion = $this->occasionOf($meal);
        $when = $this->mealMoment($meal, $occasion);

        // 0. Plat déjà cuisiné (batch cooking, 14.8) ou restes d'un plat : s'il est au congélateur, le sortir la veille.
        $dish = $this->dishFor($meal);

        if ($dish && ($dish->isFrozen() || $dish->location?->type === \App\Enums\LocationType::Freezer)) {
            $expected['dish:'.$dish->id] = [
                'type' => ReminderType::Thaw->value,
                'title' => 'Décongeler '.mb_strtolower($dish->name()),
                'detail' => $label.' · '.$meal->date->locale('fr')->isoFormat('dddd D MMMM'),
                'due_at' => $this->dayBefore($meal),
            ];
        }

        // Un plat préparé à l'avance n'a plus besoin des rappels de sa recette.
        if ($meal->isPrepared()) {
            return $withReception ? [...$expected, ...$this->receptionTasks($meal, $occasion, $frozen)] : $expected;
        }

        // 1. Ingrédients qui ne sont qu'au congélateur.
        foreach ($recipe->ingredients as $line) {
            $ingredient = $line->ingredient;

            if (! $ingredient || $line->is_optional || ! isset($frozen[$ingredient->id])) {
                continue;
            }

            $expected['thaw:'.$ingredient->id] = [
                'type' => ReminderType::Thaw->value,
                'title' => 'Sortir '.mb_strtolower($ingredient->name).' du congélateur',
                'detail' => $label.' · '.$meal->date->locale('fr')->isoFormat('dddd D MMMM'),
                'due_at' => $this->dayBefore($meal),
            ];
        }

        // 2. Repos long : on remonte le temps depuis l'heure du repas.
        if (! $meal->isLeftover() && (int) $recipe->rest_minutes >= self::LONG_REST_MINUTES) {
            $start = $when->copy()->subMinutes((int) $recipe->rest_minutes + (int) $recipe->prep_minutes);

            $expected['rest'] = [
                'type' => ReminderType::Rest->value,
                'title' => 'Préparer '.mb_strtolower($recipe->title),
                'detail' => 'Repos de '.\App\Support\Duration::format((int) $recipe->rest_minutes).' avant '.$meal->date->locale('fr')->isoFormat('dddd D MMMM'),
                'due_at' => $start->lt(now()) ? $this->dayBefore($meal) : $start,
            ];
        }

        // 3. Étapes qui parlent de la veille, de trempage ou de marinade.
        if (! $meal->isLeftover()) {
            foreach ($recipe->steps as $step) {
                $normalized = NameNormalizer::normalize($step->instruction);

                foreach (self::KEYWORDS as $word => $type) {
                    if (! str_contains($normalized, NameNormalizer::normalize($word))) {
                        continue;
                    }

                    $expected['step:'.$step->id] = [
                        'type' => $type->value,
                        'title' => $type->label().' — '.mb_strtolower($recipe->title),
                        'detail' => \Illuminate\Support\Str::limit($step->instruction, 160),
                        'due_at' => $this->dayBefore($meal),
                    ];

                    break;
                }
            }
        }

        return $withReception ? [...$expected, ...$this->receptionTasks($meal, $occasion, $frozen)] : $expected;
    }

    /**
     * Rétroplanning d'une réception (module 21) : ce qui se prépare longtemps à l'avance devient un rappel.
     *
     * @param  array<int, string>  $frozen
     */
    private function receptionTasks(PlannedMeal $meal, ?\App\Models\MealOccasion $occasion, array $frozen): array
    {
        return $occasion ? app(\App\Services\Receptions\ReceptionPlanner::class)->reminderTasks($meal, $occasion, $frozen) : [];
    }

    /** Convives et heure de la case du repas, s'ils ont été renseignés. */
    private function occasionOf(PlannedMeal $meal): ?\App\Models\MealOccasion
    {
        return \App\Models\MealOccasion::query()
            ->whereDate('date', $meal->date->toDateString())
            ->where('meal_slot_id', $meal->meal_slot_id)
            ->with('guests', 'slot')
            ->first();
    }

    /** Plat préparé en stock lié au repas (batch cooking) ou au repas dont ce sont les restes. */
    private function dishFor(PlannedMeal $meal): ?StockItem
    {
        $sourceId = $meal->isLeftover() ? $meal->leftover_of_id : ($meal->isPrepared() ? $meal->id : null);

        return $sourceId
            ? StockItem::query()->active()->where('planned_meal_id', $sourceId)->whereNull('ingredient_id')->with('location')->latest('id')->first()
            : null;
    }

    /* ================================================================ Lecture */

    /**
     * Rappels à faire, du plus urgent au plus lointain.
     *
     * @return Collection<int, Reminder>
     */
    public function due(?Carbon $until = null): Collection
    {
        return Reminder::query()
            ->due($until ?? now()->endOfDay())
            ->with('meal.recipe', 'meal.slot', 'meal.leftoverOf.recipe')
            ->orderBy('due_at')
            ->get()
            ->filter(fn (Reminder $r) => $r->meal !== null)
            ->values();
    }

    /** Rappels en attente d'une semaine, par date de repas (icône ⏰ du planning). */
    public function forWeek(Carbon $weekStart): Collection
    {
        return Reminder::query()->pending()
            ->whereHas('meal', fn ($q) => $q->between($weekStart, $weekStart->copy()->addDays(6)))
            ->with('meal')
            ->get()
            ->groupBy(fn (Reminder $r) => $r->meal->date->toDateString().'|'.$r->meal->meal_slot_id);
    }

    public function markDone(Reminder $reminder): void
    {
        $reminder->update(['status' => Reminder::DONE, 'handled_at' => now()]);
    }

    public function markIgnored(Reminder $reminder): void
    {
        $reminder->update(['status' => Reminder::IGNORED, 'handled_at' => now()]);
    }

    /* ================================================================ Outils */

    /** Ingrédients dont tout le stock utilisable est au congélateur. */
    public function frozenIngredients(): array
    {
        $items = StockItem::query()->active()->whereNotNull('ingredient_id')->with('location')->get();
        $byIngredient = [];

        foreach ($items as $item) {
            // Congelé : rangé au congélateur, ou explicitement marqué comme congelé.
            $byIngredient[$item->ingredient_id][] = $item->location?->type === \App\Enums\LocationType::Freezer || $item->isFrozen();
        }

        return collect($byIngredient)
            ->filter(fn (array $flags) => $flags !== [] && ! in_array(false, $flags, true))
            ->map(fn () => 'freezer')
            ->all();
    }

    /**
     * Moment du repas : l'heure d'une réception quand elle est saisie (et la place du plat dans le menu),
     * sinon l'heure de config (19 h par défaut).
     */
    private function mealMoment(PlannedMeal $meal, ?\App\Models\MealOccasion $occasion = null): Carbon
    {
        if ($occasion?->serve_time) {
            return $occasion->serveAt()->addMinutes(($meal->course ?? \App\Enums\Course::Main)->serveOffset());
        }

        return $meal->date->copy()->setTime((int) config('bouffe.planning.meal_hour', 19), 0);
    }

    /** La veille du repas, à l'heure des rappels. */
    private function dayBefore(PlannedMeal $meal): Carbon
    {
        return $meal->date->copy()->subDay()->setTime(Settings::int('planning.reminder_hour', 18), 0);
    }
}
