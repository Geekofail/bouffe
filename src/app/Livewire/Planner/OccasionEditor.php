<?php

namespace App\Livewire\Planner;

use App\Enums\MealType;
use App\Models\Guest;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\User;
use App\Services\Planning\GuestCompatibility;
use App\Services\Planning\OccasionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Fenêtre « Convives » d'une case du planning (8.1, 8.7) : présents du foyer, invités, occasion,
 * contraintes alimentaires et plats déjà prévus.
 */
class OccasionEditor extends Component
{
    public bool $show = false;

    public string $date = '';

    public ?int $slotId = null;

    public string $title = '';

    public string $notes = '';

    /** @var list<int> */
    public array $presentUserIds = [];

    /** Personnes sans compte présentes (lot 39). @var list<int> */
    public array $presentPersonIds = [];

    /** @var list<int> */
    public array $guestIds = [];

    public int $extraAdults = 0;

    public int $extraChildren = 0;

    public string $guestSearch = '';

    #[On('open-occasion')]
    public function open(string $date, int $slotId, OccasionService $occasions): void
    {
        $this->resetErrorBag();
        $this->date = Carbon::parse($date)->toDateString();
        $this->slotId = $slotId;
        $this->guestSearch = '';

        $occasion = $occasions->find($this->date, $slotId);
        $absent = $occasion?->absent_user_ids ?? [];

        $this->title = (string) $occasion?->title;
        $this->notes = (string) $occasion?->notes;
        $this->presentUserIds = User::query()->inHousehold()->whereNotIn('id', $absent)->orderBy('name')->pluck('id')->all();
        $this->presentPersonIds = $this->peopleWithoutAccount()->pluck('id')->diff($occasion?->absent_person_ids ?? [])->values()->all();
        $this->guestIds = $occasion?->guests->pluck('id')->all() ?? [];
        $this->extraAdults = (int) $occasion?->extra_adults;
        $this->extraChildren = (int) $occasion?->extra_children;
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
    }

    /* ================================================================ Invités */

    public function addGuest(int $guestId): void
    {
        if (Guest::active()->whereKey($guestId)->exists() && ! in_array($guestId, $this->guestIds, true)) {
            $this->guestIds[] = $guestId;
        }

        $this->guestSearch = '';
    }

    public function removeGuest(int $guestId): void
    {
        $this->guestIds = array_values(array_diff($this->guestIds, [$guestId]));
    }

    public function addGroup(string $group): void
    {
        $ids = Guest::active()->where('group_name', $group)->pluck('id')->all();
        $this->guestIds = array_values(array_unique([...$this->guestIds, ...$ids]));
    }

    /** Crée un invité à la volée depuis le champ de recherche. */
    public function createGuest(): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', $this->guestSearch));

        if ($name === '' || mb_strlen($name) > 100) {
            $this->addError('guestSearch', 'Indiquez un prénom (100 caractères au plus).');

            return;
        }

