<?php

namespace App\Services\Nutrition;

use App\Models\Ingredient;
use App\Models\NutritionFood;
use App\Support\NameNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import de la table Ciqual de l'Anses (17.4, règle R19).
 *
 * Bouffe ne livre **aucune** donnée nutritionnelle : les valeurs viennent du fichier officiel,
 * téléchargé une fois sur ciqual.anses.fr (ou data.gouv.fr) puis importé ici. Rien n'est
 * inventé, rien n'est estimé, et aucune requête Internet n'est faite ensuite.
 *
 * Le fichier officiel est un tableur : il suffit de l'enregistrer en CSV. L'import est
 * volontairement tolérant, parce que les intitulés de colonnes changent d'une édition à
 * l'autre : les colonnes sont reconnues par mots-clés, et une colonne absente laisse
 * simplement la valeur vide au lieu de faire échouer l'import.
 *
 * Conventions de lecture des valeurs, telles que Ciqual les écrit :
 *   « traces » → 0        « - » ou vide → inconnu (null)        « < 0,5 » → 0,5 (majorant)
 */
class CiqualImporter
{
    /** Mots-clés qui identifient chaque colonne, dans l'ordre de priorité. */
    public const COLUMNS = [
        'ciqual_code' => ['alim_code', 'code aliment', 'code'],
        'name' => ['alim_nom_fr', 'nom de l\'aliment', 'nom aliment', 'libelle', 'nom'],
        'food_group' => ['alim_grp_nom_fr', 'groupe'],
        'energy_kcal' => ['energie, reglement ue', 'energie (kcal', 'energie kcal', 'kcal'],
        'proteins' => ['proteines, n x facteur de jones', 'proteines brutes', 'proteines'],
        'carbs' => ['glucides'],
        'sugars' => ['sucres'],
        'fat' => ['lipides'],
        'saturated_fat' => ['ag satures', 'acides gras satures'],
        'fibres' => ['fibres'],
        'salt' => ['sel chlorure de sodium', 'sel'],
    ];

    /**
     * Importe un fichier CSV et renvoie ce qui a été fait.
     *
     * @return array{imported: int, skipped: int, columns: array<string, string>, missing: list<string>}
     */
    public function importCsv(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Fichier introuvable ou illisible : {$path}");
        }

        $handle = fopen($path, 'rb');

        if (! $handle) {
            throw new RuntimeException("Impossible d'ouvrir {$path}.");
        }

