<?php

namespace App\Livewire\Planner;

use App\Models\PlannedMeal;
use App\Services\Planning\BatchCooking;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * « Cuisiner en avance » (14.8) : une session de batch cooking.
 *
 * Étape 1 : cocher les repas à avancer. Étape 2 : ingrédients regroupés, recettes dans l'ordre
 * où les lancer, et pour chaque plat terminé « Ranger » au réfrigérateur ou au congélateur.
 */
#[Title('Cuisiner en avance')]
class BatchCook extends Component
{
    /** Repas choisis (dans l'adresse, pour pouvoir reprendre la session). */
    #[Url(as: 'repas', except: '')]
    public string $mealIds = '';

    /** @var list<int> cases cochées à l'étape 1 */
    public array $selected = [];

    /** Retirer les ingrédients du stock en rangeant chaque plat. */
    public bool $deduct = true;

    /** @var array<int, string> fridge · freezer, par repas */
    public array $storage = [];

    /** @var array<int, int|string> portions rangées, par repas */
    public array $portions = [];

    public function mount(): void
    {
        $this->selected = $this->ids();
    }

    /** @return list<int> */
    private function ids(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $this->mealIds))));
    }

    public function start(): void
    {
        $ids = array_values(array_intersect(array_map('intval', $this->selected), $this->candidates->pluck('id')->all()));

        if ($ids === []) {
            $this->addError('selected', 'Cochez au moins un repas.');

            return;
        }

        $this->mealIds = implode(',', $ids);
        unset($this->meals, $this->session);
    }

    public function back(): void
    {
        $this->selected = $this->ids();
        $this->mealIds = '';
        unset($this->meals, $this->session);
    }

    public function prepare(int $mealId, BatchCooking $batch): void
    {
        $meal = PlannedMeal::with('recipe')->find($mealId);

        if (! $meal || ! in_array($mealId, $this->ids(), true)) {
            return;
        }

        try {
            $item = $batch->prepare($meal, $this->storage[$mealId] ?? $batch->storageFor($meal)['storage'], $this->deduct, \App\Services\Planning\Appetites::clamp($this->portions[$mealId] ?? $meal->servings));
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        unset($this->meals, $this->session);
        $this->dispatch('stock-changed');
        $this->dispatch('notify', message: '« '.$meal->recipe->title.' » rangé : '.$item->location->name.'.');
    }

    public function unprepare(int $mealId, BatchCooking $batch): void
    {
        $meal = PlannedMeal::find($mealId);

        if ($meal && in_array($mealId, $this->ids(), true)) {
            $batch->unprepare($meal);
            unset($this->meals, $this->session);
            $this->dispatch('stock-changed');
        }
    }

    /* ================================================================ Données */

    /** @return Collection<int, PlannedMeal> */
    #[Computed]
    public function candidates(): Collection
    {
        return app(BatchCooking::class)->candidates();
    }

    /** Repas de la session (y compris ceux déjà rangés). @return Collection<int, PlannedMeal> */
    #[Computed]
    public function meals(): Collection
    {
        return PlannedMeal::query()->whereIn('id', $this->ids())->whereNull('cooked_at')->with('recipe.steps', 'slot', 'preparedDish.location')->get();
    }

    #[Computed]
    public function session(): array
    {
        $batch = app(BatchCooking::class);
        $session = $batch->session($this->meals->reject(fn (PlannedMeal $m) => $m->isPrepared()));

        foreach ($session['recipes'] as $row) {
            $id = $row['meal']->id;
            $this->storage[$id] ??= $row['storage'];
            $this->portions[$id] ??= $row['meal']->servings;
        }

        return [
            ...$session,
            'texts' => $batch->ingredientTexts($session['ingredients']),
            'done' => $this->meals->filter(fn (PlannedMeal $m) => $m->isPrepared())->values(),
        ];
    }

    public function render()
    {
        return view('livewire.planner.batch-cook', ['inSession' => $this->ids() !== []]);
    }
}
