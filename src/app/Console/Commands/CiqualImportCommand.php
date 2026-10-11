<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Models\NutritionFood;
use App\Services\Nutrition\CiqualImporter;
use App\Services\Nutrition\RecipeNutrition;
use App\Services\Nutrition\ReferenceData;
use Illuminate\Console\Command;

/**
 * Table Ciqual (lot 19, point 17.4 ; lot 43).
 *
 *     php artisan bouffe:ciqual                 table Ciqual 2025 et catalogue livrés avec Bouffe
 *     php artisan bouffe:ciqual chemin\ciqual.csv   une autre édition (fichier CSV de ciqual.anses.fr)
 *
 * Sans fichier, la commande refait l'import livré même s'il a déjà été appliqué (bouffe:deploy
 * le fait tout seul quand le fichier change). Les choix faits dans Paramètres → Nutrition sont
 * toujours conservés.
 */
class CiqualImportCommand extends Command
{
    protected $signature = 'bouffe:ciqual
                            {fichier? : chemin du fichier CSV exporté depuis la table Ciqual}
                            {--associer : refaire la correspondance ingrédients → aliments, même pour ceux déjà associés}';

    protected $description = 'Importe la table nutritionnelle Ciqual (Anses), le catalogue d\'ingrédients, et associe les ingrédients';

    public function handle(CiqualImporter $importer, ReferenceData $reference, RecipeNutrition $recipes): int
    {
        $path = $this->argument('fichier');

        if ($path) {
            $this->newLine();
            $this->components->info('Lecture de '.$path);

            try {
                $result = $importer->importCsv($path);
            } catch (\Throwable $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $this->components->twoColumnDetail('Aliments importés', (string) $result['imported']);
            $this->components->twoColumnDetail('Lignes ignorées', (string) $result['skipped']);

            foreach ($result['columns'] as $field => $label) {
                $this->components->twoColumnDetail("  {$field}", "<fg=gray>{$label}</>");
            }

            if ($result['missing'] !== []) {
                $this->components->warn('Colonnes non trouvées (valeurs laissées vides) : '.implode(', ', $result['missing']));
            }
        } else {
            $this->newLine();
            $this->components->info('Table Ciqual 2025 et catalogue livrés avec Bouffe');

            $result = $reference->sync(force: true);

            if ($result['skipped']) {
                $this->components->error('Données de référence indisponibles : lancez d\'abord « php artisan migrate ».');

                return self::FAILURE;
            }

            $this->components->twoColumnDetail('Aliments importés', (string) $result['foods']);
            $this->components->twoColumnDetail('Ingrédients ajoutés depuis le catalogue', (string) $result['created']);
            $this->components->twoColumnDetail('Ingrédients rattachés par le catalogue', (string) $result['linked']);
            $this->components->twoColumnDetail('Choix faits à la main, conservés', (string) $result['kept']);
            $this->components->twoColumnDetail('Recettes recalculées', (string) $result['recipes']);
        }

        /* ---------------------------------------------- Correspondance ingrédients */

        $match = $importer->matchIngredients($this->option('associer'));

        $this->newLine();
        $this->components->twoColumnDetail('Autres ingrédients associés d\'après leur nom', $match['matched'].' / '.$match['total']);

        if ($path || $match['matched'] > 0) {
            \Illuminate\Support\Facades\DB::table('recipes')->update(['nutrition_at' => null]);
            $recipes->refreshStale(PHP_INT_MAX);
        }

        $without = Ingredient::query()->whereNull('ciqual_code')->count();

        if ($without > 0) {
            $this->components->warn("{$without} ingrédient(s) sans aliment associé : complétez-les dans Paramètres → Nutrition.");
        }

        $this->newLine();
        $this->components->info(NutritionFood::count().' aliments disponibles. Les valeurs nutritionnelles et le Nutri-Score estimé apparaissent sur les fiches recettes.');
        $this->line('  <fg=gray>'.NutritionFood::ATTRIBUTION.'</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
