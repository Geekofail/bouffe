<?php

namespace App\Livewire\Planner;

use App\Models\PlannedMeal;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Planning de la semaine')]
class Week extends Component
{
    use \App\Livewire\Concerns\OffersUndo;
    use \App\Support\Concerns\RequiresFullAccess;
    use Concerns\ManagesMealDetail;
    use Concerns\ManagesWholeWeek;
    use Concerns\OffersAdjustments;
    use Concerns\ProvidesWeekData;

    /** Lundi de la semaine affichée (AAAA-MM-JJ). */
    #[Url(as: 'semaine', except: '')]
    public string $week = '';

    /** Affichage : grille (par défaut) ou liste, plus confortable sur téléphone (14.9). */
    #[Url(as: 'vue', except: 'grille')]
    public string $view = 'grille';

    /**
     * Lot 28 (28.3) : sur téléphone, un jour à la fois. Index 0 (lundi) à 6 ; -1 = automatique
     * (aujourd'hui dans la semaine en cours, sinon lundi). Changé côté navigateur sans aller-retour
     * (`$wire.$set(..., false)`), transmis avec la requête suivante.
     */
    public int $day = -1;

    /** Sur téléphone : toute la semaine plutôt qu'un jour à la fois. */
    public bool $allDays = false;

    /** Ne mettre en avant que les repas que je cuisine (14.7). */
    #[Url(as: 'mes_repas', except: false)]
    public bool $mineOnly = false;

    /* ---------------------------------------------------------- Détail d'un repas */

    public ?int $selectedMealId = null;

    public int|float|string $editServings = 2;

    public string $editComment = '';

    /** Qui cuisine (14.7) : '' = personne · 'together' = ensemble · identifiant du membre sinon. */
    public string $editCook = '';

    /* ---------------------------------------------------------- Proposition de restes */

    /** @var array{meal_id: int, date: string, slot_id: int, slot: string, servings: int}|null */
    public ?array $leftoverOffer = null;

    /* ---------------------------------------------------------- Proposition d'adapter les portions */

    /** @var array{date: string, slot_id: int, slot: string, before: int, after: int, count: int}|null */
    public ?array $servingsOffer = null;

    /* ---------------------------------------------------------- Copie de semaine */

    public bool $showCopy = false;

    public string $copyTarget = '';

    /** add | replace */
    public string $copyMode = 'add';

