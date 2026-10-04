<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/** Unicité dans le foyer actif (lot 24) : deux foyers peuvent avoir chacun un magasin « Cactus ». */
final class HouseholdRule
{
    public static function unique(string $table, string $column): Unique
    {
        return Rule::unique($table, $column)->where('household_id', CurrentHousehold::id() ?? 0);
    }
}
