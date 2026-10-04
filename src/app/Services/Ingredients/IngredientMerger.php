<?php

namespace App\Services\Ingredients;

use App\Enums\ItemOrigin;
use App\Enums\ListStatus;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\IngredientMerge;
use App\Models\ShoppingList;
use App\Services\Backup\BackupManager;
use App\Services\Shopping\ShoppingListManager;
use App\Support\NameNormalizer;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Fusion d'ingrédients en double et doublons probables (12.6, règle R22).
 *
 * Fusionner A dans B : toutes les références à A passent sur B, A est supprimé et son nom devient
 * un alias de B. La dernière fusion peut être annulée (A recréé avec le même identifiant).
 */
class IngredientMerger
{
    /** Tables qui référencent un ingrédient. */
    public const REFERENCES = [
        'recipe_ingredients' => 'ingredient_id',
        'stock_items' => 'ingredient_id',
        'stock_movements' => 'ingredient_id',
        'shopping_list_items' => 'ingredient_id',
        'recurring_items' => 'ingredient_id',
        'guest_restrictions' => 'ingredient_id',
        'person_restrictions' => 'ingredient_id',   // goûts des personnes du foyer (lot 39)
        'ingredient_aliases' => 'ingredient_id',
        'ingredient_prices' => 'ingredient_id',
        'receipt_lines' => 'ingredient_id',
        'receipt_label_mappings' => 'ingredient_id',
        'products' => 'ingredient_id',
        'stock_usage_rules' => 'ingredient_id',
        'household_ingredient_settings' => 'ingredient_id',
    ];

    /** Tables à une ligne par foyer et par ingrédient : en cas de doublon, celle de l'ingrédient gardé l'emporte. */
    private const PER_HOUSEHOLD = ['stock_usage_rules', 'household_ingredient_settings'];

    public function __construct(
        private readonly ShoppingListManager $shopping,
        private readonly BackupManager $backups,
    ) {}

    /**
     * Ce qu'une fusion va déplacer (fenêtre de confirmation).
     *
     * @return array{recipes: int, stock: int, lists: int, restrictions: int}
     */
    public function impact(Ingredient $source): array
    {
        return [
            'recipes' => DB::table('recipe_ingredients')->where('ingredient_id', $source->id)->distinct()->count('recipe_id'),
            'stock' => DB::table('stock_items')->where('ingredient_id', $source->id)->whereNull('finished_at')->count(),
            'lists' => DB::table('shopping_list_items')->where('ingredient_id', $source->id)->distinct()->count('shopping_list_id'),
            'restrictions' => DB::table('guest_restrictions')->where('ingredient_id', $source->id)->count()
                + DB::table('person_restrictions')->where('ingredient_id', $source->id)->count(),
        ];
    }