        try {
            $first = fgets($handle);

            if ($first === false) {
                throw new RuntimeException('Le fichier est vide.');
            }

            $separator = $this->detectSeparator($first);
            rewind($handle);

            $header = fgetcsv($handle, 0, $separator);

            if (! $header) {
                throw new RuntimeException('Impossible de lire la ligne d\'en-tête.');
            }

            $map = $this->mapColumns($header);

            foreach (['ciqual_code', 'name'] as $required) {
                if (! isset($map[$required])) {
                    throw new RuntimeException("Colonne « {$required} » introuvable : ce fichier ne ressemble pas à un export Ciqual.");
                }
            }

            $imported = 0;
            $skipped = 0;
            $batch = [];

            while (($row = fgetcsv($handle, 0, $separator)) !== false) {
                $record = $this->rowToRecord($row, $map);

                if ($record === null) {
                    $skipped++;

                    continue;
                }

                $batch[] = $record;
                $imported++;

                if (count($batch) >= 400) {
                    $this->flush($batch);
                    $batch = [];
                }
            }

            $this->flush($batch);

            return [
                'imported' => $imported,
                'skipped' => $skipped,
                'columns' => array_map(fn (int $index) => (string) ($header[$index] ?? ''), $map),
                'missing' => array_values(array_diff(array_keys(self::COLUMNS), array_keys($map))),
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Propose un aliment Ciqual pour chaque ingrédient qui n'en a pas encore.
     *
     * La correspondance est volontairement prudente : nom exact d'abord, puis un aliment dont
     * le nom **commence** par celui de l'ingrédient, en préférant les formes « cru » et en
     * écartant les préparations (« en conserve », « surgelé », « cuit »). Tout est vérifiable
     * et modifiable dans Paramètres → Nutrition.
     *
     * @return array{matched: int, total: int}
     */
    public function matchIngredients(bool $overwrite = false): array
    {
        $ingredients = Ingredient::query()
            ->when(! $overwrite, fn ($query) => $query->whereNull('ciqual_code'))
            ->orderBy('name')
            ->get();

        $matched = 0;

        foreach ($ingredients as $ingredient) {
            $code = $this->suggestFor($ingredient)?->ciqual_code;

            if ($code === null) {
                continue;
            }

            $ingredient->forceFill(['ciqual_code' => $code])->save();
            $matched++;
        }

        return ['matched' => $matched, 'total' => $ingredients->count()];
    }

    /** Meilleur aliment Ciqual pour un ingrédient, ou null si rien de convaincant. */
    public function suggestFor(Ingredient $ingredient): ?NutritionFood
    {
        $name = NameNormalizer::normalize($ingredient->name);

        if ($name === '') {
            return null;
        }

        if ($exact = NutritionFood::query()->where('search_name', $name)->first()) {
            return $exact;
        }

        $candidates = NutritionFood::query()
            ->where('search_name', 'like', $name.'%')
            ->limit(40)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->sortBy(fn (NutritionFood $food) => [
                // « cru » d'abord, préparations ensuite, et à défaut le nom le plus court.
                Str::contains($food->search_name, ['conserve', 'surgele', 'prepare', 'sechee', 'seche', 'confit']) ? 1 : 0,
                Str::contains($food->search_name, ['cru', 'crue', 'frais', 'fraiche']) ? 0 : 1,
                mb_strlen($food->name),
            ])
            ->first();
    }

    /* ================================================================ Lecture du fichier */

    private function detectSeparator(string $line): string
    {
        return substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int> champ => indice de colonne
     */
    public function mapColumns(array $header): array
    {
        $normalized = array_map(fn ($label) => $this->flatten((string) $label), $header);
        $map = [];

        foreach (self::COLUMNS as $field => $keywords) {
            foreach ($keywords as $keyword) {
                $index = $this->findColumn($normalized, $this->flatten($keyword));

                if ($index !== null) {
                    $map[$field] = $index;

                    break;
                }
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $map
     * @return array<string, mixed>|null
     */
    private function rowToRecord(array $row, array $map): ?array
    {
        $code = trim((string) ($row[$map['ciqual_code']] ?? ''));
        $name = trim((string) ($row[$map['name']] ?? ''));

        if ($code === '' || $name === '' || ! preg_match('/^\d+$/', $code)) {
            return null;
        }

        $record = [
            'ciqual_code' => mb_substr($code, 0, 10),
            'name' => mb_substr($name, 0, 250),
            'search_name' => mb_substr(NameNormalizer::normalize($name), 0, 250),
            'food_group' => isset($map['food_group']) ? mb_substr(trim((string) ($row[$map['food_group']] ?? '')), 0, 150) ?: null : null,
            'source' => 'ciqual',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach (array_keys(NutritionFood::NUTRIENTS) as $nutrient) {
            $record[$nutrient] = isset($map[$nutrient]) ? $this->parseValue((string) ($row[$map[$nutrient]] ?? '')) : null;
        }

        return $record;
    }

    /** « 12,4 » → 12.4 · « traces » → 0 · « < 0,5 » → 0.5 · « - » → null */
    public function parseValue(string $raw): ?float
    {
        $value = trim(str_replace(["\u{00A0}", "\u{202F}", ' '], '', $raw));
        $flat = $this->flatten($value);

        if ($value === '' || $flat === '-' || $flat === 'na' || $flat === 'nd') {
            return null;
        }

        if (str_contains($flat, 'trace')) {
            return 0.0;
        }

        $value = ltrim($value, '<>≤≥');
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    /** @param  list<array<string, mixed>>  $batch */
    private function flush(array $batch): void
    {
        if ($batch === []) {
            return;
        }

        DB::table('nutrition_foods')->upsert(
            $batch,
            ['ciqual_code'],
            ['name', 'search_name', 'food_group', 'source', 'updated_at', ...array_keys(NutritionFood::NUTRIENTS)],
        );
    }

    /**
     * @param  list<string>  $columns
     */
    private function findColumn(array $columns, string $keyword): ?int
    {
        foreach ($columns as $index => $label) {
            if ($label !== '' && str_contains($label, $keyword)) {
                return $index;
            }
        }

        return null;
    }

    /** Minuscules sans accents : les intitulés Ciqual changent de casse et d'accents. */
    private function flatten(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }
}