    public function mount(): void
    {
        // Ajout rapide (bouton « + ») : /planning?ajouter=2026-09-17&creneau=2 ouvre directement la case.
        $add = (string) request()->query('ajouter', '');
        $slotId = request()->integer('creneau');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $add) && strtotime($add) !== false && \App\Models\MealSlot::whereKey($slotId)->exists()) {
            $this->week = $this->planner()->weekStart($add)->toDateString();
            $this->day = (int) $this->weekStart()->diffInDays(Carbon::parse($add));
            $this->dispatch('open-meal-picker', date: $add, slotId: $slotId)->to(MealPicker::class);

            return;
        }

        // Un repas précis (QR code d'une étiquette de gamelle, 32.3) : /planning?repas=12.
        $meal = request()->integer('repas') ? PlannedMeal::find(request()->integer('repas')) : null;

        if ($meal) {
            $this->week = $this->planner()->weekStart($meal->date)->toDateString();
            $this->day = (int) $this->weekStart()->diffInDays($meal->date);
            $this->selectMeal($meal->id);

            return;
        }

        $this->week = $this->weekStart()->toDateString();
    }

    private function planner(): WeekPlanner
    {
        return app(WeekPlanner::class);
    }

    public function weekStart(): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->week) && strtotime($this->week) !== false) {
            return $this->planner()->weekStart($this->week);
        }

        return $this->planner()->weekStart();
    }

    /* ================================================================ Navigation */

    public function previousWeek(): void
    {
        $this->goTo($this->weekStart()->subWeek());
    }

    public function nextWeek(): void
    {
        $this->goTo($this->weekStart()->addWeek());
    }

    public function currentWeek(): void
    {
        $this->goTo($this->planner()->weekStart());
    }

    /** Jour montré sur téléphone (0 = lundi). */
    public function dayIndex(): int
    {
        if ($this->day >= 0 && $this->day <= 6) {
            return $this->day;
        }

        $today = Carbon::today();
        $start = $this->weekStart();

        return $today->betweenIncluded($start, $start->copy()->addDays(6)) ? (int) $start->diffInDays($today) : 0;
    }

    /** Balayage au-delà du dimanche ou avant le lundi : semaine suivante ou précédente. */
    public function shiftDay(int $direction): void
    {
        $direction >= 0 ? $this->nextWeek() : $this->previousWeek();
        $this->day = $direction >= 0 ? 0 : 6;
    }

    public function toggleAllDays(): void
    {
        $this->allDays = ! $this->allDays;
    }

    private function goTo(Carbon $weekStart): void
    {
        $this->week = $weekStart->toDateString();
        $this->day = -1;
        $this->reset('selectedMealId', 'leftoverOffer', 'servingsOffer', 'showCopy');
        unset($this->meals, $this->occasions, $this->mealConflicts);
    }

    /* ================================================================ Ajout (sélecteur) */

    /* ================================================================ Convives */

    /* ================================================================ Glisser-déposer */

    /* ================================================================ Détail d'un repas */

    /* ================================================================ Semaine entière */

    /* ================================================================ Données */

    /**
     * Exécute une action métier ; une règle non respectée devient une notification.
     */
    private function attempt(callable $action, ?string $errorField = null): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            $errorField
                ? $this->addError($errorField, $e->getMessage())
                : $this->dispatch('notify', type: 'warning', message: $e->getMessage());
        } finally {
            unset($this->meals, $this->selectedMeal);
        }
    }

    public function toggleMine(): void
    {
        $this->mineOnly = ! $this->mineOnly;
    }

    /** Ce repas est-il « à moi » (moi, ou ensemble) ? */
    public function isMine(PlannedMeal $meal): bool
    {
        return $meal->cook_together || (int) $meal->cook_user_id === (int) auth()->id();
    }

    public function toggleView(): void
    {
        $this->view = $this->view === 'liste' ? 'grille' : 'liste';
    }

    /** Après « Annuler » (lot 30) : la semaine est relue. */
    #[On('bouffe-undone')]
    public function afterUndo(): void
    {
        $this->reset('selectedMealId', 'leftoverOffer', 'servingsOffer');
        unset($this->meals, $this->mealConflicts, $this->occasions, $this->selectedMeal);
    }

    public function render()
    {
        $weekStart = $this->weekStart();

        return view('livewire.planner.week', [
            'weekStart' => $weekStart,
            'days' => $this->planner()->days($weekStart),
            'isCurrentWeek' => $weekStart->equalTo($this->planner()->weekStart()),
            'today' => Carbon::today()->toDateString(),
            'dayIndex' => $this->dayIndex(),
            'weekLunchboxes' => $this->meals->flatten(1)->filter(fn (PlannedMeal $meal) => $meal->isLunchbox())->count(),
            'tableSummary' => app(\App\Services\Planning\OccasionService::class)->summary(null),
            'guestMealsCount' => $this->occasions->filter(fn ($o) => $o->guestCount() > 0)->count(),
            // Coût estimé de la semaine (17.2, règle R17).
            'weekCost' => app(\App\Services\Pricing\CostCalculator::class)->week($weekStart, $weekStart->copy()->addDays(6)),
            // Équilibre de la semaine (17.5) : des jauges, pas des notes.
            'balance' => app(\App\Services\Planning\WeekBalance::class)->week($weekStart, $weekStart->copy()->addDays(6)),
            'prices' => app(\App\Services\Pricing\PriceBook::class),
        ]);
    }
}
