<?php

namespace App\Services\Search;

use App\Enums\ListStatus;
use App\Models\Guest;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\ShoppingListItem;
use App\Models\StockItem;
use App\Services\QuantityFormatter;
use App\Services\Stock\ExpiryCalculator;
use App\Support\NameNormalizer;

/**
 * Recherche globale (12.1) : pages, recettes, stock, ingrédients, invités et liste de courses en cours.
 *
 * Chaque résultat : ['title', 'subtitle', 'url', 'icon', 'badge' => ?['text', 'color'], 'actions' => list<['label', 'url']>].
 */
class GlobalSearch
{
    public const PER_GROUP = 5;

    public function __construct(
        private readonly QuantityFormatter $formatter,
        private readonly ExpiryCalculator $expiry,
    ) {}

    /** @return array<string, list<array>> groupes non vides, dans l'ordre d'affichage */
    public function search(string $query): array
    {
        $term = NameNormalizer::normalize($query);

        if (mb_strlen($term) < 2) {
            return ['Aller à' => array_slice($this->pages(''), 0, 8)];
        }

        return array_filter([
            'Aller à' => array_slice($this->pages($term), 0, self::PER_GROUP),
            'Recettes' => $this->recipes($query),
            'Stock' => $this->stock($term),
            'Liste de courses' => $this->shopping($term),
            'Ingrédients' => $this->ingredients($query),
            'Invités' => $this->guests($term),
        ]);
    }

    /** Pages et actions, filtrées par mots-clés. */
    public function pages(string $term): array
    {
        $pages = [
            ['Nouvelle recette', 'Recettes', 'recipes.create', 'plus', 'ajouter creer recette'],
            ['Importer une recette', 'Depuis une adresse ou du texte', 'recipes.import', 'download', 'import importer url adresse coller texte marmiton json'],
            ['Planning de la semaine', 'Planning', 'planner.week', 'calendar', 'planning semaine menu repas'],
            ['Remplir la semaine', 'Planning', 'planner.fill', 'sparkles', 'remplir automatique proposition semaine menu'],
            ['Semaines types', 'Planning', 'planner.templates', 'squares', 'semaine type modele gabarit'],
            ['Règles de la semaine', 'Paramètres → Planning', 'settings.planning', 'settings', 'regle quota poisson vegetarien temps rappel'],
            ['Foyer', 'Paramètres → Foyer', 'settings.household', 'users', 'foyer membre role allergie regime naime pas preference'],
            ['Que cuisiner ?', 'Suggestions depuis le stock', 'suggestions', 'sparkles', 'que cuisiner idee suggestion stock recette'],
            ['Listes de courses', 'Courses', 'shopping.index', 'cart', 'course liste achat magasin'],
            ['Stock', 'Frigo, congélateur, placard', 'stock.index', 'pantry', 'stock frigo refrigerateur congelateur placard'],
            ['Recettes', 'Carnet de recettes', 'recipes.index', 'recipes', 'recette carnet'],
            ['Invités', 'Carnet d\'invités', 'guests.index', 'users', 'invite convive ami famille allergie'],
            ['Ingrédients', 'Paramètres', 'settings.ingredients', 'settings', 'parametre ingredient'],
            ['Doublons d\'ingrédients', 'Paramètres → Ingrédients', 'settings.ingredient-duplicates', 'merge', 'doublon fusion fusionner ingredient alias'],
            ['Rayons', 'Paramètres', 'settings.aisles', 'settings', 'parametre rayon'],
            ['Unités', 'Paramètres', 'settings.units', 'settings', 'parametre unite mesure'],
            ['Catégories', 'Paramètres', 'settings.tags', 'settings', 'parametre categorie tag'],
            ['Créneaux', 'Paramètres', 'settings.slots', 'settings', 'parametre creneau repas dejeuner diner'],
            ['Emplacements', 'Paramètres', 'settings.locations', 'settings', 'parametre emplacement stock'],
            ['Alertes stock', 'Paramètres', 'settings.stock', 'settings', 'parametre alerte stock peremption deduction'],
            ['Articles récurrents', 'Paramètres', 'settings.recurring', 'settings', 'parametre article recurrent course'],
            ['Sauvegardes', 'Paramètres', 'settings.backups', 'settings', 'parametre sauvegarde restauration backup'],
            ['Accès téléphone', 'Paramètres', 'settings.phone', 'settings', 'parametre telephone mobile qr code'],
            ['Affichage', 'Barre du bas, accueil, thème', 'settings.display', 'settings', 'parametre affichage theme sombre barre accueil'],
        ];

        return collect($pages)
            ->filter(fn (array $p) => $term === '' || str_contains(NameNormalizer::normalize($p[0].' '.$p[4]), $term))
            ->map(fn (array $p) => ['title' => $p[0], 'subtitle' => $p[1], 'url' => route($p[2]), 'icon' => $p[3], 'badge' => null, 'actions' => []])
            ->values()->all();
    }

