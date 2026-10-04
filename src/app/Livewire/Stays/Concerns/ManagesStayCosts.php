<?php

namespace App\Livewire\Stays\Concerns;

use App\Models\Stay;
use App\Services\Stays\StayCosts;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Attributes\Computed;

/**
 * Séjour — frais partagés (34.3, R37) : qui a avancé quoi, la part de chacun, les remboursements.
 */
trait ManagesStayCosts
{
    public string $expenseGroup = '';

    public string $expenseLabel = '';

    public string $expenseAmount = '';

    public string $expenseDate = '';

    public function setSplitMode(string $mode): void
    {
        if (! isset(Stay::SPLIT_MODES[$mode]) || ! $this->organizerOnly()) {
            return;
        }

        $this->stay->update(['split_mode' => $mode]);
        unset($this->costs);
    }

    public function addExpense(StayCosts $costs): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->validate([
            'expenseGroup' => 'required|string|max:60',
            'expenseLabel' => 'nullable|string|max:150',
            'expenseAmount' => 'required|string|max:20',
            'expenseDate' => 'nullable|date',
        ], [], ['expenseGroup' => 'qui a payé', 'expenseLabel' => 'quoi', 'expenseAmount' => 'montant', 'expenseDate' => 'date']);

        try {
            $costs->addExpense($this->stay, $this->expenseGroup, $this->expenseLabel, $this->expenseAmount, $this->expenseDate ?: null);
        } catch (InvalidArgumentException $e) {
            $this->addError('expenseAmount', $e->getMessage());

            return;
        }

        $this->reset('expenseLabel', 'expenseAmount');
        $this->stay->unsetRelation('payments');
        unset($this->costs);
    }

    public function removePayment(int $paymentId): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $payment = $this->stay->payments()->find($paymentId);

        // Lot 42 (42.1) : chacun retire ses lignes ; l'organisateur, toutes.
        if (! $payment || ! app(\App\Services\Stays\StayCoorganizers::class)->canManage($payment->household_id, $this->stay)) {
            return;
        }

        $payment->delete();
        $this->stay->unsetRelation('payments');
        unset($this->costs);
    }

    public function settle(string $from, string $to, string $amount, StayCosts $costs): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        // Seul un remboursement proposé peut être coché.
        $proposed = collect($this->costs['transfers'])->first(fn ($t) => $t['from'] === $from && $t['to'] === $to && abs($t['amount'] - (float) $amount) < 0.005);

        if (! $proposed) {
            return;
        }

        $costs->settle($this->stay, $from, $to, $proposed['amount']);
        $this->stay->unsetRelation('payments');
        unset($this->costs);
        $this->dispatch('notify', message: $from.' → '.$to.' : noté comme remboursé.');
    }

    #[Computed]
    public function costs(): array
    {
        return app(StayCosts::class)->summary($this->stay);
    }

    public function defaultExpenseDate(): string
    {
        return Carbon::today()->min($this->stay->ends_on)->max($this->stay->starts_on)->toDateString();
    }
}
