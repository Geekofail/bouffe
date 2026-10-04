<?php

namespace App\Livewire\Stock;

use App\Models\PlannedMeal;
use App\Services\Stock\MealStockService;
use App\Support\Settings;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Fenêtre « Retirer du stock ? » ouverte quand un repas est marqué mangé (9.8, R9),
 * ou « Remettre dans le stock ? » quand on le décoche.
 *
 * Réglage stock.deduction_mode : ask (fenêtre) · auto (appliqué directement) · never.
 */
class MealStockDialog extends Component
{
    public bool $show = false;

    /** eat · revert */
    #[Locked]
    public string $mode = 'eat';

    #[Locked]
    public ?int $mealId = null;

    #[Locked]
    public string $mealLabel = '';

    /** Proposition calculée côté serveur (non modifiable par le navigateur). */
    #[Locked]
    public array $rows = [];

    #[Locked]
    public ?array $leftovers = null;

    #[Locked]
    public ?array $consumption = null;

    /** Choix de l'utilisateur, par clé de ligne. */
    public array $include = [];

    public array $finishUnknown = [];

    public bool $storeLeftovers = true;

    public int|string $leftoverPortions = 0;

    public bool $consume = true;

    /** Régulariser un retrait laissé en attente (22.2) : la fenêtre s'ouvre, quel que soit le réglage. */
    #[On('settle-meal-stock')]
    public function settle(int $mealId): void
    {
        $this->onMealCooked($mealId, true, forceAsk: true);
    }

    /** « Annuler » du message de retrait automatique : le stock revient, le repas reste à régulariser. */
    #[On('undo-meal-stock')]
    public function undo(int $mealId): void
    {
        $meal = PlannedMeal::find($mealId);

        if (! $meal) {
            return;
        }

        $this->notifyRevert(app(MealStockService::class)->revert($meal));
        $meal->forceFill(['stock_state' => 'pending'])->save();
        $this->dispatch('reminders-changed');
    }

    #[On('meal-cooked')]
    public function onMealCooked(int $mealId, bool $cooked, bool $forceAsk = false): void
    {
        $mode = $forceAsk ? 'ask' : (string) Settings::get('stock.deduction_mode', 'ask');
        $meal = PlannedMeal::with('recipe')->find($mealId);

        if (! $meal || $mode === 'never') {
            return;
        }

        $service = app(MealStockService::class);
        $this->reset('include', 'finishUnknown', 'rows', 'leftovers', 'consumption');
        $this->mealId = $meal->id;
        $this->mealLabel = $meal->label();

        if (! $cooked) {
            if (! $service->hasDeduction($meal)) {
                return;
            }

            $this->mode = 'revert';

            if ($mode === 'auto') {
                $this->notifyRevert($service->revert($meal));

                return;
            }

            $this->show = true;

            return;
        }

        $this->mode = 'eat';
        $this->rows = $service->plan($meal);
        $this->leftovers = $service->leftoversOffer($meal);
        $this->consumption = $service->leftoverConsumption($meal);

        if ($this->rows === [] && ! $this->leftovers && ! $this->consumption) {
            return;
        }

        foreach ($this->rows as $row) {
            $this->include[$row['key']] = $row['include'];
            $this->finishUnknown[$row['key']] = $row['finish_unknown'];
        }

        $this->storeLeftovers = true;
        $this->leftoverPortions = $this->leftovers['portions'] ?? 0;
        $this->consume = true;

        if ($mode === 'auto') {
            $parts = $service->applyDefault($meal);
            $this->mealId = null;

            if ($parts !== []) {
                // Retrait fait sans fenêtre : on le dit, et on laisse la possibilité de revenir en arrière (22.2).
                $this->dispatch('notify', message: 'Stock : '.implode(' · ', $parts).'.', action: ['label' => 'Annuler', 'event' => 'undo-meal-stock', 'params' => ['mealId' => $meal->id]]);
                $this->dispatch('stock-changed');
            }

            return;
        }

        // En attente jusqu'à la réponse : si la page est quittée sans répondre, le repas reste signalé (22.2).
        $meal->forceFill(['stock_state' => 'pending'])->save();
        $this->show = true;
    }