    private function recipes(string $query): array
    {
        return Recipe::query()->search($query)
            ->orderByRaw('archived_at IS NOT NULL')->orderBy('search_title')
            ->limit(self::PER_GROUP)->get()
            ->map(fn (Recipe $recipe) => [
                'title' => $recipe->title,
                'subtitle' => collect([$recipe->total_minutes ? \App\Support\Duration::format($recipe->total_minutes) : null, $recipe->servings.' portions'])->filter()->join(' · '),
                'url' => route('recipes.show', $recipe),
                'icon' => 'recipes',
                'badge' => $recipe->isArchived() ? ['text' => 'Archivée', 'color' => 'stone'] : null,
                'actions' => $recipe->isArchived() ? [] : [['label' => 'Avec mon stock', 'url' => route('recipes.show', $recipe)]],
            ])->all();
    }

    private function stock(string $term): array
    {
        return StockItem::query()->active()->with('ingredient.aliases', 'unit', 'location')->get()
            ->filter(fn (StockItem $item) => $this->matches($term, $item->name(), $item->ingredient?->aliases->pluck('name')->all() ?? []))
            ->sortBy(fn (StockItem $item) => $this->expiry->effective($item)['date']?->timestamp ?? PHP_INT_MAX)
            ->take(self::PER_GROUP)
            ->map(function (StockItem $item) {
                $badge = $this->expiry->badge($item);

                return [
                    'title' => $item->name(),
                    'subtitle' => collect([$item->quantity !== null ? $this->formatter->format((float) $item->quantity, $item->unit) : null, $item->location->name])->filter()->join(' · '),
                    'url' => route('stock.index', ['q' => $item->name()]),
                    'icon' => 'pantry',
                    'badge' => $badge ? ['text' => $badge['text'], 'color' => $badge['color']] : null,
                    'actions' => $item->ingredient_id ? [['label' => 'Que cuisiner ?', 'url' => route('suggestions', ['utiliser' => [$item->ingredient_id]])]] : [],
                ];
            })->values()->all();
    }

    private function shopping(string $term): array
    {
        return ShoppingListItem::query()
            ->whereHas('shoppingList', fn ($q) => $q->where('status', ListStatus::Active->value))
            ->where('is_removed', false)
            ->with('shoppingList')
            ->get()
            ->filter(fn (ShoppingListItem $item) => str_contains(NameNormalizer::normalize($item->label), $term))
            ->take(self::PER_GROUP)
            ->map(fn (ShoppingListItem $item) => [
                'title' => $item->label,
                'subtitle' => $item->shoppingList->name,
                'url' => route('shopping.show', $item->shoppingList),
                'icon' => 'cart',
                'badge' => $item->is_checked ? ['text' => 'Coché', 'color' => 'green'] : null,
                'actions' => [],
            ])->values()->all();
    }

    private function ingredients(string $query): array
    {
        $term = NameNormalizer::normalize($query);

        return Ingredient::query()->search($query)->with('aliases', 'aisle')->withCount(['stockItems as in_stock' => fn ($q) => $q->active()])
            ->ordered()->limit(self::PER_GROUP)->get()
            ->map(function (Ingredient $ingredient) use ($term) {
                $alias = str_contains($ingredient->search_name, $term) ? null : $ingredient->aliases->first(fn ($a) => str_contains($a->search_name, $term));

                return [
                    'title' => $ingredient->name,
                    'subtitle' => collect([$ingredient->aisle?->name, $alias ? 'autre nom : '.$alias->name : null])->filter()->join(' · '),
                    'url' => route('settings.ingredients', ['q' => $ingredient->name]),
                    'icon' => 'tag',
                    'badge' => $ingredient->in_stock > 0 ? ['text' => 'En stock', 'color' => 'green'] : null,
                    'actions' => array_values(array_filter([
                        ['label' => 'Recettes', 'url' => route('recipes.index', ['q' => $ingredient->name])],
                        $ingredient->in_stock > 0 ? ['label' => 'Voir au stock', 'url' => route('stock.index', ['q' => $ingredient->name])] : null,
                    ])),
                ];
            })->all();
    }

    private function guests(string $term): array
    {
        return Guest::query()->active()->ordered()->get()
            ->filter(fn (Guest $guest) => str_contains(NameNormalizer::normalize($guest->name.' '.$guest->group_name), $term))
            ->take(self::PER_GROUP)
            ->map(fn (Guest $guest) => [
                'title' => $guest->name,
                'subtitle' => (string) $guest->group_name,
                'url' => route('guests.show', $guest),
                'icon' => 'users',
                'badge' => $guest->is_child ? ['text' => 'Enfant', 'color' => 'sky'] : null,
                'actions' => [],
            ])->values()->all();
    }

    private function matches(string $term, string $name, array $aliases): bool
    {
        foreach ([$name, ...$aliases] as $candidate) {
            if (str_contains(NameNormalizer::normalize($candidate), $term)) {
                return true;
            }
        }

        return false;
    }
}
