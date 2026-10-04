<?php

namespace App\Livewire\Settings;

use App\Models\MealSlot;
use App\Models\Tag;
use App\Services\Planning\PlanningRules;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Paramètres → Planning (14.4) : règles de la semaine et heure des rappels.
 */
#[Layout('layouts.app')]
#[Title('Planning')]
class Planning extends Component
{
    /** @var array<int, array{max_minutes: int|string|null, weeknights_only: bool}> */
    public array $slotRules = [];

    /** @var list<array{tag_id: int|string, min: int|string|null, max: int|string|null}> */
    public array $quotas = [];

    public bool $avoidRepeatCategory = true;

    public int|string $reminderHour = 18;

    public function mount(PlanningRules $rules): void
    {
        $stored = $rules->all();

        $this->slotRules = $this->activeSlots
            ->mapWithKeys(fn (MealSlot $slot) => [$slot->id => [
                'max_minutes' => $stored['slots'][$slot->id]['max_minutes'] ?? null,
                'weeknights_only' => $stored['slots'][$slot->id]['weeknights_only'] ?? true,
            ]])
            ->all();

        $this->quotas = $stored['quotas'];
        $this->avoidRepeatCategory = $stored['avoid_repeat_category'];
        $this->reminderHour = Settings::int('planning.reminder_hour', 18);
    }

    public function addQuota(): void
    {
        $this->quotas[] = ['tag_id' => '', 'min' => '', 'max' => ''];
    }

    public function removeQuota(int $index): void
    {
        unset($this->quotas[$index]);
        $this->quotas = array_values($this->quotas);
    }

    public function save(PlanningRules $rules): void
    {
        $this->validate([
            'slotRules.*.max_minutes' => 'nullable|integer|min:5|max:600',
            'quotas.*.tag_id' => 'nullable|integer|exists:tags,id',
            'quotas.*.min' => 'nullable|integer|min:0|max:21',
            'quotas.*.max' => 'nullable|integer|min:0|max:21',
            'reminderHour' => 'required|integer|min:6|max:22',
        ], [], [
            'slotRules.*.max_minutes' => 'temps maximum',
            'quotas.*.tag_id' => 'catégorie',
            'quotas.*.min' => 'minimum',
            'quotas.*.max' => 'maximum',
            'reminderHour' => 'heure des rappels',
        ]);

        $rules->save([
            'slots' => $this->slotRules,
            'quotas' => $this->quotas,
            'avoid_repeat_category' => $this->avoidRepeatCategory,
        ]);

        Settings::set('planning.reminder_hour', (int) $this->reminderHour);

        $this->dispatch('notify', message: 'Règles de la semaine enregistrées.');
    }

    #[Computed]
    public function activeSlots(): Collection
    {
        return MealSlot::query()->active()->ordered()->get(['id', 'name']);
    }

    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()->ordered()->get(['id', 'name']);
    }

    public function render()
    {
        return view('livewire.settings.planning');
    }
}
