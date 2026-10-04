<?php

namespace App\Livewire\Recipes;

use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Services\RecipeDuplicator;
use App\Services\RecipePhotoService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Show extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;
    use Concerns\ComparesWithOrigin;
    use Concerns\ProvidesRecipeData;
    use Concerns\RatesAndPlansRecipe;

    #[Locked]
    public Recipe $recipe;

    /** Nombre de portions affiché (ajusteur), par demi-portion (lot 32). */
    public float $servings = 2;

    public int $myRating = 0;

    public string $myComment = '';

    /* Ajout au planning */
    public bool $showPlan = false;

    public string $planDate = '';

    public ?int $planSlotId = null;

    public int|float|string $planServings = 2;

    /** Repas du planning d'où l'on vient (?repas=) : portions du repas et contraintes des invités. */
    #[Locked]
    public ?int $mealId = null;

    public function mount(Recipe $recipe): void
    {
        $this->recipe = $recipe;
        $this->servings = $recipe->servings;

        $meal = request()->integer('repas') ? PlannedMeal::find(request()->integer('repas')) : null;

        if ((float) str_replace(',', '.', (string) request('portions')) > 0) {
            $this->servings = \App\Services\Planning\Appetites::clamp(request('portions'));
        }

        if ($meal && $meal->eatenRecipe()?->is($recipe)) {
            $this->mealId = $meal->id;
            $this->servings = $meal->isRecipe() ? $meal->servings : $recipe->servings;
        }

        $mine = $recipe->ratings()->where('user_id', auth()->id())->first();
        $this->myRating = (int) $mine?->rating;
        $this->myComment = (string) $mine?->comment;
    }

    /* ------------------------------------------------------------ Portions */

    public function increment(): void
    {
        $this->servings = min(50.0, $this->servings + 1);
    }

    public function decrement(): void
    {
        $this->servings = max(0.5, $this->servings - 1);
    }

    /** Lot 32 (32.2) : les portions du foyer, d'après l'appétit de chacun. */
    public function useHouseholdServings(): void
    {
        $this->servings = $this->householdServings();
    }

    public function householdServings(): float
    {
        return max(0.5, app(\App\Services\Planning\OccasionService::class)->diners(null));
    }

    public function resetServings(): void
    {
        $this->servings = $this->recipe->servings;
    }

    /* ------------------------------------------------------------ Notes */

    /* ------------------------------------------------------------ Actions */

    public function duplicate(RecipeDuplicator $duplicator): void
    {
        abort_if($this->recipe->isForeign(), 403);
        $copy = $duplicator->duplicate($this->recipe, auth()->id());

        session()->flash('status', 'Copie créée : vous pouvez l\'adapter.');
        $this->redirectRoute('recipes.edit', ['recipe' => $copy], navigate: true);
    }

    public function toggleArchive(): void
    {
        if (! $this->allowedToEdit() || $this->recipe->isForeign()) {
            return;
        }

        $archived = ! $this->recipe->isArchived();
        $this->recipe->update(['archived_at' => $archived ? now() : null]);

        $this->dispatch('notify', message: $archived
            ? 'Recette archivée : elle n\'apparaît plus dans le carnet ni dans les suggestions.'
            : 'Recette sortie des archives.');
    }

    public function delete(RecipePhotoService $photos): void
    {
        abort_if($this->recipe->isForeign() || ! auth()->user()->canEdit(), 403);

        if ($this->recipe->plannedMeals()->exists()) {
            $this->dispatch('notify', type: 'warning', message: 'Cette recette figure dans le planning : archivez-la plutôt que de la supprimer.');

            return;
        }

        // Sous-recette utilisée ailleurs (13.8) : la supprimer casserait les autres recettes.
        $parents = app(\App\Services\Recipes\SubRecipes::class)->parents($this->recipe);

        if ($parents->isNotEmpty()) {
            $this->dispatch('notify', type: 'warning', message: 'Cette recette est utilisée par « '.$parents->pluck('title')->join(' », « ').' » : archivez-la plutôt que de la supprimer.');

            return;
        }

        $title = $this->recipe->title;
        // Planifiée telle quelle par un foyer relié (26.2) : son repas garde le nom, sans la recette.
        app(\App\Services\Linked\RecipeRemoval::class)->detachFromOtherHouseholds([$this->recipe->id]);
        $photos->delete($this->recipe->photo_path);
        app(\App\Services\Recipes\RecipePhotos::class)->deleteFilesOf($this->recipe);   // photos d'étapes et « notre version » (31.2)
        $this->recipe->delete();

        session()->flash('status', "Recette « {$title} » supprimée.");
        $this->redirectRoute('recipes.index', navigate: true);
    }

    /* ------------------------------------------------------------ Lot 31 */

    /* ------------------------------------------------------------ Données */

    /** Variante affichée (13.7) : null = la recette telle quelle. */
    #[Url(as: 'variante', except: null)]
    public ?int $variantId = null;

    /* ------------------------------------------------------------ Entre foyers (lot 26) */

    public bool $showOriginDiff = false;

    public function render()
    {
        $this->recipe->loadMissing(['tags', 'steps', 'author', 'household']);

        return view('livewire.recipes.show', [
            'foreign' => $this->recipe->isForeign(),
            'prices' => app(\App\Services\Pricing\PriceBook::class),
        ])->title($this->recipe->title);
    }
}
