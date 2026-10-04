<?php

namespace App\Models;

use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un aliment de la table Ciqual (Anses), importé une fois (R19).
 *
 * Bouffe ne livre aucune donnée nutritionnelle : le fichier officiel est téléchargé par
 * l'utilisateur puis importé (`php artisan bouffe:ciqual`). Les valeurs sont pour 100 g.
 *
 * @property string $ciqual_code
 * @property string $name
 * @property string|null $food_group
 * @property string|null $energy_kcal
 */
#[Fillable([
    'ciqual_code', 'name', 'search_name', 'food_group',
    'energy_kcal', 'proteins', 'carbs', 'sugars', 'fat', 'saturated_fat', 'fibres', 'salt', 'source',
])]
class NutritionFood extends Model
{
    /** Le pluriel français n'est pas deviné par Eloquent : on le fixe. */
    protected $table = 'nutrition_foods';

    /** Les constituants retenus, avec leur libellé et leur unité. */
    public const NUTRIENTS = [
        'energy_kcal' => ['Énergie', 'kcal'],
        'proteins' => ['Protéines', 'g'],
        'carbs' => ['Glucides', 'g'],
        'sugars' => ['dont sucres', 'g'],
        'fat' => ['Lipides', 'g'],
        'saturated_fat' => ['dont saturés', 'g'],
        'fibres' => ['Fibres', 'g'],
        'salt' => ['Sel', 'g'],
    ];

    protected function casts(): array
    {
        return [
            'energy_kcal' => 'float',
            'proteins' => 'float',
            'carbs' => 'float',
            'sugars' => 'float',
            'fat' => 'float',
            'saturated_fat' => 'float',
            'fibres' => 'float',
            'salt' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (NutritionFood $food) {
            $food->search_name = NameNormalizer::normalize($food->name);
        });
    }

    public function scopeSearch(Builder $query, string $text): Builder
    {
        $normalized = NameNormalizer::normalize($text);

        return $normalized === '' ? $query : $query->where('search_name', 'like', '%'.$normalized.'%');
    }

    /** Nom sans la partie descriptive après la virgule : « Carotte, crue » → « Carotte ». */
    public function shortName(): string
    {
        return trim(explode(',', $this->name)[0]);
    }
}
