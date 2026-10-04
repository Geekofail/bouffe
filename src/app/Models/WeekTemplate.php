<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Semaine type (14.3, règle R16) : une semaine enregistrée comme modèle, réappliquée plus tard.
 *
 * @property int $id
 * @property string $name
 * @property string|null $notes
 */
#[Fillable(['name', 'notes', 'created_by'])]
class WeekTemplate extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    /** @return HasMany<WeekTemplateMeal, $this> */
    public function meals(): HasMany
    {
        return $this->hasMany(WeekTemplateMeal::class)->orderBy('weekday')->orderBy('meal_slot_id')->orderBy('position');
    }

    public function summary(): string
    {
        $count = $this->meals()->count();

        return $count === 0 ? 'Vide' : $count.' repas';
    }
}
