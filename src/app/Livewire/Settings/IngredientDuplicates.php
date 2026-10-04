<?php

namespace App\Livewire\Settings;

use App\Models\Ingredient;
use App\Models\IngredientMerge;
use App\Services\Ingredients\IngredientMerger;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Paramètres → Doublons d'ingrédients (12.6, R22) : doublons probables, fusion manuelle, annulation.
 */
#[Title('Doublons d\'ingrédients')]
class IngredientDuplicates extends Component
{
    use \App\Support\Concerns\GuardsCatalog;

    #[Url(as: 'source', except: null)]
    public ?int $sourceId = null;

    public ?int $targetId = null;

    /** Fenêtre de confirmation ouverte. */
    public bool $confirming = false;

    public function prepare(int $sourceId, int $targetId): void
    {
        $this->sourceId = $sourceId;
        $this->targetId = $targetId;
        $this->review();
    }

    public function review(): void
    {
        $this->resetErrorBag();

        if (! $this->sourceId || ! $this->targetId || $this->sourceId === $this->targetId) {
            $this->addError('targetId', 'Choisissez deux ingrédients différents.');

            return;
        }

        $this->confirming = Ingredient::whereKey([$this->sourceId, $this->targetId])->count() === 2;
    }

    public function cancel(): void
    {
        $this->confirming = false;
    }

    public function merge(IngredientMerger $merger): void
    {
        if (! $this->catalogEditable()) {
            return;
        }

        $source = Ingredient::find($this->sourceId);
        $target = Ingredient::find($this->targetId);
        $this->confirming = false;

        if (! $source || ! $target) {
            return;
        }

        try {
            $merger->merge($source, $target);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        $this->reset('sourceId', 'targetId');
        unset($this->duplicates, $this->ingredients, $this->lastMerge);
        $this->dispatch('notify', message: "« {$source->name} » fusionné dans « {$target->name} ». L'ancien nom reste reconnu.");
    }

    public function ignore(int $a, int $b, IngredientMerger $merger): void
    {
        if (! $this->catalogEditable()) {
            return;
        }

        $merger->ignore($a, $b);
        unset($this->duplicates);
    }

    public function undo(IngredientMerger $merger): void
    {
        if (! $this->catalogEditable()) {
            return;
        }

        $merge = $this->lastMerge;

        if (! $merge) {
            return;
        }

        try {
            $ingredient = $merger->undo($merge);
            $this->dispatch('notify', message: "Fusion annulée : « {$ingredient->name} » est de retour.");
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());
        }

        unset($this->duplicates, $this->ingredients, $this->lastMerge);
    }

    #[Computed]
    public function duplicates(): Collection
    {
        return app(IngredientMerger::class)->duplicates();
    }

    #[Computed]
    public function ingredients(): Collection
    {
        return Ingredient::query()->ordered()->get(['id', 'name']);
    }

    #[Computed]
    public function lastMerge(): ?IngredientMerge
    {
        return app(IngredientMerger::class)->lastUndoable()?->load('target', 'user');
    }

    /** Nombre de recettes / articles en stock par ingrédient, pour choisir lequel garder. */
    public function usage(Ingredient $ingredient): string
    {
        $impact = app(IngredientMerger::class)->impact($ingredient);

        return collect([
            $impact['recipes'] ? $impact['recipes'].' recette'.($impact['recipes'] > 1 ? 's' : '') : null,
            $impact['stock'] ? $impact['stock'].' en stock' : null,
            $impact['lists'] ? $impact['lists'].' liste'.($impact['lists'] > 1 ? 's' : '') : null,
        ])->filter()->join(' · ') ?: 'pas utilisé';
    }

    public function render()
    {
        $source = $this->confirming ? Ingredient::find($this->sourceId) : null;

        return view('livewire.settings.ingredient-duplicates', [
            'source' => $source,
            'target' => $this->confirming ? Ingredient::find($this->targetId) : null,
            'impact' => $source ? app(IngredientMerger::class)->impact($source) : null,
        ]);
    }
}
