<?php

namespace Database\Seeders;

use App\Enums\UnitType;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Unités de mesure de départ. Ré-exécutable : n'écrase pas les unités modifiées par l'utilisateur.
 */
class UnitSeeder extends Seeder
{
    public const UNITS = [
        // code,      libellé,          pluriel,        type,               facteur, métrique
        ['g',        'g',              null,           UnitType::Mass,     1,       true],
        ['kg',       'kg',             null,           UnitType::Mass,     1000,    true],
        ['ml',       'ml',             null,           UnitType::Volume,   1,       true],
        ['cl',       'cl',             null,           UnitType::Volume,   10,      true],
        ['l',        'l',              null,           UnitType::Volume,   1000,    true],
        ['cc',       'c. à café',      'c. à café',    UnitType::Volume,   5,       false],
        ['cs',       'c. à soupe',     'c. à soupe',   UnitType::Volume,   15,      false],
        ['pincee',   'pincée',         'pincées',      UnitType::Other,    null,    false],
        ['piece',    'pièce',          'pièces',       UnitType::Piece,    null,    false],
        ['tranche',  'tranche',        'tranches',     UnitType::Piece,    null,    false],
        ['gousse',   'gousse',         'gousses',      UnitType::Piece,    null,    false],
        ['botte',    'botte',          'bottes',       UnitType::Piece,    null,    false],
        ['brin',     'brin',           'brins',        UnitType::Piece,    null,    false],
        ['sachet',   'sachet',         'sachets',      UnitType::Other,    null,    false],
        ['boite',    'boîte',          'boîtes',       UnitType::Other,    null,    false],
        ['pot',      'pot',            'pots',         UnitType::Other,    null,    false],
        ['portion',  'portion',        'portions',     UnitType::Piece,    null,    false],
    ];

    public function run(): void
    {
        foreach (self::UNITS as $index => [$code, $label, $plural, $type, $factor, $metric]) {
            Unit::firstOrCreate(['code' => $code], [
                'label' => $label,
                'label_plural' => $plural,
                'type' => $type,
                'factor_to_base' => $factor,
                'is_metric' => $metric,
                'sort_order' => $index + 1,
            ]);
        }
    }
}