        $guest = Guest::active()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first() ?? Guest::create(['name' => $name]);
        $this->addGuest($guest->id);
    }

    public function adjust(string $field, int $delta): void
    {
        if (in_array($field, ['extraAdults', 'extraChildren'], true)) {
            $this->{$field} = max(0, min(50, $this->{$field} + $delta));
        }
    }

    /* ================================================================ Enregistrement */

    public function save(OccasionService $occasions): void
    {
        $this->validate([
            'title' => 'nullable|string|max:150',
            'notes' => 'nullable|string|max:255',
            'extraAdults' => 'integer|min:0|max:50',
            'extraChildren' => 'integer|min:0|max:50',
        ], [], ['title' => 'occasion', 'notes' => 'note']);

        try {
            $result = $occasions->save($this->date, (int) $this->slotId, [
                'title' => $this->title,
                'notes' => $this->notes,
                'absent_user_ids' => User::query()->inHousehold()->whereNotIn('id', $this->presentUserIds)->pluck('id')->all(),
                'absent_person_ids' => $this->peopleWithoutAccount()->pluck('id')->diff(array_map('intval', $this->presentPersonIds))->values()->all(),
                'guest_ids' => $this->guestIds,
                'extra_adults' => $this->extraAdults,
                'extra_children' => $this->extraChildren,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->addError('extraAdults', $e->getMessage());

            return;
        }

        $this->show = false;
        $this->dispatch('occasion-saved', date: $this->date, slotId: (int) $this->slotId, before: $result['before'], after: $result['after']);
        $this->dispatch('notify', message: $result['occasion'] ? 'Convives enregistrés : '.app(OccasionService::class)->summary($result['occasion']).'.' : 'Retour au foyer complet, sans invité.');
    }

    public function clear(OccasionService $occasions): void
    {
        $this->reset('title', 'notes', 'guestIds', 'extraAdults', 'extraChildren');
        $this->presentUserIds = User::query()->inHousehold()->pluck('id')->all();
        $this->presentPersonIds = $this->peopleWithoutAccount()->pluck('id')->all();
        $this->save($occasions);
    }

    /* ================================================================ Données */

    #[Computed]
    public function slot(): ?MealSlot
    {
        return $this->slotId ? MealSlot::find($this->slotId) : null;
    }

    #[Computed]
    public function users(): Collection
    {
        return User::query()->inHousehold()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Qui cocher dans « À la maison » : les personnes à table d'habitude (lot 39) ; sans liste, les comptes.
     *
     * @return Collection<int, array{key: string, name: string, user_id: int|null, person_id: int|null, hex: string|null, canteen: bool}>
     */
    #[Computed]
    public function presence(): Collection
    {
        $canteen = $this->show && $this->slotId ? app(\App\Services\People\CanteenCalendar::class)->absentAt($this->date, (int) $this->slotId) : [];
        $people = \App\Models\HouseholdPerson::query()->atTable()->ordered()->get();

        if ($people->isEmpty()) {
            return $this->users->map(fn (User $user) => ['key' => 'u'.$user->id, 'name' => $user->name, 'user_id' => $user->id, 'person_id' => null, 'hex' => null, 'canteen' => false]);
        }

        return $people->map(fn (\App\Models\HouseholdPerson $person) => [
            'key' => 'p'.$person->id,
            'name' => $person->name,
            'user_id' => $person->user_id,
            'person_id' => $person->user_id ? null : $person->id,
            'hex' => $person->hex(),
            'canteen' => in_array($person->id, $canteen, true),
        ]);
    }

    /** @return Collection<int, \App\Models\HouseholdPerson> */
    private function peopleWithoutAccount(): Collection
    {
        return \App\Models\HouseholdPerson::query()->atTable()->whereNull('user_id')->get(['id']);
    }

    /** @return Collection<int, Guest> */
    #[Computed]
    public function selectedGuests(): Collection
    {
        return Guest::query()->whereIn('id', $this->guestIds)->with('restrictions.ingredient', 'restrictions.tag')->ordered()->get();
    }

    /** @return Collection<int, Guest> */
    #[Computed]
    public function guestResults(): Collection
    {
        if (! $this->show) {
            return collect();
        }

        $term = trim($this->guestSearch);

        return Guest::query()->active()->whereNotIn('id', $this->guestIds)
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('group_name', 'like', "%{$term}%")))
            ->ordered()->limit($term === '' ? 8 : 20)->get();
    }

    /** @return Collection<string, int> groupe => nombre d'invités non encore ajoutés */
    #[Computed]
    public function groups(): Collection
    {
        return Guest::query()->active()->whereNotNull('group_name')->whereNotIn('id', $this->guestIds)
            ->get(['id', 'group_name'])->countBy('group_name')->sortKeys();
    }

    /** Personnes et portions d'après le formulaire (aperçu avant enregistrement, R33). */
    #[Computed]
    public function diners(): array
    {
        $appetites = app(\App\Services\Planning\Appetites::class);
        $absent = User::query()->inHousehold()->whereNotIn('id', $this->presentUserIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $absentPersons = $this->peopleWithoutAccount()->pluck('id')->diff(array_map('intval', $this->presentPersonIds))->values()
            ->merge($this->slotId ? app(\App\Services\People\CanteenCalendar::class)->absentAt($this->date, (int) $this->slotId) : [])->unique()->values()->all();
        $guests = $this->selectedGuests;
        $extraAdults = max(0, (int) $this->extraAdults);
        $extraChildren = max(0, (int) $this->extraChildren);

        $portions = $appetites->householdPortions($absent, $absentPersons)
            + $guests->sum(fn (Guest $guest) => $appetites->part($guest->appetiteLevel()))
            + $extraAdults + $extraChildren * $appetites->part('petit');

        return [
            'people' => $appetites->householdPeople($absent, $absentPersons) + $guests->count() + $extraAdults + $extraChildren,
            'portions' => \App\Services\Planning\Appetites::roundUp($portions),
        ];
    }

    /** Plats déjà prévus dans la case, avec leurs conflits éventuels. */
    #[Computed]
    public function cellMeals(): Collection
    {
        if (! $this->show) {
            return collect();
        }

        $compatibility = app(GuestCompatibility::class);

        return PlannedMeal::query()->whereDate('date', $this->date)->where('meal_slot_id', $this->slotId)
            ->with('recipe.ingredients.ingredient', 'recipe.tags', 'leftoverOf.recipe')->orderBy('position')->get()
            ->map(fn (PlannedMeal $meal) => [
                'meal' => $meal,
                'conflicts' => $meal->type === MealType::Recipe && $meal->recipe ? $compatibility->conflicts($meal->recipe, $this->selectedGuests) : [],
            ]);
    }

    public function render()
    {
        return view('livewire.planner.occasion-editor');
    }
}
