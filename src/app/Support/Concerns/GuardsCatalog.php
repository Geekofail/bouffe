<?php

namespace App\Support\Concerns;

use App\Models\Ingredient;

/**
 * Catalogue commun (lot 24, R30) : unités, rayons, ingrédients, saisons et nutrition sont partagés
 * par tous les foyers ; avec plusieurs foyers, seul l'administrateur de l'installation les modifie.
 *
 *     if (! $this->catalogEditable()) { return; }
 */
trait GuardsCatalog
{
    protected function catalogEditable(): bool
    {
        if (! Ingredient::catalogLocked()) {
            return true;
        }

        $this->dispatch('notify', type: 'warning', message: 'Ce catalogue est commun à tous les foyers de l\'installation : seul son administrateur peut le modifier.');

        return false;
    }
}
