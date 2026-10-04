<?php

namespace App\Services\System;

use App\Models\Guest;
use App\Models\Ingredient;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Export de toutes les données (lot 16, point 20.8).
 *
 * Différent d'une sauvegarde : une sauvegarde sert à remettre Bouffe en état, un export sert
 * à **emporter** ses données. Le contenu est lisible sans Bouffe — du JSON commenté en français
 * et les photos dans un dossier — pour pouvoir repartir ailleurs un jour.
 */
class DataExporter
{
    /** Crée l'archive et renvoie son chemin (à supprimer après envoi). */
    public function toZip(): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('L\'extension PHP « zip » est nécessaire pour exporter les données.');
        }

        $path = tempnam(sys_get_temp_dir(), 'bouffe-export-').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de créer l\'archive de l\'export.');
        }

        $data = $this->data();

        $zip->addFromString('lisez-moi.txt', $this->readme($data));
        $zip->addFromString('donnees.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('recettes.md', $this->recipesAsText());

        // Lot 24 : seulement les fichiers du foyer actif (recettes, réceptions, tickets).
        $disk = Storage::disk('local');

        if ($household = \App\Support\CurrentHousehold::get()) {
            foreach (app(\App\Services\Households\HouseholdData::class)->files($household) as $file) {
                $zip->addFile($disk->path($file), (str_starts_with($file, 'receipts/') ? 'tickets/' : 'photos/').basename($file));
            }
        }

        if (! $zip->close()) {
            throw new RuntimeException('Écriture de l\'archive de l\'export impossible.');
        }

        return $path;
    }

    public function filename(): string
    {
        $household = \Illuminate\Support\Str::slug((string) \App\Support\CurrentHousehold::get()?->name) ?: 'foyer';

        return 'bouffe-export-'.$household.'-'.Carbon::now()->format('Y-m-d').'.zip';
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return [
            'application' => 'bouffe',
            'version' => config('bouffe.version'),
            'exporte_le' => Carbon::now()->toIso8601String(),

            'recettes' => Recipe::with(['ingredients.ingredient', 'ingredients.unit', 'steps', 'tags'])->get()
                ->map(fn (Recipe $recipe) => [
                    'titre' => $recipe->title,
                    'portions' => $recipe->servings,
                    'preparation_minutes' => $recipe->prep_minutes,
                    'cuisson_minutes' => $recipe->cook_minutes,
                    'source' => $recipe->source,
                    'notes' => $recipe->notes,
                    'categories' => $recipe->tags->pluck('name'),
                    'photo' => $recipe->photo_path ? basename($recipe->photo_path) : null,
                    'ingredients' => $recipe->ingredients->map(fn ($line) => [
                        'ingredient' => $line->ingredient?->name,
                        'quantite' => $line->quantity,
                        'unite' => $line->unit?->code,
                        'facultatif' => (bool) $line->is_optional,
                        'precision' => $line->preparation,
                    ]),
                    'etapes' => $recipe->steps->sortBy('position')->values()->map(fn ($step) => [
                        'groupe' => $step->group_name,
                        'texte' => $step->instruction,
                    ]),
                ]),

            // Lot 31 (31.1) : les collections et leurs recettes, dans l'ordre choisi.
            'collections' => \App\Models\RecipeCollection::with('recipes:recipes.id,recipes.title')->orderBy('name')->get()
                ->map(fn ($collection) => [
                    'nom' => $collection->name,
                    'description' => $collection->description,
                    'recettes' => $collection->recipes->pluck('title')->all(),
                ])->all(),

            'planning' => PlannedMeal::with(['recipe', 'slot'])->orderBy('date')->get()
                ->map(fn (PlannedMeal $meal) => [
                    'date' => $meal->date->toDateString(),
                    'creneau' => $meal->slot?->name,
                    'repas' => $meal->label(),
                    'portions' => $meal->servings,
                    'cuisine_le' => $meal->cooked_at?->toIso8601String(),
                ]),

            'listes_de_courses' => ShoppingList::with('items')->orderByDesc('period_start')->get()
                ->map(fn (ShoppingList $list) => [
                    'nom' => $list->name,
                    'du' => $list->period_start?->toDateString(),
                    'au' => $list->period_end?->toDateString(),
                    'articles' => $list->items->map(fn ($item) => [
                        'article' => $item->label,
                        'quantite' => $item->quantity,
                        'coche' => (bool) $item->is_checked,
                    ]),
                ]),

            'stock' => StockItem::with(['ingredient', 'location', 'unit'])->get()
                ->map(fn (StockItem $item) => [
                    'ingredient' => $item->ingredient?->name,
                    'emplacement' => $item->location?->name,
                    'quantite' => $item->quantity,
                    'unite' => $item->unit?->code,
                    'date_limite' => $item->expires_on?->toDateString(),
                ]),

            'ingredients' => Ingredient::with(['aisle', 'defaultUnit'])->orderBy('name')->get()
                ->map(fn (Ingredient $ingredient) => [
                    'nom' => $ingredient->name,
                    'rayon' => $ingredient->aisle?->name,
                    'unite_par_defaut' => $ingredient->defaultUnit?->code,
                    'produit_de_base' => (bool) $ingredient->is_staple,
                ]),

            'invites' => Guest::with('restrictions')->orderBy('name')->get()
                ->map(fn (Guest $guest) => [
                    'nom' => $guest->name,
                    'notes' => $guest->notes,
                    'restrictions' => $guest->restrictions->map(fn ($r) => $r->type->label().' : '.$r->subject()),
                ]),

            'foyer' => \App\Support\CurrentHousehold::get()?->name,

            'comptes' => User::query()->inHousehold()->orderBy('name')->get()
                ->map(fn (User $user) => ['nom' => $user->name, 'email' => $user->email, 'role' => $user->role?->value]),

            'depenses' => \App\Models\Expense::with(['splits.category', 'store'])->orderBy('spent_on')->get()
                ->map(fn (\App\Models\Expense $expense) => [
                    'date' => $expense->spent_on->toDateString(),
                    'lieu' => $expense->placeLabel(),
                    'montant' => (float) $expense->amount,
                    'postes' => $expense->splits->map(fn ($split) => ['poste' => $split->category?->name, 'montant' => (float) $split->amount]),
                    'origine' => $expense->source,
                ]),
        ];
    }

    /** Les recettes en texte lisible : de quoi les relire ou les imprimer sans rien installer. */
    private function recipesAsText(): string
    {
        $lines = ['# Recettes de Bouffe', ''];

        foreach (Recipe::with(['ingredients.ingredient', 'ingredients.unit', 'steps'])->orderBy('title')->get() as $recipe) {
            $lines[] = '## '.$recipe->title;
            $lines[] = '';
            $lines[] = 'Pour '.$recipe->servings.' personne(s).';
            $lines[] = '';

            foreach ($recipe->ingredients as $line) {
                $quantity = trim(($line->quantity ? rtrim(rtrim((string) $line->quantity, '0'), '.') : '').' '.($line->unit?->code ?? ''));
                $lines[] = '- '.trim($quantity.' '.($line->ingredient?->name ?? ''));
            }

            $lines[] = '';

            foreach ($recipe->steps->sortBy('position')->values() as $i => $step) {
                $lines[] = ($i + 1).'. '.$step->instruction;
            }

            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    private function readme(array $data): string
    {
        return implode("\n", [
            'Export des données de Bouffe',
            '============================',
            '',
            'Fait le '.Carbon::now()->locale('fr')->isoFormat('D MMMM YYYY à HH:mm').'.',
            '',
            'Contenu :',
            '  donnees.json   toutes les données, lisibles dans n\'importe quel éditeur de texte',
            '  recettes.md    les recettes en texte simple, à relire ou à imprimer',
            '  photos/        les photos des recettes et des réceptions (le nom du fichier figure dans donnees.json)',
            '  tickets/       les photos de tickets de caisse encore conservées',
            '',
            'Ce dossier n\'est pas une sauvegarde : pour remettre Bouffe en état après une panne,',
            'utilisez Paramètres → Sauvegardes, qui contient la base de données complète.',
            '',
            count($data['recettes']).' recette(s) · '.count($data['planning']).' repas planifié(s) · '
                .count($data['listes_de_courses']).' liste(s) de courses · '.count($data['stock']).' produit(s) en stock',
            '',
        ]);
    }
}
