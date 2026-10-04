<?php

namespace App\Services\Linked;

use App\Models\Recipe;
use App\Services\IngredientLineFormatter;
use App\Support\CurrentHousehold;
use Illuminate\Support\Collection;

/**
 * Carnet familial (26.9) : un livre de recettes réunissant plusieurs foyers — sommaire, une recette
 * par page, photo, auteur. Imprimé depuis le navigateur (ou « Enregistrer en PDF »).
 */
class FamilyBook
{
    public function __construct(private readonly SharedRecipes $shared, private readonly IngredientLineFormatter $formatter) {}

    /**
     * Recettes proposées : notre carnet, puis celles des proches visibles, par foyer.
     *
     * @return Collection<string, Collection<int, Recipe>>
     */
    public function candidates(): Collection
    {
        $own = Recipe::query()->active()->with('household')->orderBy('search_title')->get();
        $theirs = $this->shared->visibleQuery()->with('household')->orderBy('search_title')->get();

        return $own->concat($theirs)->groupBy(fn (Recipe $r) => (string) $r->household?->name)
            ->sortBy(fn ($group, $name) => $group->first()->household_id === CurrentHousehold::id() ? '' : $name);
    }

    /**
     * Recettes lisibles, dans l'ordre demandé, relations chargées.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Recipe>
     */
    public function recipes(array $ids): Collection
    {
        $ids = array_slice(array_values(array_unique($ids)), 0, 200);

        if ($ids === []) {
            return collect();
        }

        $own = Recipe::query()->active()->whereIn('id', $ids)->get();
        $theirs = $this->shared->visibleQuery()->whereIn('recipes.id', $ids)->get();
        $all = $own->concat($theirs)->keyBy('id');

        return collect($ids)->map(fn (int $id) => $all->get($id))->filter()
            ->each(fn (Recipe $r) => $r->load(['steps', 'household', 'author']))
            ->values();
    }

    /** @return Collection<string, list<array{quantity: string, name: string, preparation: string|null, optional: bool}>> lignes par groupe */
    public function lines(Recipe $recipe): Collection
    {
        return $recipe->ingredients()->with('ingredient', 'unit')->orderBy('sort_order')->orderBy('id')->get()
            ->map(function ($line) {
                $parts = $line->ingredient ? $this->formatter->format($line->quantity !== null ? (float) $line->quantity : null, $line->unit, $line->ingredient) : ['quantity' => '', 'name' => ''];

                return ['group' => (string) $line->group_name, 'quantity' => $parts['quantity'], 'name' => $parts['name'], 'preparation' => $line->preparation, 'optional' => (bool) $line->is_optional];
            })
            ->groupBy('group');
    }
}
