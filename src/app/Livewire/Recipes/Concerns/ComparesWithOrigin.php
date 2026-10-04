<?php

namespace App\Livewire\Recipes\Concerns;

use App\Models\Recipe;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Fiche recette — entre foyers (lot 26) : copier une recette de proche, reprendre l'original,
 * avis des proches (découpé de Show au lot 36).
 */
trait ComparesWithOrigin
{
    /** Copier une recette d'un proche dans notre carnet (26.2, R31). */
    public function copyToMine(\App\Services\Linked\RecipeCopier $copier): void
    {
        abort_unless($this->recipe->isForeign() && auth()->user()->canEdit(), 403);

        if ($existing = $copier->existingCopy($this->recipe)) {
            $this->redirectRoute('recipes.show', ['recipe' => $existing], navigate: true);

            return;
        }

        $copy = $copier->copy($this->recipe, auth()->user());
        session()->flash('status', 'Recette copiée dans votre carnet : vous pouvez la modifier.');
        $this->redirectRoute('recipes.show', ['recipe' => $copy], navigate: true);
    }

    /** Reprendre une partie de l'original mis à jour (R31). */
    public function applyOrigin(string $section, \App\Services\Linked\RecipeCopier $copier): void
    {
        abort_if($this->recipe->isForeign() || ! auth()->user()->canEdit(), 403);
        abort_unless(array_key_exists($section, \App\Services\Linked\RecipeCopier::SECTIONS), 404);

        $copier->apply($this->recipe, [$section]);
        $this->recipe->refresh();
        unset($this->originInfo, $this->ingredientGroups);
        $this->dispatch('notify', message: 'Repris de l\'original : '.mb_strtolower(\App\Services\Linked\RecipeCopier::SECTIONS[$section]).'.');
    }

    public function dismissOrigin(\App\Services\Linked\RecipeCopier $copier): void
    {
        abort_if($this->recipe->isForeign() || ! auth()->user()->canEdit(), 403);

        $copier->dismiss($this->recipe);
        $this->recipe->refresh();
        $this->showOriginDiff = false;
        unset($this->originInfo);
    }

    /**
     * Origine d'une copie : foyer, date, original modifié depuis ? (R31)
     *
     * @return array{household: string|null, synced: \Illuminate\Support\Carbon|null, available: bool, updated: bool, diff: array|null}|null
     */
    #[Computed]
    public function originInfo(): ?array
    {
        if (! $this->recipe->origin_recipe_id && ! $this->recipe->origin_household_id) {
            return null;
        }

        $copier = app(\App\Services\Linked\RecipeCopier::class);
        $origin = $copier->origin($this->recipe);
        $updated = $origin && $copier->hasUpdate($this->recipe);

        return [
            'household' => $this->recipe->originHousehold?->name,
            'synced' => $this->recipe->origin_synced_at,
            'available' => $origin !== null,
            'origin' => $origin,
            'updated' => $updated,
            'diff' => $updated && $this->showOriginDiff ? $copier->diff($this->recipe) : null,
        ];
    }

    /** Pour une recette d'un proche : notre copie, s'il y en a une. */
    #[Computed]
    public function myCopy(): ?Recipe
    {
        return $this->recipe->isForeign() ? app(\App\Services\Linked\RecipeCopier::class)->existingCopy($this->recipe) : null;
    }

    /** Avis laissés par les proches sur notre recette (26.3). */
    #[Computed]
    public function linkedReviews(): Collection
    {
        if ($this->recipe->isForeign()) {
            return collect();
        }

        return $this->recipe->ratings()->whereNotNull('household_id')->where('household_id', '!=', $this->recipe->household_id)
            ->with('user', 'household')->latest('updated_at')->get();
    }
}
