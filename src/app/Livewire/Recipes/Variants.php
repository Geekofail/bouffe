<?php

namespace App\Livewire\Recipes;

use App\Enums\RestrictionType;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeVariant;
use App\Models\Tag;
use App\Models\Unit;
use App\Services\Recipes\VariantService;
use App\Support\Concerns\RequiresFullAccess;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Variantes d'une recette (13.7).
 *
 * Une variante décrit **seulement ce qui change** : ligne par ligne, on remplace un ingrédient
 * par un autre, ou on le retire. Les étapes, les temps et la photo restent ceux de la recette,
 * ce qui évite d'entretenir deux fiches presque identiques.
 */
class Variants extends Component
{
    use RequiresFullAccess;

    #[Locked]
    public int $recipeId;

    #[Url(as: 'variante', except: null)]
    public ?int $variantId = null;

    /* ---------------------------------------------------------- Nouvelle variante */

    public string $newName = '';

    public string $newNote = '';

    public string $solvesType = '';

    public ?int $solvesIngredientId = null;

    public ?int $solvesTagId = null;

    public function mount(Recipe $recipe): void
    {
        abort_if($recipe->isForeign(), 404);   // lot 26 : pas de variante sur la recette d'un autre foyer
        $this->recipeId = $recipe->id;
        $this->variantId ??= $recipe->variants()->value('id');
    }

    #[Computed]
    public function recipe(): Recipe
    {
        return Recipe::with(['ingredients.ingredient', 'ingredients.unit'])->findOrFail($this->recipeId);
    }

    /** @return Collection<int, RecipeVariant> */
    #[Computed]
    public function variants(): Collection
    {
        return RecipeVariant::query()
            ->where('recipe_id', $this->recipeId)
            ->with(['swaps.ingredient', 'swaps.unit', 'solvesIngredient', 'solvesTag'])
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    #[Computed]
    public function variant(): ?RecipeVariant
    {
        return $this->variantId ? $this->variants->firstWhere('id', $this->variantId) : null;
    }

    public function select(?int $variantId): void
    {
        $this->variantId = $variantId;
        unset($this->variant);
    }

    public function add(): void
    {
        $this->newName = trim($this->newName);

        $this->validate([
            'newName' => ['required', 'string', 'max:100'],
            'newNote' => ['nullable', 'string', 'max:300'],
            'solvesType' => ['nullable', Rule::in(array_column(RestrictionType::cases(), 'value'))],
            'solvesIngredientId' => ['nullable', 'integer', 'exists:ingredients,id'],
            'solvesTagId' => ['nullable', 'integer', 'exists:tags,id'],
        ], [], ['newName' => 'nom de la variante', 'newNote' => 'précision']);

        $variant = RecipeVariant::create([
            'recipe_id' => $this->recipeId,
            'name' => $this->newName,
            'note' => trim($this->newNote) ?: null,
            'solves_type' => $this->solvesType ?: null,
            'solves_ingredient_id' => $this->solvesType === RestrictionType::Diet->value ? null : $this->solvesIngredientId,
            'solves_tag_id' => $this->solvesType === RestrictionType::Diet->value ? $this->solvesTagId : null,
            'sort_order' => $this->variants->count() + 1,
        ]);

        $this->reset('newName', 'newNote', 'solvesType', 'solvesIngredientId', 'solvesTagId');
        unset($this->variants);
        $this->select($variant->id);
        $this->dispatch('notify', message: "Variante « {$variant->name} » créée. Indiquez maintenant ce qui change.");
    }

    public function delete(int $variantId): void
    {
        RecipeVariant::whereKey($variantId)->where('recipe_id', $this->recipeId)->delete();

        unset($this->variants);
        $this->select($this->variants->first()?->id);
    }

    /* ---------------------------------------------------------- Remplacements */

    /** Remplace une ligne par un autre ingrédient (id vide = pas de changement, « none » = retirée). */
    public function swap(int $lineId, string $value, VariantService $service): void
    {
        $variant = $this->variant;

        if (! $variant) {
            return;
        }

        if ($value === '') {
            $service->removeSwap($variant, $lineId);
        } elseif ($value === 'none') {
            $service->setSwap($variant, $lineId, null, null, null);
        } else {
            $line = $this->recipe->ingredients->firstWhere('id', $lineId);
            $service->setSwap($variant, $lineId, (int) $value, $line?->quantity !== null ? (float) $line->quantity : null, $line?->unit_id);
        }

        unset($this->variants, $this->variant);
    }

    /** Ajuste la quantité d'un remplacement (l'équivalent n'a pas toujours le même poids). */
    public function setQuantity(int $lineId, string $quantity, ?int $unitId = null): void
    {
        $variant = $this->variant;
        $swap = $variant?->swaps->firstWhere('recipe_ingredient_id', $lineId);

        if (! $swap) {
            return;
        }

        $parsed = trim($quantity) === '' ? null : app(\App\Services\QuantityParser::class)->tryParse($quantity);

        if ($parsed === false) {
            $this->addError('quantity-'.$lineId, 'Quantité invalide.');

            return;
        }

        $swap->update(['quantity' => $parsed, 'unit_id' => $unitId ?? $swap->unit_id]);

        unset($this->variants, $this->variant);
    }

    /* ---------------------------------------------------------- Données de la vue */

    /** @return Collection<int, Ingredient> */
    #[Computed]
    public function ingredients(): Collection
    {
        return Ingredient::query()->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, Unit> */
    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->ordered()->get(['id', 'label']);
    }

    /** @return Collection<int, Tag> */
    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()->ordered()->get(['id', 'name']);
    }

    public function render(VariantService $service)
    {
        return view('livewire.recipes.variants', [
            'lines' => $service->lines($this->recipe, $this->variant),
            'restrictionTypes' => RestrictionType::cases(),
        ])->title('Variantes · '.$this->recipe->title);
    }
}
