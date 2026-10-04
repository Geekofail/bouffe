<?php

namespace App\Livewire\Recipes;

use App\Livewire\Forms\RecipeForm;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\Unit;
use App\Services\Recipes\IngredientLineParser;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Création (/recettes/nouvelle) et modification (/recettes/{slug}/modifier) d'une recette.
 */
class Edit extends Component
{
    use WithFileUploads;

    public RecipeForm $form;

    #[Validate('nullable|image|mimes:jpg,jpeg,png,webp|max:10240', as: 'photo')]
    public $photo = null;

    /** Saisie rapide (13.3) : plusieurs lignes d'ingrédients collées d'un coup. */
    public string $quickLines = '';

    public bool $quickOpen = false;

    public function mount(?Recipe $recipe = null): void
    {
        // Recette d'un foyer relié (lot 26) : on la copie pour la modifier, jamais directement.
        abort_if($recipe?->exists && $recipe->isForeign(), 404);

        if ($recipe?->exists) {
            $this->form->setRecipe($recipe);
        } else {
            $this->form->initNew();
        }
    }

    /* ------------------------------------------------------------ Ingrédients */

    public function addIngredient(): void
    {
        $lastGroup = $this->form->useGroups ? (string) (end($this->form->ingredients)['group_name'] ?? '') : '';
        $this->form->ingredients[] = $this->form->newIngredientRow($lastGroup);
    }

    /**
     * Saisie rapide (13.3) : chaque ligne collée est analysée (règle R14) et devient une ligne du formulaire.
     */
    public function parseQuickLines(IngredientLineParser $parser): void
    {
        $added = 0;

        foreach (preg_split('/\r\n|\r|\n/u', $this->quickLines) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $parsed = $parser->parse($line);

            if ($parsed['name'] === '') {
                continue;
            }

            $this->form->ingredients[] = $this->form->rowFromParsedLine($parsed);
            $added++;
        }

        // Les lignes vides laissées par le formulaire passent après les nouvelles.
        $this->form->ingredients = array_values(array_filter(
            $this->form->ingredients,
            fn ($row) => trim($row['name']) !== '' || trim((string) $row['quantity']) !== ''
        ));
        $this->form->ensureEmptyRows();

        $this->reset('quickLines');
        $this->quickOpen = false;
        $this->dispatch('notify', message: $added === 0
            ? 'Aucune ligne reconnue.'
            : $added.' ingrédient'.($added > 1 ? 's ajoutés' : ' ajouté').'.');
    }

    public function removeIngredient(string $uid): void
    {
        $this->form->ingredients = array_values(array_filter($this->form->ingredients, fn ($row) => $row['uid'] !== $uid));
        $this->form->ensureEmptyRows();
    }

    public function sortIngredients(string $uid, int $position): void
    {
        $this->form->moveRow('ingredients', $uid, $position);
    }

    /**
     * Quand un ingrédient connu est saisi, on propose son unité par défaut ;
     * quand il est inconnu, on pré-sélectionne un rayon pour sa création.
     */
    public function updated(string $property, mixed $value): void
    {
        if (preg_match('/^form\.ingredients\.(\d+)\.name$/', $property, $m)) {
            $i = (int) $m[1];
            $ingredient = $this->form->findIngredient((string) $value);

            if ($ingredient) {
                $this->form->ingredients[$i]['new_aisle_id'] = null;

                if (empty($this->form->ingredients[$i]['unit_id']) && $ingredient->default_unit_id) {
                    $this->form->ingredients[$i]['unit_id'] = $ingredient->default_unit_id;
                }
            } elseif (trim((string) $value) !== '' && empty($this->form->ingredients[$i]['new_aisle_id'])) {
                $this->form->ingredients[$i]['new_aisle_id'] = RecipeForm::defaultAisleId();
            }
        }
    }

    /* ------------------------------------------------------------ Sous-recettes (13.8) */

    public function addComponent(): void
    {
        $this->form->components[] = $this->form->newComponentRow();
    }

    public function removeComponent(string $uid): void
    {
        $this->form->components = array_values(array_filter($this->form->components, fn ($row) => $row['uid'] !== $uid));
    }

    /** Recettes utilisables comme sous-recettes (toutes sauf celle-ci). */
    #[Computed]
    public function componentChoices(): Collection
    {
        return Recipe::query()->active()
            ->when($this->form->recipe, fn ($q) => $q->whereKeyNot($this->form->recipe->id))
            ->orderBy('title')->get(['id', 'title', 'servings']);
    }

    /* ------------------------------------------------------------ Étapes */

    public function addStep(): void
    {
        $this->form->steps[] = $this->form->newStepRow();
    }

    public function removeStep(string $uid): void
    {
        $this->form->steps = array_values(array_filter($this->form->steps, fn ($row) => $row['uid'] !== $uid));
        $this->form->ensureEmptyRows();
    }

    public function sortSteps(string $uid, int $position): void
    {
        $this->form->moveRow('steps', $uid, $position);
    }

    /* ------------------------------------------------------------ Catégories & photo */

    public function toggleTag(int $tagId): void
    {
        $this->form->tagIds = in_array($tagId, $this->form->tagIds, true)
            ? array_values(array_diff($this->form->tagIds, [$tagId]))
            : [...$this->form->tagIds, $tagId];
    }

    public function removePhoto(): void
    {
        $this->photo = null;
        $this->form->removePhoto = true;
    }

    /* ------------------------------------------------------------ Enregistrement */

    public function save(): void
    {
        $this->validateOnly('photo');

        $isNew = $this->form->recipe === null;
        // Historique (31.5) : le contenu d'avant, pour la version d'origine et le résumé des changements.
        $revisions = app(\App\Services\Recipes\RecipeRevisions::class);
        $before = $isNew ? null : $revisions->snapshot($this->form->recipe->fresh());
        $beforeAt = $isNew ? null : $this->form->recipe->updated_at?->copy();

        $recipe = $this->form->save($this->photo);
        $revisions->record($recipe, $before, beforeAt: $beforeAt);

        session()->flash('status', $isNew ? 'Recette créée.' : 'Recette enregistrée.');

        $this->redirectRoute('recipes.show', ['recipe' => $recipe], navigate: true);
    }

    /* ------------------------------------------------------------ Données de la vue */

    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->ordered()->get(['id', 'label']);
    }

    #[Computed]
    public function aisles(): Collection
    {
        return Aisle::query()->ordered()->get(['id', 'name']);
    }

    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()->ordered()->get();
    }

    /** Noms pour l'autocomplétion (datalist). */
    #[Computed]
    public function ingredientNames(): array
    {
        return Ingredient::query()->orderBy('name')->pluck('name')->all();
    }

    /** Index des lignes dont l'ingrédient n'existe pas encore (affichage du choix du rayon). */
    #[Computed]
    public function newIngredientRows(): array
    {
        $known = Ingredient::query()->pluck('search_name')->merge(\App\Models\IngredientAlias::query()->pluck('search_name'))->flip();

        return collect($this->form->ingredients)
            ->filter(fn ($row) => trim($row['name']) !== '' && ! $known->has(\App\Support\NameNormalizer::normalize($row['name'])))
            ->keys()
            ->all();
    }

    public function render()
    {
        $title = $this->form->recipe ? 'Modifier « '.$this->form->recipe->title.' »' : 'Nouvelle recette';

        return view('livewire.recipes.edit')->title($title);
    }
}