    public function confirm(): void
    {
        $meal = $this->meal();

        if (! $meal) {
            $this->close();

            return;
        }

        $service = app(MealStockService::class);
        $parts = [];

        // Proposition recalculée : le stock a pu changer depuis l'ouverture de la fenêtre.
        $rows = array_map(fn (array $row) => [
            ...$row,
            'include' => (bool) ($this->include[$row['key']] ?? false),
            'finish_unknown' => (bool) ($this->finishUnknown[$row['key']] ?? false),
        ], $service->plan($meal));

        $count = $service->apply($meal, $rows);

        if ($count > 0) {
            $parts[] = $count.' article'.($count > 1 ? 's' : '').' mis à jour';
        }

        if ($this->consumption && $this->consume && $service->consumeLeftovers($meal)) {
            $parts[] = 'restes retirés';
        }

        $portions = max(0.0, round((float) str_replace(',', '.', (string) $this->leftoverPortions) * 2) / 2);

        if ($this->leftovers && $this->storeLeftovers && $portions > 0 && $service->storeLeftovers($meal, $portions)) {
            $parts[] = $portions.' portion'.($portions > 1 ? 's' : '').' au réfrigérateur';
        }

        $meal->forceFill(['stock_state' => 'done'])->save();
        $this->show = false;
        $this->mealId = null;
        $this->dispatch('reminders-changed');

        if ($parts !== []) {
            $this->dispatch('notify', message: 'Stock : '.implode(' · ', $parts).'.');
            $this->dispatch('stock-changed');
        }
    }

    /** « Ne rien retirer » : c'est un choix, le repas n'est plus signalé. */
    public function ignore(): void
    {
        if ($meal = $this->meal()) {
            $meal->forceFill(['stock_state' => 'ignored'])->save();
            $this->dispatch('reminders-changed');
        }

        $this->show = false;
        $this->mealId = null;
    }

    public function confirmRevert(): void
    {
        $meal = $this->meal();
        $this->close();

        if ($meal) {
            $this->notifyRevert(app(MealStockService::class)->revert($meal));
        }
    }

    /**
     * Fenêtre fermée sans répondre (croix, touche Échap, clic à côté) : le retrait n'est pas perdu,
     * le repas est signalé « stock à régulariser » dans la cloche (22.2).
     */
    public function close(): void
    {
        if ($this->show && $this->mode === 'eat' && ($meal = $this->meal()) && $meal->cooked_at) {
            $meal->forceFill(['stock_state' => 'pending'])->save();
            $this->dispatch('reminders-changed');
        }

        $this->show = false;
        $this->mealId = null;
    }

    public function render()
    {
        return view('livewire.stock.meal-stock-dialog');
    }

    private function meal(): ?PlannedMeal
    {
        return $this->mealId ? PlannedMeal::with('recipe')->find($this->mealId) : null;
    }

    /** @param  array{restored: int, skipped: int}  $result */
    private function notifyRevert(array $result): void
    {
        if ($result['restored'] === 0 && $result['skipped'] === 0) {
            return;
        }

        $message = $result['restored'] > 0
            ? 'Stock remis comme avant le repas ('.$result['restored'].' opération'.($result['restored'] > 1 ? 's' : '').').'
            : 'Stock non modifié.';

        if ($result['skipped'] > 0) {
            $message .= ' '.$result['skipped'].' article'.($result['skipped'] > 1 ? 's modifiés' : ' modifié').' depuis : à corriger dans le stock.';
        }

        $this->dispatch('notify', type: $result['skipped'] > 0 ? 'warning' : 'success', message: $message);
        $this->dispatch('stock-changed');
    }
}
