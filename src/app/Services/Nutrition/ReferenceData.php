<?php

namespace App\Services\Nutrition;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Données de référence livrées avec Bouffe (lot 43) : la table Ciqual 2025 et le catalogue
 * d'ingrédients qui s'appuie dessus.
 *
 * Appelé par `bouffe:deploy` (après les migrations), par `db:seed` et par `bouffe:ciqual`.
 * Chaque fichier n'est appliqué qu'une fois par version (empreinte du fichier, table
 * reference_versions) : un déploiement sans changement ne fait rien, en une requête.
 *
 * Ordre : table Ciqual → catalogue → association des autres ingrédients par le nom → recalcul
 * des recettes concernées.
 */
class ReferenceData
{
    public const CIQUAL_PATH = 'database/data/ciqual-2025.csv';

    public function __construct(
        private readonly CiqualImporter $importer,
        private readonly CiqualCatalogue $catalogue,
        private readonly RecipeNutrition $recipes,
    ) {}

    public function ciqualPath(): string
    {
        return base_path(self::CIQUAL_PATH);
    }

    /** Les tables existent-elles (migrations du lot 43 passées) ? */
    public function ready(): bool
    {
        return Schema::hasTable('reference_versions') && Schema::hasColumn('nutrition_foods', 'food_subgroup');
    }

    /**
     * Ce qui reste à appliquer : ['ciqual' => version, 'catalogue' => version], vide si tout est à jour.
     *
     * @return array<string, string>
     */
    public function pending(): array
    {
        if (! $this->ready()) {
            return [];
        }

        $applied = DB::table('reference_versions')->pluck('version', 'name');
        $pending = [];

        foreach (['ciqual' => $this->ciqualPath(), 'catalogue' => $this->catalogue->path()] as $name => $path) {
            if (! is_readable($path)) {
                continue;
            }

            $version = substr(sha1_file($path) ?: '', 0, 16);

            if (($applied[$name] ?? null) !== $version) {
                $pending[$name] = $version;
            }
        }

        return $pending;
    }

    /**
     * Applique ce qui a changé.
     *
     * @param  bool  $force  refaire même si la version est déjà appliquée
     * @return array{skipped: bool, foods: int|null, created: int, linked: int, kept: int, matched: int, recipes: int}
     */
    public function sync(bool $force = false): array
    {
        $result = ['skipped' => true, 'foods' => null, 'created' => 0, 'linked' => 0, 'kept' => 0, 'matched' => 0, 'recipes' => 0];

        if (! $this->ready() || (! $force && ! config('bouffe.reference_data', true))) {
            return $result;
        }

        $pending = $this->pending();

        if ($force) {
            $pending += array_filter([
                'ciqual' => is_readable($this->ciqualPath()) ? substr(sha1_file($this->ciqualPath()), 0, 16) : null,
                'catalogue' => is_readable($this->catalogue->path()) ? $this->catalogue->version() : null,
            ]);
        }

        if ($pending === []) {
            return $result;
        }

        $result['skipped'] = false;

        if (isset($pending['ciqual'])) {
            $result['foods'] = $this->importer->importCsv($this->ciqualPath())['imported'];
            $this->remember('ciqual', $pending['ciqual'], ['foods' => $result['foods']]);
        }

        if (isset($pending['catalogue'])) {
            $previous = json_decode((string) DB::table('reference_versions')->where('name', 'catalogue')->value('meta'), true)['names'] ?? [];
            $applied = $this->catalogue->apply($previous);
            $result = array_merge($result, array_intersect_key($applied, array_flip(['created', 'linked', 'kept'])));
            $this->remember('catalogue', $pending['catalogue'], ['names' => array_values(array_unique([...$previous, ...$applied['names']]))]);
        }

        $result['matched'] = $this->importer->matchIngredients()['matched'];

        // Tout a pu changer : on recalcule toutes les recettes.
        DB::table('recipes')->update(['nutrition_at' => null]);
        $result['recipes'] = $this->recipes->refreshStale(PHP_INT_MAX);

        return $result;
    }

    private function remember(string $name, string $version, array $meta): void
    {
        DB::table('reference_versions')->updateOrInsert(
            ['name' => $name],
            ['version' => $version, 'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'applied_at' => now()],
        );
    }
}
