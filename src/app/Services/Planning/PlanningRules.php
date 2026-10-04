<?php

namespace App\Services\Planning;

use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\Tag;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Règles de la semaine (14.4) : ce qu'on souhaite voir — ou ne pas voir — dans une semaine.
 *
 * Elles servent au remplissage automatique (R15) et sont signalées sur le planning.
 * Ce sont des préférences, pas des interdits : on peut toujours planifier ce qu'on veut à la main.
 *
 * Enregistrées dans `settings` sous la clé `planning.rules` :
 *   [
 *     'slots'  => [slotId => ['max_minutes' => 30, 'weeknights_only' => true]],
 *     'quotas' => [['tag_id' => 3, 'min' => null, 'max' => 1], …],
 *     'avoid_repeat_category' => true,
 *   ]
 */
class PlanningRules
{
    public const KEY = 'planning.rules';

    /** Lundi à jeudi : les soirs « de semaine ». */
    public const WEEKNIGHTS = [1, 2, 3, 4];

    /** @return array{slots: array<int, array{max_minutes: int|null, weeknights_only: bool}>, quotas: list<array{tag_id: int, min: int|null, max: int|null}>, avoid_repeat_category: bool} */
    public function all(): array
    {
        $stored = (array) Settings::get(self::KEY, []);

        return [
            'slots' => collect($stored['slots'] ?? [])
                ->mapWithKeys(fn ($rule, $slotId) => [(int) $slotId => [
                    'max_minutes' => ($rule['max_minutes'] ?? null) ? (int) $rule['max_minutes'] : null,
                    'weeknights_only' => (bool) ($rule['weeknights_only'] ?? true),
                ]])
                ->all(),
            'quotas' => collect($stored['quotas'] ?? [])
                ->filter(fn ($q) => ! empty($q['tag_id']))
                ->map(fn ($q) => [
                    'tag_id' => (int) $q['tag_id'],
                    'min' => ($q['min'] ?? null) !== null && $q['min'] !== '' ? max(0, (int) $q['min']) : null,
                    'max' => ($q['max'] ?? null) !== null && $q['max'] !== '' ? max(0, (int) $q['max']) : null,
                ])
                ->values()->all(),
            'avoid_repeat_category' => (bool) ($stored['avoid_repeat_category'] ?? true),
        ];
    }

    public function save(array $rules): void
    {
        Settings::set(self::KEY, [
            'slots' => collect($rules['slots'] ?? [])
                ->mapWithKeys(fn ($rule, $slotId) => [(int) $slotId => [
                    'max_minutes' => ($rule['max_minutes'] ?? null) !== null && $rule['max_minutes'] !== ''
                        ? max(5, min(600, (int) $rule['max_minutes']))
                        : null,
                    'weeknights_only' => (bool) ($rule['weeknights_only'] ?? true),
                ]])
                ->filter(fn ($rule) => $rule['max_minutes'] !== null)
                ->all(),
            'quotas' => collect($rules['quotas'] ?? [])
                ->filter(fn ($q) => ! empty($q['tag_id']) && (($q['min'] ?? null) !== null && $q['min'] !== '' || ($q['max'] ?? null) !== null && $q['max'] !== ''))
                ->map(fn ($q) => [
                    'tag_id' => (int) $q['tag_id'],
                    'min' => ($q['min'] ?? null) !== null && $q['min'] !== '' ? max(0, min(21, (int) $q['min'])) : null,
                    'max' => ($q['max'] ?? null) !== null && $q['max'] !== '' ? max(0, min(21, (int) $q['max'])) : null,
                ])
                ->values()->all(),
            'avoid_repeat_category' => (bool) ($rules['avoid_repeat_category'] ?? true),
        ]);
    }

    public function isEmpty(): bool
    {
        $rules = $this->all();

        return $rules['slots'] === [] && $rules['quotas'] === [];
    }

    /* ================================================================ Temps */

