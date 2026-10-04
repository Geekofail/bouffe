<?php

namespace App\Livewire\Settings;

use App\Support\Settings;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Réglages du stock')]
class StockSettings extends Component
{
    public int|string $dlcSoonDays = 3;

    public int|string $ddmSoonDays = 7;

    public bool $navBadge = true;

    public bool $planningBanner = true;

    /** ask · auto · never (repas mangé → stock) */
    public string $deductionMode = 'ask';

    /** Clôture des repas passés (R23) : 0 = demander, sinon marquer mangé au bout de N jours. */
    public int|string $autoCloseDays = 0;

    /** Retrait au fil du mode cuisine (22.6). */
    public bool $cookModeLive = false;

    public function mount(): void
    {
        $this->dlcSoonDays = Settings::int('stock.dlc_soon_days', 3);
        $this->ddmSoonDays = Settings::int('stock.ddm_soon_days', 7);
        $this->navBadge = Settings::bool('stock.nav_badge', true);
        $this->planningBanner = Settings::bool('stock.planning_banner', true);
        $this->deductionMode = (string) Settings::get('stock.deduction_mode', 'ask');
        $this->autoCloseDays = Settings::int('stock.auto_close_days', 0);
        $this->cookModeLive = Settings::bool('stock.cook_mode_live', false);
    }

    public function save(): void
    {
        $this->validate([
            'dlcSoonDays' => 'required|integer|min:1|max:14',
            'ddmSoonDays' => 'required|integer|min:1|max:60',
            'deductionMode' => 'required|in:ask,auto,never',
            'autoCloseDays' => 'required|integer|min:0|max:7',
        ], [], ['dlcSoonDays' => 'délai DLC', 'ddmSoonDays' => 'délai DDM']);

        Settings::set('stock.dlc_soon_days', $this->dlcSoonDays);
        Settings::set('stock.ddm_soon_days', $this->ddmSoonDays);
        Settings::set('stock.nav_badge', $this->navBadge);
        Settings::set('stock.planning_banner', $this->planningBanner);
        Settings::set('stock.deduction_mode', $this->deductionMode);
        Settings::set('stock.auto_close_days', $this->autoCloseDays);
        Settings::set('stock.cook_mode_live', $this->cookModeLive);

        $this->dispatch('notify', message: 'Réglages du stock enregistrés.');
    }

    /* ================================================================ Consommations régulières (22.4) */

    public string $ruleText = '';

    public int|string $ruleEvery = 1;

    /** « 1 l de lait » tous les N jours */
    public function addRule(\App\Services\Stock\QuickAddParser $parser): void
    {
        $this->validate(['ruleText' => 'required|string|max:100', 'ruleEvery' => 'required|integer|min:1|max:60'], [], ['ruleText' => 'consommation', 'ruleEvery' => 'fréquence']);

        $parsed = $parser->parse($this->ruleText);
        $parsed['ingredient'] ??= app(\App\Services\Stock\StockUsage::class)->resolve($parsed['name']);

        if (! $parsed['ingredient'] || $parsed['quantity'] === null) {
            $this->addError('ruleText', 'Indiquez une quantité et un ingrédient connu, par exemple « 1 l de lait ».');

            return;
        }

        \App\Models\StockUsageRule::updateOrCreate(['ingredient_id' => $parsed['ingredient']->id], [
            'quantity' => $parsed['quantity'],
            'unit_id' => ($parsed['unit'] ?? $parsed['ingredient']->defaultUnit)?->id,
            'every_days' => (int) $this->ruleEvery,
            'last_applied_on' => Carbon::today()->toDateString(),
            'is_active' => true,
        ]);

        $this->reset('ruleText', 'ruleEvery');
        $this->dispatch('notify', message: 'Consommation régulière enregistrée : elle sera retirée du stock chaque jour concerné.');
    }

    public function removeRule(int $ruleId): void
    {
        \App\Models\StockUsageRule::whereKey($ruleId)->delete();
    }

    public function render()
    {
        $today = Carbon::today();

        return view('livewire.settings.stock-settings', [
            'rules' => \App\Models\StockUsageRule::query()->with('ingredient', 'unit')->get()->sortBy('ingredient.name'),
            'dlcExample' => $today->copy()->addDays(max(1, (int) $this->dlcSoonDays))->locale('fr')->isoFormat('dddd D MMMM'),
            'ddmExample' => $today->copy()->addDays(max(1, (int) $this->ddmSoonDays))->locale('fr')->isoFormat('dddd D MMMM'),
        ]);
    }
}
