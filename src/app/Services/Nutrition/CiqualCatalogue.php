<?php

namespace App\Services\Nutrition;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\Unit;
use App\Support\NameNormalizer;
use Database\Seeders\AisleSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Le catalogue d'ingrédients livré avec Bouffe (lot 43) : ~1 100 ingrédients, chacun avec son
 * aliment de la table Ciqual 2025, son rayon et son unité.
 *
 * Fichier : database/data/catalogue-ciqual.csv, produit par outils/ciqual/catalogue.py.
 *
 * L'application est prudente avec ce qui existe déjà :
 *  - un ingrédient du même nom (au pluriel ou sans accent près) garde son nom, son rayon, son
 *    unité, ses réglages ; seul son aliment Ciqual est posé, et seulement s'il n'a pas été
 *    choisi à la main (ciqual_source = manuel). Densité et poids d'une pièce sont complétés
 *    s'ils manquent ;
 *  - un ingrédient livré par une version précédente du catalogue et supprimé depuis n'est pas
 *    recréé : seuls les noms nouveaux d'une version à l'autre sont ajoutés.
 */
class CiqualCatalogue
{
    public const PATH = 'database/data/catalogue-ciqual.csv';

    /** @var array<string, array<string, array<string, mixed>>> fichier => (nom normalisé => ligne), lu une fois par processus */
    private static array $cache = [];

    public function path(): string
    {
        return base_path(self::PATH);
    }

    /** Empreinte du fichier : change dès que le catalogue change. */
    public function version(): string
    {
        return substr(sha1_file($this->path()) ?: '', 0, 16);
    }

    /**
     * @return array<string, array{code: string, name: string, plural: ?string, aisle: string, unit: string, piece_weight: ?float, density: ?float, staple: bool}>
     */
    public function rows(): array
    {
        $path = $this->path();

        if (isset(self::$cache[$path])) {
            return self::$cache[$path];
        }

        if (! is_readable($path)) {
            throw new RuntimeException("Catalogue introuvable : {$path}");
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle, 0, ';');
        $rows = [];

        while (($line = fgetcsv($handle, 0, ';')) !== false) {
            $row = array_combine($header, array_pad($line, count($header), ''));
            $key = NameNormalizer::normalize($row['nom']);

            $rows[$key] ??= [
                'code' => $row['code'],
                'name' => $row['nom'],
                'plural' => $row['pluriel'] !== '' ? $row['pluriel'] : null,
                'aisle' => $row['rayon'],
                'unit' => $row['unite'],
                'piece_weight' => $row['poids_piece'] !== '' ? (float) $row['poids_piece'] : null,
                'density' => $row['densite'] !== '' ? (float) $row['densite'] : null,
                'staple' => $row['de_base'] === '1',
            ];
        }

        fclose($handle);

        return self::$cache[$path] = $rows;
    }

    /** Code Ciqual du catalogue pour un nom déjà normalisé, ou null. */
    public function codeFor(string $normalized): ?string
    {
        try {
            return $this->rows()[$normalized]['code'] ?? null;
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Pose les aliments Ciqual sur les ingrédients connus et ajoute les ingrédients manquants.
     *
     * @param  list<string>  $previous  noms (normalisés) livrés par la version déjà appliquée
     * @return array{created: int, linked: int, kept: int, names: list<string>}
     */
    public function apply(array $previous = []): array
    {
        $rows = $this->rows();
        $aisles = $this->aisleIds();
        $units = Unit::query()->pluck('id', 'code');
        $previous = array_flip($previous);

        $existing = Ingredient::query()
            ->get(['id', 'name', 'search_name', 'ciqual_code', 'ciqual_source', 'density', 'piece_weight_g'])
            ->keyBy('search_name');

        $created = 0;
        $linked = 0;
        $kept = 0;

        DB::transaction(function () use ($rows, $aisles, $units, $previous, $existing, &$created, &$linked, &$kept) {
            foreach ($rows as $key => $row) {
                $ingredient = $existing->get($key);

                if ($ingredient) {
                    if ($ingredient->ciqual_source === 'manuel') {
                        $kept++;

                        continue;
                    }

                    $changes = [];

                    if ($ingredient->ciqual_code !== $row['code'] || $ingredient->ciqual_source !== 'catalogue') {
                        $changes += ['ciqual_code' => $row['code'], 'ciqual_source' => 'catalogue'];
                    }

                    // Densité et poids d'une pièce : seulement s'ils manquent, jamais à la place d'une valeur saisie.
                    if ($ingredient->density === null && $row['density'] !== null) {
                        $changes['density'] = $row['density'];
                    }

                    if ($ingredient->piece_weight_g === null && $row['piece_weight'] !== null) {
                        $changes['piece_weight_g'] = $row['piece_weight'];
                    }

                    if ($changes !== []) {
                        // Écriture directe : le catalogue commun, sans passer par les réglages du foyer.
                        DB::table('ingredients')->where('id', $ingredient->id)->update($changes + ['updated_at' => now()]);
                        $linked++;
                    }

                    continue;
                }

                // Livré par une version précédente puis supprimé : on respecte la suppression.
                if (isset($previous[$key])) {
                    continue;
                }

                $ingredient = new Ingredient([
                    'name' => $row['name'],
                    'name_plural' => $row['plural'],
                    'aisle_id' => $aisles[$row['aisle']] ?? $aisles['misc'] ?? array_values($aisles)[0],
                    'default_unit_id' => $units[$row['unit']] ?? null,
                    'piece_weight_g' => $row['piece_weight'],
                    'is_staple' => $row['staple'],
                    'density' => $row['density'],
                ]);
                $ingredient->forceFill(['ciqual_code' => $row['code'], 'ciqual_source' => 'catalogue'])->save();
                $created++;
            }
        });

        return ['created' => $created, 'linked' => $linked, 'kept' => $kept, 'names' => array_keys($rows)];
    }

    /** @return array<string, int> clé de rayon (AisleSeeder) => id */
    private function aisleIds(): array
    {
        $byName = Aisle::query()->pluck('id', 'name');
        $ids = [];

        foreach (AisleSeeder::AISLES as $key => [$name]) {
            if (isset($byName[$name])) {
                $ids[$key] = $byName[$name];
            }
        }

        if ($ids === []) {
            // Rayons renommés ou supprimés : on les recrée plutôt que d'échouer.
            (new AisleSeeder)->run();

            return $this->aisleIds();
        }

        return $ids;
    }
}
