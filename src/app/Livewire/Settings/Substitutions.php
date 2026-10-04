<?php

namespace App\Livewire\Settings;

use App\Models\Ingredient;
use App\Models\IngredientSubstitution;
use App\Models\Recipe;
use App\Services\Recipes\Substitutions as SubstitutionService;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Paramètres → Remplacements (lot 40, 40.1, R43) : « crème liquide → crème de soja ».
 *
 * La liste commune (Q58) se masque, celle du foyer se complète ; un remplacement peut ne valoir
 * que pour une recette. Rien ne modifie les recettes : ils sont proposés en mode cuisine, dans
 * « Que cuisiner ? » et sur la liste de courses.
 */
#[Title('Remplacements')]
class Substitutions extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public int|string|null $ingredientId = null;

    public int|string|null $substituteId = null;

    public string $ratio = '1';

    public int|string|null $recipeId = null;

    public string $note = '';

    public function add(SubstitutionService $substitutions): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->validate([
            'ingredientId' => 'required|integer',
            'substituteId' => 'required|integer',
            'ratio' => 'required|string|max:10',
            'note' => 'nullable|string|max:150',
        ], [], ['ingredientId' => 'ingrédient', 'substituteId' => 'remplacement', 'ratio' => 'rapport', 'note' => 'note']);

        try {
            $substitution = $substitutions->add((int) $this->ingredientId, (int) $this->substituteId, $this->ratio, $this->recipeId ? (int) $this->recipeId : null, $this->note);
        } catch (InvalidArgumentException $e) {
            $this->addError('substituteId', $e->getMessage());

            return;
        }

        $this->reset('ingredientId', 'substituteId', 'ratio', 'recipeId', 'note');
        unset($this->rows);
        $this->dispatch('notify', message: 'Remplacement ajouté : '.$substitution->load('ingredient', 'substitute')->ingredient->name.' → '.mb_strtolower($substitution->substitute->name).'.');
    }

    public function remove(int $id, SubstitutionService $substitutions): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $substitution = IngredientSubstitution::query()->findOrFail($id);
        $substitutions->remove($substitution);
        unset($this->rows, $this->hidden);
        $this->dispatch('notify', message: $substitution->isCommon() ? 'Remplacement masqué pour le foyer.' : 'Remplacement retiré.');
    }

    public function restore(int $id, SubstitutionService $substitutions): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $substitutions->restoreCommon($id);
        unset($this->rows, $this->hidden);
    }

    /** @return Collection<int, IngredientSubstitution> */
    #[Computed]
    public function rows(): Collection
    {
        $term = mb_strtolower(trim($this->search));

        return app(SubstitutionService::class)->visible()
            ->filter(fn (IngredientSubstitution $s) => $term === ''
                || str_contains(mb_strtolower($s->ingredient->name), $term)
                || str_contains(mb_strtolower($s->substitute->name), $term))
            ->sortBy(fn (IngredientSubstitution $s) => [$s->ingredient->search_name, $s->recipe_id === null ? 0 : 1, $s->substitute->search_name])
            ->values();
    }

    /** @return Collection<int, IngredientSubstitution> */
    #[Computed]
    public function hidden(): Collection
    {
        return app(SubstitutionService::class)->hiddenCommons();
    }

    #[Computed]
    public function ingredients(): Collection
    {
        return Ingredient::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function recipes(): Collection
    {
        return Recipe::query()->active()->orderBy('title')->get(['id', 'title']);
    }

    public function render()
    {
        return view('livewire.settings.substitutions');
    }
}
