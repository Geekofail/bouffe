<?php

namespace App\Livewire\Planner;

use App\Models\MealSlot;
use App\Models\WeekTemplate;
use App\Services\Planning\WeekPlanner;
use App\Services\Planning\WeekTemplateManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Semaines types (14.3, règle R16) : enregistrer une semaine comme modèle et la réappliquer.
 */
#[Title('Semaines types')]
class Templates extends Component
{
    #[Url(as: 'semaine', except: '')]
    public string $week = '';

    #[Validate('required|string|max:80', as: 'nom')]
    public string $name = '';

    #[Validate('nullable|string|max:500', as: 'note')]
    public string $notes = '';

    public bool $showSave = false;

    /** Modèle dont on prépare l'application. */
    public ?int $applyingId = null;

    public string $mode = 'fill';

    /** @var array{placed: int, skipped: list<string>, warnings: list<string>, removed: int}|null */
    public ?array $report = null;

    public function weekStart(): Carbon
    {
        return app(WeekPlanner::class)->weekStart($this->week ?: null);
    }

    /* ---------------------------------------------------------------- Enregistrer */

    public function openSave(): void
    {
        $this->reset('name', 'notes');
        $this->resetErrorBag();
        $this->showSave = true;
    }

    public function save(WeekTemplateManager $manager): void
    {
        $this->validate();
        $this->validate(['name' => [\App\Support\HouseholdRule::unique('week_templates', 'name')]], [], ['name' => 'nom']);

        try {
            $template = $manager->saveFromWeek($this->weekStart(), $this->name, trim($this->notes) ?: null);
        } catch (InvalidArgumentException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->showSave = false;
        $this->reset('name', 'notes');
        unset($this->templates);
        $this->dispatch('notify', message: "Semaine type « {$template->name} » enregistrée.");
    }

    /* ---------------------------------------------------------------- Appliquer */

    public function openApply(int $templateId): void
    {
        $this->applyingId = $templateId;
        $this->mode = 'fill';
        $this->report = null;
    }

    public function closeApply(): void
    {
        $this->reset('applyingId', 'report');
    }

    public function apply(WeekTemplateManager $manager): void
    {
        $template = WeekTemplate::findOrFail($this->applyingId);
        $this->report = $manager->apply($template, $this->weekStart(), $this->mode);

        $this->dispatch('notify', message: $this->report['placed'].' repas placé'.($this->report['placed'] > 1 ? 's' : '').'.');
    }

    public function delete(int $templateId): void
    {
        WeekTemplate::findOrFail($templateId)->delete();
        unset($this->templates);
        $this->dispatch('notify', message: 'Semaine type supprimée.');
    }

    /* ---------------------------------------------------------------- Données */

    #[Computed]
    public function templates(): Collection
    {
        return WeekTemplate::query()->with('meals.recipe', 'meals.slot')->orderBy('name')->get();
    }

    #[Computed]
    public function slots(): Collection
    {
        return MealSlot::query()->active()->ordered()->get(['id', 'name']);
    }

    #[Computed]
    public function applying(): ?WeekTemplate
    {
        return $this->applyingId ? WeekTemplate::find($this->applyingId) : null;
    }

    /** Nombre de repas déjà présents dans la semaine d'arrivée. */
    #[Computed]
    public function existingCount(): int
    {
        return app(WeekPlanner::class)->mealsForWeek($this->weekStart())->count();
    }

    public static function weekdayName(int $weekday): string
    {
        return Carbon::now()->startOfWeek()->addDays($weekday - 1)->locale('fr')->isoFormat('dddd');
    }

    public function render()
    {
        return view('livewire.planner.templates');
    }
}