    public function merge(Ingredient $source, Ingredient $target): IngredientMerge
    {
        if ($source->is($target)) {
            throw new InvalidArgumentException('Choisissez deux ingrédients différents.');
        }

        $this->backupBeforeMerge();

        return DB::transaction(function () use ($source, $target) {
            $moved = [];
            $deleted = [];

            // Contraintes d'invités et de personnes du foyer déjà présentes sur la cible (index unique) : celles de la source sont retirées.
            foreach (['guest_restrictions' => 'guest_id', 'person_restrictions' => 'person_id'] as $table => $owner) {
                $duplicates = DB::table($table.' as s')
                    ->join($table.' as t', fn ($j) => $j->on('t.'.$owner, '=', 's.'.$owner)->on('t.type', '=', 's.type'))
                    ->where('s.ingredient_id', $source->id)->where('t.ingredient_id', $target->id)
                    ->pluck('s.id');

                if ($duplicates->isNotEmpty()) {
                    $deleted[$table] = DB::table($table)->whereIn('id', $duplicates)->get()->map(fn ($r) => (array) $r)->all();
                    DB::table($table)->whereIn('id', $duplicates)->delete();
                }
            }

            // Articles générés des listes en cours : recalculés après la fusion (sinon deux lignes pour le même ingrédient).
            $activeLists = ShoppingList::query()->where('status', ListStatus::Active->value)
                ->whereHas('items', fn ($q) => $q->where('ingredient_id', $source->id))->get();

            DB::table('shopping_list_items')
                ->whereIn('shopping_list_id', $activeLists->pluck('id'))
                ->where('ingredient_id', $source->id)
                ->whereIn('origin', [ItemOrigin::Generated->value, ItemOrigin::Staple->value])
                ->delete();

            foreach (self::PER_HOUSEHOLD as $table) {
                // Liste lue d'abord : MySQL refuse une sous-requête sur la table qu'on modifie (erreur 1093, lot 25).
                $households = DB::table($table)->where('ingredient_id', $target->id)->pluck('household_id')->all();

                if ($households !== []) {
                    DB::table($table)->where('ingredient_id', $source->id)->whereIn('household_id', $households)->delete();
                }
            }

            foreach (self::REFERENCES as $table => $column) {
                $ids = DB::table($table)->where($column, $source->id)->pluck('id')->all();

                if ($ids !== []) {
                    DB::table($table)->whereIn('id', $ids)->update([$column => $target->id]);
                    $moved[$table] = $ids;
                }
            }

            $attributes = DB::table('ingredients')->where('id', $source->id)->first();
            DB::table('ingredients')->where('id', $source->id)->delete();

            $aliases = collect([$source->name, $source->name_plural])
                ->filter()
                ->unique(fn ($name) => NameNormalizer::normalize($name))
                ->reject(fn ($name) => NameNormalizer::normalize($name) === $target->search_name
                    || IngredientAlias::query()->where('search_name', NameNormalizer::normalize($name))->exists())
                ->map(fn ($name) => IngredientAlias::create(['ingredient_id' => $target->id, 'name' => $name])->id)
                ->values()->all();

            foreach ($activeLists as $list) {
                $this->shopping->regenerate($list);
            }

            return IngredientMerge::create([
                'source_id' => $source->id,
                'source_name' => $source->name,
                'target_id' => $target->id,
                'user_id' => auth()->id(),
                'payload' => [
                    'attributes' => (array) $attributes,
                    'moved' => $moved,
                    'deleted' => $deleted,
                    'aliases' => $aliases,
                    'lists' => $activeLists->pluck('id')->all(),
                ],
            ]);
        });
    }

    /** Dernière fusion encore annulable. */
    public function lastUndoable(): ?IngredientMerge
    {
        $merge = IngredientMerge::query()->whereNull('undone_at')->latest('id')->first();

        return $merge && $merge->target && ! Ingredient::whereKey($merge->source_id)->exists() ? $merge : null;
    }

    public function undo(IngredientMerge $merge): Ingredient
    {
        if ($merge->undone_at || ! $merge->is($this->lastUndoable())) {
            throw new InvalidArgumentException('Seule la dernière fusion peut être annulée.');
        }

        $payload = $merge->payload;
        $attributes = $payload['attributes'];

        if (Ingredient::query()->where('search_name', $attributes['search_name'])->exists()) {
            throw new InvalidArgumentException("Un ingrédient « {$attributes['name']} » existe de nouveau : annulation impossible.");
        }

        return DB::transaction(function () use ($merge, $payload, $attributes) {
            IngredientAlias::query()->whereIn('id', $payload['aliases'] ?? [])->delete();
            DB::table('ingredients')->insert($attributes);

            foreach ($payload['moved'] ?? [] as $table => $ids) {
                $column = self::REFERENCES[$table] ?? null;

                if ($column) {
                    // Seules les lignes encore rattachées à la cible reviennent (une ligne modifiée depuis reste où elle est).
                    DB::table($table)->whereIn('id', $ids)->where($column, $merge->target_id)->update([$column => $merge->source_id]);
                }
            }

            foreach (['guest_restrictions', 'person_restrictions'] as $table) {
                foreach ($payload['deleted'][$table] ?? [] as $row) {
                    DB::table($table)->insertOrIgnore($row);
                }
            }

            foreach (ShoppingList::query()->whereIn('id', $payload['lists'] ?? [])->where('status', ListStatus::Active->value)->get() as $list) {
                DB::table('shopping_list_items')->where('shopping_list_id', $list->id)
                    ->whereIn('ingredient_id', [$merge->source_id, $merge->target_id])
                    ->whereIn('origin', [ItemOrigin::Generated->value, ItemOrigin::Staple->value])
                    ->delete();
                $this->shopping->regenerate($list);
            }

            $merge->update(['undone_at' => now()]);

            return Ingredient::findOrFail($merge->source_id);
        });
    }

