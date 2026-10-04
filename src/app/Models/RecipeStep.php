<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $recipe_id
 * @property int $position
 * @property string|null $group_name
 * @property string $instruction
 * @property string|null $uid identifiant stable (lot 40) : notes et photos suivent l'étape
 * @property bool|null $adult_help demande un adulte (39.4) ; vide : d'après les mots de l'étape
 */
#[Fillable(['uid', 'position', 'group_name', 'instruction', 'adult_help'])]
class RecipeStep extends Model
{
    protected function casts(): array
    {
        return ['position' => 'integer', 'adult_help' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (RecipeStep $step) {
            $step->uid ??= self::newUid();
        });
    }

    public static function newUid(): string
    {
        return \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10));
    }
}
