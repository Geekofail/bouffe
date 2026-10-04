<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Models\NutritionFood;
use App\Services\Nutrition\CiqualImporter;
use Illuminate\Console\Command;

/**
 * Import de la table Ciqual (lot 19, point 17.4).
 *
 *     php artisan bouffe:ciqual C:\Users\Pierre\Downloads\ciqual.csv
 *
 * À faire une seule fois. Le fichier officiel se télécharge sur ciqual.anses.fr (ou
 * data.gouv.fr) : ouvrez le tableur, enregistrez-le au format CSV, et indiquez ce fichier ici.
 */
class CiqualImportCommand extends Command
{
    protected $signature = 'bouffe:ciqual
                            {fichier? : chemin du fichier CSV exporté depuis la table Ciqual}
                            {--associer : refaire la correspondance ingrédients → aliments, même pour ceux déjà associés}';

    protected $description = 'Importe la table nutritionnelle Ciqual (Anses) et associe les ingrédients';

    public function handle(CiqualImporter $importer): int
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
        } elseif (! NutritionFood::query()->exists()) {
            $this->components->error('Aucune table importée et aucun fichier indiqué.');
            $this->line('  Téléchargez la table Ciqual sur https://ciqual.anses.fr, enregistrez-la en CSV, puis :');
            $this->line('  <fg=white>php artisan bouffe:ciqual chemin\\vers\\ciqual.csv</>');

            return self::FAILURE;
        }

        /* ---------------------------------------------- Correspondance ingrédients */

        $match = $importer->matchIngredients($this->option('associer'));

        $this->newLine();
        $this->components->twoColumnDetail('Ingrédients associés', $match['matched'].' / '.$match['total']);

        $without = Ingredient::query()->whereNull('ciqual_code')->count();

        if ($without > 0) {
            $this->components->warn("{$without} ingrédient(s) sans aliment associé : complétez-les dans Paramètres → Nutrition.");
        }

        $this->newLine();
        $this->components->info(NutritionFood::count().' aliments disponibles. Les valeurs nutritionnelles apparaissent sur les fiches recettes.');
        $this->newLine();

        return self::SUCCESS;
    }
}