    /**
     * Doublons probables : noms proches (faute de frappe, mot en plus) dans le même rayon,
     * ou noms identiques une fois les espaces retirés. Les paires écartées ne sont plus proposées.
     *
     * @return Collection<int, array{a: Ingredient, b: Ingredient}>
     */
    public function duplicates(): Collection
    {
        $ignored = array_flip((array) Settings::get('ingredients.ignored_duplicates', []));
        $ingredients = Ingredient::query()->with('aisle')->orderBy('search_name')->get()->values();
        $pairs = collect();

        foreach ($ingredients as $i => $a) {
            for ($j = $i + 1; $j < $ingredients->count(); $j++) {
                $b = $ingredients[$j];

                if (isset($ignored[self::pairKey($a->id, $b->id)])) {
                    continue;
                }

                $sameCompact = str_replace(' ', '', $a->search_name) === str_replace(' ', '', $b->search_name);
                $similar = $a->aisle_id === $b->aisle_id && $this->looksLikeDuplicate($a->search_name, $b->search_name);

                if ($sameCompact || $similar) {
                    $pairs->push(['a' => $a, 'b' => $b]);
                }
            }
        }

        return $pairs;
    }

    public function ignore(int $idA, int $idB): void
    {
        $ignored = (array) Settings::get('ingredients.ignored_duplicates', []);
        $ignored[] = self::pairKey($idA, $idB);
        Settings::set('ingredients.ignored_duplicates', $ignored);
    }

    public static function pairKey(int $a, int $b): string
    {
        return min($a, $b).'-'.max($a, $b);
    }

    /**
     * Plus strict que NameNormalizer::isSimilar (qui sert à prévenir à la saisie) :
     * « tomate » / « tomate cerise » sont deux ingrédients différents, « tomatte » / « tomate » non.
     */
    private function looksLikeDuplicate(string $a, string $b): bool
    {
        $wordsA = explode(' ', $a);
        $wordsB = explode(' ', $b);

        if (count($wordsA) !== count($wordsB)) {
            return false;
        }

        // Un seul mot diffère, et de peu : « tomatte » / « tomate », mais pas « côte de porc » / « rôti de porc ».
        $different = array_values(array_filter(array_keys($wordsA), fn ($i) => $wordsA[$i] !== $wordsB[$i]));

        if (count($different) !== 1) {
            return false;
        }

        [$wordA, $wordB] = [$wordsA[$different[0]], $wordsB[$different[0]]];
        $length = max(strlen($wordA), strlen($wordB));

        return min(strlen($wordA), strlen($wordB)) >= 4 && levenshtein($wordA, $wordB) <= ($length >= 9 ? 2 : 1);
    }

    private function backupBeforeMerge(): void
    {
        if (! config('bouffe.backups.before_merge', true) || ! $this->backups->zipAvailable()) {
            return;
        }

        try {
            $this->backups->create('merge');
        } catch (\Throwable) {
            // La fusion reste annulable ; une sauvegarde impossible (disque, droits) ne doit pas bloquer.
        }
    }
}
