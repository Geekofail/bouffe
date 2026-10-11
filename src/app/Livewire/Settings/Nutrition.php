<?php

namespace App\Livewire\Settings;

use App\Models\Ingredient;
use App\Models\NutritionFood;
use App\Services\Nutrition\CiqualImporter;
use App\Services\Nutrition\RecipeNutrition;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Paramètres → Nutrition (17.4, lot 43).
 *
 * La table Ciqual 2025 est livrée avec Bouffe, et chaque ingrédient du catalogue a son aliment.
 * Cet écran sert à vérifier et corriger : à quel aliment correspond chaque ingrédient, d'où vient
 * ce choix (catalogue, automatique, à la main), et la source des données (attribution Anses).
 * Un choix fait ici n'est plus jamais remplacé automatiquement.
 */
#[Title('Nutrition')]
class Nutrition extends Component
{
    use \App\Support\Concerns\GuardsCatalog;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** tous | associes | manquants */
    #[Url(as: 'filtre', except: 'manquants')]
    public string $filter = 'manquants';

    /** Ingrédient en cours de rattachement. */
    public ?int $editingId = null;

    public string $foodSearch = '';

    public string $density = '';

    public function edit(int $ingredientId): void
    {
        $ingredient = Ingredient::findOrFail($ingredientId);

        $this->editingId = $ingredient->id;
        $this->foodSearch = $ingredient->name;
        $this->density = $ingredient->density !== null
            ? rtrim(rtrim(str_replace('.', ',', (string) $ingredient->density), '0'), ',')
            : '';
        $this->resetErrorBag();
    }

    public function closeEdit(): void
    {
        $this->reset('editingId', 'foodSearch', 'density');
    }

    public function attach(string $code): void
    {
        if (! $this->catalogEditable()) {
            return;
        }

        $ingredient = Ingredient::findOrFail($this->editingId);
        // Choix fait à la main : ni le catalogue ni la correspondance automatique n'y toucheront.
        $ingredient->forceFill(['ciqual_code' => $code, 'ciqual_source' => 'manuel'])->save();
        RecipeNutrition::markStaleForIngredients([$ingredient->id]);

        $this->closeEdit();
        unset($this->ingredients);
        $this->dispatch('notify', message: "« {$ingredient->name} » rattaché à un aliment de la table.");
    }

    public function detach(int $ingredientId): void
    {
        if (! $this->catalogEditable()) {
            return;
        }

        // « Aucun aliment » est aussi un choix : il n'est plus proposé automatiquement.
        Ingredient::whereKey($ingredientId)->update(['ciqual_code' => null, 'ciqual_source' => 'manuel']);
        RecipeNutrition::markStaleForIngredients([$ingredientId]);

        unset($this->ingredients);
    }

    public function saveDensity(): void
    {
        $value = str_replace(',', '.', trim($this->density));

        $this->validate(['density' => ['nullable', 'regex:/^\d{0,2}([.,]\d{1,3})?$/']], [
            'density.regex' => 'Densité invalide (ex. 0,9).',
        ], ['density' => 'densité']);

        Ingredient::whereKey($this->editingId)->update(['density' => $value === '' ? null : (float) $value]);
        RecipeNutrition::markStaleForIngredients([$this->editingId]);

        unset($this->ingredients);
        $this->dispatch('notify', message: 'Densité enregistrée : les volumes seront convertis en grammes avec cette valeur.');
    }

    /** Relance la correspondance automatique pour les ingrédients encore sans aliment. */
    public function matchAll(CiqualImporter $importer): void
    {
        $result = $importer->matchIngredients(false);

        if ($result['matched'] > 0) {
            \Illuminate\Support\Facades\DB::table('recipes')->update(['nutrition_at' => null]);
        }

        unset($this->ingredients);
        $this->dispatch('notify', message: "{$result['matched']} ingrédient(s) rattachés automatiquement.");
    }

    /** @return Collection<int, Ingredient> */
    #[Computed]
    public function ingredients(): Collection
    {
        return Ingredient::query()
            ->when(trim($this->search) !== '', fn ($query) => $query->search(trim($this->search)))
            ->when($this->filter === 'associes', fn ($query) => $query->whereNotNull('ciqual_code'))
            ->when($this->filter === 'manquants', fn ($query) => $query->whereNull('ciqual_code'))
            ->orderBy('name')
            ->limit(120)
            ->get();
    }

    /** Aliments Ciqual des ingrédients affichés, indexés par code. */
    #[Computed]
    public function foods(): Collection
    {
        return NutritionFood::query()
            ->whereIn('ciqual_code', $this->ingredients->pluck('ciqual_code')->filter())
            ->get()
            ->keyBy('ciqual_code');
    }

    /** Candidats proposés dans la fenêtre de rattachement. */
    #[Computed]
    public function candidates(): Collection
    {
        if (! $this->editingId || trim($this->foodSearch) === '') {
            return collect();
        }

        return NutritionFood::query()->search($this->foodSearch)->orderBy('name')->limit(25)->get();
    }

    public function render()
    {
        return view('livewire.settings.nutrition', [
            'foodCount' => NutritionFood::count(),
            'attached' => Ingredient::whereNotNull('ciqual_code')->count(),
            'total' => Ingredient::count(),
            'editing' => $this->editingId ? Ingredient::find($this->editingId) : null,
        ]);
    }
}
