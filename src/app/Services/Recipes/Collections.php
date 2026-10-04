<?php

namespace App\Services\Recipes;

use App\Models\Household;
use App\Models\Recipe;
use App\Models\RecipeCollection;
use App\Models\Scopes\HouseholdScope;
use App\Services\Linked\HouseholdLinks;
use App\Services\Linked\SharedRecipes;
use App\Support\CurrentHousehold;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Collections de recettes (lot 31, 31.1).
 *
 * Une collection est un regroupement libre, propre au foyer. Partagée avec les foyers reliés,
 * elle apparaît chez eux avec **les recettes qu'ils peuvent déjà lire** (R31 : partager une
 * collection n'ouvre pas les recettes privées ; on propose de les ouvrir en même temps).
 */
class Collections
{
    public const MAX_NAME = 100;

    public function __construct(
        private readonly HouseholdLinks $links,
        private readonly SharedRecipes $shared,
    ) {}

    /** @return Collection<int, RecipeCollection> les collections du foyer, avec le nombre de recettes */
    public function mine(): Collection
    {
        return RecipeCollection::query()->withCount(['recipes' => fn ($q) => $q->reorder()])->orderBy('name')->get();
    }

    /** @return Builder<RecipeCollection> collections partagées par les foyers reliés au foyer actif */
    public function sharedWithMe(): Builder
    {
        $viewer = CurrentHousehold::id() ?? 0;
        $linked = $this->links->linkedIds($viewer);
        $active = Household::query()->whereNull('disabled_at')->whereNull('deletion_requested_at')->select('id');

        return RecipeCollection::query()->withoutGlobalScope(HouseholdScope::class)
            ->where('visibility', 'linked')
            ->whereIn('household_id', $linked === [] ? [0] : $linked)
            ->whereIn('household_id', $active);
    }

    public function create(string $name, ?string $description = null): RecipeCollection
    {
        $name = $this->cleanName($name);

        return RecipeCollection::create([
            'name' => $name,
            'description' => $this->cleanDescription($description),
            'created_by' => auth()->id(),
        ]);
    }

    public function update(RecipeCollection $collection, string $name, ?string $description): RecipeCollection
    {
        $this->ensureMine($collection);
        $collection->update(['name' => $this->cleanName($name, $collection->id), 'description' => $this->cleanDescription($description)]);

        return $collection;
    }

    public function delete(RecipeCollection $collection): void
    {
        $this->ensureMine($collection);
        $collection->delete();   // les recettes restent : seul le regroupement disparaît
    }

    /** Ajoute ou retire une recette ; renvoie true si elle y est désormais. */
    public function toggle(RecipeCollection $collection, Recipe $recipe): bool
    {
        $this->ensureMine($collection);

        if ($recipe->isForeign()) {
            throw new InvalidArgumentException('Copiez d\'abord cette recette dans votre carnet pour la ranger dans une collection.');
        }

        if (DB::table('collection_recipe')->where('collection_id', $collection->id)->where('recipe_id', $recipe->id)->exists()) {
            $collection->recipes()->detach($recipe->id);

            return false;
        }

        $position = (int) DB::table('collection_recipe')->where('collection_id', $collection->id)->max('position') + 1;
        $collection->recipes()->attach($recipe->id, ['position' => $position, 'created_at' => now()]);

        return true;
    }

    /** Monte (-1) ou descend (+1) une recette dans la collection. */
    public function move(RecipeCollection $collection, int $recipeId, int $direction): void
    {
        $this->ensureMine($collection);
        $ids = $collection->recipes()->pluck('recipes.id')->map(fn ($id) => (int) $id)->all();
        $index = array_search($recipeId, $ids, true);
        $target = $index === false ? false : $index + ($direction < 0 ? -1 : 1);

        if ($index === false || $target < 0 || $target >= count($ids)) {
            return;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        DB::transaction(function () use ($collection, $ids) {
            foreach ($ids as $position => $id) {
                DB::table('collection_recipe')->where('collection_id', $collection->id)->where('recipe_id', $id)->update(['position' => $position + 1]);
            }
        });
    }

    /**
     * Recettes affichées : toutes les nôtres (non archivées), ou, pour une collection de proches,
     * celles que notre foyer peut lire.
     *
     * @return Collection<int, Recipe>
     */
    public function recipes(RecipeCollection $collection): Collection
    {
        $query = $collection->recipes()->whereNull('recipes.archived_at')->with('tags');

        if ($collection->isForeign()) {
            $query->whereIn('recipes.id', $this->shared->visibleQuery()->select('recipes.id'));
        }

        return $query->get();
    }

    /** Recettes privées d'une collection : invisibles des proches même si la collection est partagée. */
    public function privateRecipes(RecipeCollection $collection): Collection
    {
        return $collection->recipes()->whereNull('recipes.archived_at')->where('recipes.visibility', 'private')->get();
    }

    /**
     * Partage (ou non) la collection avec les foyers reliés ; avec $withRecipes, ses recettes
     * privées sont ouvertes aux foyers reliés en même temps.
     *
     * @return int nombre de recettes ouvertes
     */
    public function setShared(RecipeCollection $collection, bool $shared, bool $withRecipes = false): int
    {
        $this->ensureMine($collection);
        $collection->update(['visibility' => $shared ? 'linked' : 'private']);

        if (! $shared || ! $withRecipes) {
            return 0;
        }

        $opened = 0;

        foreach ($this->privateRecipes($collection) as $recipe) {
            $recipe->update(['visibility' => 'linked']);
            $opened++;
        }

        return $opened;
    }

    /** Adresse du carnet imprimable (26.9) avec les recettes de la collection. */
    public function printUrl(RecipeCollection $collection): ?string
    {
        $ids = $this->recipes($collection)->pluck('id')->all();

        return $ids === [] ? null : route('linked.book.print', ['recettes' => implode(',', $ids), 'titre' => $collection->name]);
    }

    private function ensureMine(RecipeCollection $collection): void
    {
        if ($collection->isForeign()) {
            throw new InvalidArgumentException('Cette collection appartient à un foyer relié.');
        }
    }

    private function cleanName(string $name, ?int $ignoreId = null): string
    {
        $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), 0, self::MAX_NAME);

        if ($name === '') {
            throw new InvalidArgumentException('Donnez un nom à la collection.');
        }

        $taken = RecipeCollection::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();

        if ($taken) {
            throw new InvalidArgumentException('Une collection porte déjà ce nom.');
        }

        return $name;
    }

    private function cleanDescription(?string $description): ?string
    {
        return mb_substr(trim((string) $description), 0, 500) ?: null;
    }
}
