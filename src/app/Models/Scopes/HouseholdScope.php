<?php

namespace App\Models\Scopes;

use App\Support\CurrentHousehold;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * R29 : une table de foyer ne se lit jamais sans filtre. Sans foyer actif, rien n'est visible.
 */
class HouseholdScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('household_id'), CurrentHousehold::id() ?? 0);
    }
}