    /** Temps total maximum souhaité pour cette case, ou null si aucune limite. */
    public function maxMinutesFor(Carbon|string $date, MealSlot|int $slot): ?int
    {
        $slotId = $slot instanceof MealSlot ? $slot->id : (int) $slot;
        $rule = $this->all()['slots'][$slotId] ?? null;

        if (! $rule || $rule['max_minutes'] === null) {
            return null;
        }

        $weekday = Carbon::parse($date)->dayOfWeekIso;

        return $rule['weeknights_only'] && ! in_array($weekday, self::WEEKNIGHTS, true) ? null : $rule['max_minutes'];
    }

    /** La recette dépasse-t-elle le temps souhaité pour cette case ? */
    public function exceedsTime(Recipe $recipe, Carbon|string $date, MealSlot|int $slot): bool
    {
        $max = $this->maxMinutesFor($date, $slot);

        return $max !== null && (int) $recipe->total_minutes > $max;
    }

    /* ================================================================ Quotas de catégories */

    /**
     * Bilan des quotas sur une semaine : ce qui est atteint, dépassé ou encore attendu.
     *
     * @param  Collection<int, PlannedMeal>  $meals
     * @return list<array{tag: Tag, count: int, min: int|null, max: int|null, state: string, message: string}>
     */
    public function weekStatus(Collection $meals): array
    {
        $quotas = $this->all()['quotas'];

        if ($quotas === []) {
            return [];
        }

        $tags = Tag::query()->whereIn('id', array_column($quotas, 'tag_id'))->get()->keyBy('id');
        $counts = $this->countByTag($meals);
        $status = [];

        foreach ($quotas as $quota) {
            $tag = $tags->get($quota['tag_id']);

            if (! $tag) {
                continue;
            }

            $count = $counts[$quota['tag_id']] ?? 0;
            [$state, $message] = match (true) {
                $quota['max'] !== null && $count > $quota['max'] => ['over', "{$tag->name} : {$count} fois (maximum {$quota['max']})"],
                $quota['min'] !== null && $count < $quota['min'] => ['under', "{$tag->name} : {$count} sur {$quota['min']} souhaités"],
                default => ['ok', "{$tag->name} : {$count}"],
            };

            $status[] = ['tag' => $tag, 'count' => $count, 'min' => $quota['min'], 'max' => $quota['max'], 'state' => $state, 'message' => $message];
        }

        return $status;
    }

    /**
     * Ajouter cette recette ferait-il dépasser un maximum ? (pénalité du remplissage, R15)
     *
     * @param  array<int, int>  $counts  catégorie => nombre déjà prévu dans la semaine
     */
    public function wouldExceedQuota(Recipe $recipe, array $counts): bool
    {
        foreach ($this->all()['quotas'] as $quota) {
            if ($quota['max'] === null) {
                continue;
            }

            if ($recipe->tags->contains('id', $quota['tag_id']) && ($counts[$quota['tag_id']] ?? 0) >= $quota['max']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Catégories encore attendues dans la semaine (minimums non atteints).
     *
     * @param  array<int, int>  $counts
     * @return list<int> identifiants de catégories
     */
    public function missingTagIds(array $counts): array
    {
        return collect($this->all()['quotas'])
            ->filter(fn ($q) => $q['min'] !== null && ($counts[$q['tag_id']] ?? 0) < $q['min'])
            ->pluck('tag_id')->map('intval')->values()->all();
    }

    /**
     * Nombre de repas par catégorie dans une semaine (restes compris : c'est le même plat).
     *
     * @param  Collection<int, PlannedMeal>  $meals
     * @return array<int, int>
     */
    public function countByTag(Collection $meals): array
    {
        $counts = [];

        foreach ($meals as $meal) {
            foreach ($meal->eatenRecipe()?->tags ?? [] as $tag) {
                $counts[$tag->id] = ($counts[$tag->id] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
