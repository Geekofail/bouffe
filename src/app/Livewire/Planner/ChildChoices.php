<?php

namespace App\Livewire\Planner;

use App\Models\ChildChoice;
use App\Models\HouseholdPerson;
use App\Models\MealSlot;
use App\Models\Recipe;
use App\Services\People\CanteenCalendar;
use App\Services\People\ChildChoices as Choices;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Planning › Le choix des enfants (lot 39, 39.3, R42) : l'adulte prépare trois recettes,
 * l'enfant choisit sur l'écran de cuisine.
 */
#[Title('Le choix des enfants')]
class ChildChoices extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    public string $date = '';

    public int|string|null $slotId = null;

    public int|string|null $personId = '';

    /** @var list<int|string> */
    public array $recipeIds = ['', '', ''];

    public bool $allowPlan = false;

    public function mount(): void
    {
        $this->date = Carbon::tomorrow()->toDateString();
        $slots = MealSlot::query()->active()->ordered()->get();
        // Le dernier créneau qui n'est pas le midi : le dîner, d'habitude.
        $this->slotId = $slots->reverse()->first(fn (MealSlot $slot) => ! CanteenCalendar::isLunchName($slot->name) && ! str_contains(\App\Support\NameNormalizer::normalize($slot->name), 'gouter'))?->id ?? $slots->last()?->id;
        $this->personId = (string) ($this->children->first()?->id ?? '');
    }

    /** Trois idées compatibles avec tout le monde ce jour-là. */
    public function suggest(Choices $choices): void
    {
        if (! $this->slotId || $this->date === '') {
            return;
        }

        $current = array_filter(array_map('intval', $this->recipeIds));
        $ideas = $choices->ideas($this->date, (int) $this->slotId, array_values($current));

        if ($ideas === []) {
            $this->addError('recipeIds', 'Pas d\'idée qui convienne à tout le monde ce jour-là : choisissez vous-même.');

            return;
        }

        $this->resetErrorBag('recipeIds');
        $this->recipeIds = array_pad(array_map(fn (array $idea) => (string) $idea['recipe_id'], $ideas), 3, '');
    }

    public function propose(Choices $choices): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->validate([
            'date' => 'required|date',
            'slotId' => 'required|integer',
        ], [], ['date' => 'jour', 'slotId' => 'repas']);

        try {
            $choice = $choices->propose($this->date, (int) $this->slotId, $this->recipeIds, $this->personId ? (int) $this->personId : null, $this->allowPlan);
        } catch (InvalidArgumentException $e) {
            $this->addError('recipeIds', $e->getMessage());

            return;
        }

        $this->recipeIds = ['', '', ''];
        $this->resetErrorBag();
        unset($this->openChoices);
        $this->dispatch('notify', message: 'Choix prêt pour le '.$choice->dayLabel().' : à faire sur l\'écran de cuisine.');
    }

    public function cancel(int $choiceId, Choices $choices): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $choices->cancel(ChildChoice::query()->findOrFail($choiceId));
        unset($this->openChoices);
    }

    /** @return Collection<int, HouseholdPerson> qui peut choisir (sans compte d'abord : les enfants) */
    #[Computed]
    public function children(): Collection
    {
        return HouseholdPerson::query()->ordered()->get()->sortBy(fn (HouseholdPerson $p) => $p->user_id !== null)->values();
    }

    #[Computed]
    public function slots(): Collection
    {
        return MealSlot::query()->active()->ordered()->get();
    }

    #[Computed]
    public function recipes(): Collection
    {
        return Recipe::query()->active()->orderBy('title')->get(['id', 'title']);
    }

    #[Computed]
    public function openChoices(): Collection
    {
        return app(Choices::class)->open();
    }

    #[Computed]
    public function recentChoices(): Collection
    {
        return app(Choices::class)->recent();
    }

    /** Ce qui pose problème dans la sélection actuelle (affiché sous chaque liste). @return array<int, list<string>> */
    #[Computed]
    public function problems(): array
    {
        $problems = [];

        if (! $this->slotId || $this->date === '') {
            return [];
        }

        foreach ($this->recipeIds as $index => $id) {
            if ($id && ($recipe = Recipe::query()->find((int) $id))) {
                $problems[$index] = app(Choices::class)->problems($recipe, $this->date, (int) $this->slotId);
            }
        }

        return $problems;
    }

    public function render()
    {
        return view('livewire.planner.child-choices');
    }
}
