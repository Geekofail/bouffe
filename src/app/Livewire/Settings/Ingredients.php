<?php

namespace App\Livewire\Settings;

use App\Livewire\Forms\IngredientForm;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\Unit;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Ingrédients')]
class Ingredients extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'rayon', except: null)]
    public ?int $aisleFilter = null;

    #[Url(as: 'placard', except: false)]
    public bool $staplesOnly = false;

    public bool $showForm = false;

    public string $newAlias = '';

    public IngredientForm $form;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'aisleFilter', 'staplesOnly'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->form->reset();
        $this->form->resetErrorBag();
        $this->form->aisle_id = Aisle::query()->ordered()->value('id');
        $this->form->name = trim($this->search);
        $this->form->applyStockDefaults();
        $this->showForm = true;
    }

    public function edit(Ingredient $ingredient): void
    {
        $this->form->resetErrorBag();
        $this->form->setIngredient($ingredient);
        $this->showForm = true;
    }

    public function save(): void
    {
        $isNew = $this->form->ingredient === null;
        $ingredient = $this->form->save();

        $this->closeForm();
        $this->dispatch('notify', message: $isNew
            ? "« {$ingredient->name} » a été ajouté."
            : "« {$ingredient->name} » a été modifié.");
    }

    /** Autre nom de l'ingrédient en cours de modification (R22). */
    public function addAlias(): void
    {
        $ingredient = $this->form->ingredient;
        $name = trim(preg_replace('/\s+/u', ' ', $this->newAlias));
        $normalized = \App\Support\NameNormalizer::normalize($name);

        if (! $ingredient || $normalized === '') {
            return;
        }

        if (($existing = Ingredient::findByName($name)) !== null) {
            $this->addError('newAlias', $existing->is($ingredient) ? 'Ce nom désigne déjà cet ingrédient.' : "« {$name} » désigne déjà « {$existing->name} ».");

            return;
        }

        $ingredient->aliases()->create(['name' => mb_substr($name, 0, 150)]);
        $this->reset('newAlias');
        $this->resetErrorBag('newAlias');
    }

    public function removeAlias(int $aliasId): void
    {
        $this->form->ingredient?->aliases()->whereKey($aliasId)->delete();
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->form->reset();
        $this->form->resetErrorBag();
    }

    public function toggleStaple(Ingredient $ingredient): void
    {
        $ingredient->update(['is_staple' => ! $ingredient->is_staple]);
    }

    /** Nouvel ingrédient : réglages de stock proposés quand le rayon change. */
    public function updatedFormAisleId(): void
    {
        if ($this->form->ingredient === null) {
            $this->form->applyStockDefaults();
        }
    }

    #[Computed]
    public function storageLocations(): Collection
    {
        return \App\Models\StorageLocation::query()->ordered()->get(['id', 'name']);
    }

    public function delete(Ingredient $ingredient): void
    {
        if (Ingredient::catalogLocked() || $ingredient->usedByOtherHouseholds()) {
            $this->dispatch('notify', type: 'warning', message: "«\u{00A0}{$ingredient->name}\u{00A0}» fait partie du catalogue commun à tous les foyers : seul l'administrateur peut le supprimer, et seulement s'il ne sert nulle part.");

            return;
        }

        if ($ingredient->stockItems()->exists()) {
            $this->dispatch('notify', type: 'warning', message: "Impossible de supprimer «\u{00A0}{$ingredient->name}\u{00A0}» : il figure dans le stock ou son historique.");

            return;
        }

        $guests = \App\Models\GuestRestriction::query()->where('ingredient_id', $ingredient->id)->whereHas('guest')->with('guest')->get()->pluck('guest.name')->unique();

        if ($guests->isNotEmpty()) {
            $this->dispatch('notify', type: 'warning', message: "Impossible de supprimer «\u{00A0}{$ingredient->name}\u{00A0}» : contrainte alimentaire de ".$guests->join(', ', ' et ').'.');

            return;
        }

        if (($count = $ingredient->recipeCount()) > 0) {
            $this->dispatch('notify', type: 'warning', message: "Impossible de supprimer «\u{00A0}{$ingredient->name}\u{00A0}» : utilisé dans {$count} recette".($count > 1 ? 's' : '').'.');

            return;
        }

        $name = $ingredient->name;
        $ingredient->delete();

        $this->dispatch('notify', message: "« {$name} » a été supprimé.");
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'aisleFilter', 'staplesOnly');
        $this->resetPage();
    }

    /** Ingrédients existants proches du nom en cours de saisie (doublons probables). */
    #[Computed]
    public function similar(): Collection
    {
        if (! $this->showForm || trim($this->form->name) === '') {
            return collect();
        }

        return Ingredient::similarTo($this->form->name, $this->form->ingredient?->id)->take(5);
    }

    #[Computed]
    public function aisles(): Collection
    {
        return Aisle::query()->ordered()->get();
    }

    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->ordered()->get();
    }

    public function render()
    {
        $ingredients = Ingredient::query()
            ->with(['aisle', 'defaultUnit'])
            ->withCount(['recipeLines' => fn ($q) => $q->whereHas('recipe')])   // recettes de ce foyer seulement
            ->search($this->search)
            ->when($this->aisleFilter, fn ($query) => $query->whereSetting('aisle_id', '=', $this->aisleFilter))
            ->when($this->staplesOnly, fn ($query) => $query->whereSetting('is_staple', '=', true))
            ->ordered()
            ->paginate(25);

        return view('livewire.settings.ingredients', [
            'ingredients' => $ingredients,
            'total' => Ingredient::count(),
        ]);
    }
}
