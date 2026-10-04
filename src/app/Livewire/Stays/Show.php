<?php

namespace App\Livewire\Stays;

use App\Models\Stay;
use App\Services\Stays\StayCoorganizers;
use App\Services\Stays\StayService;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Un séjour (lot 34) : participants (34.1), repas (34.1), courses et « à emporter » (34.2, 34.4),
 * frais partagés (34.3, R37).
 */
class Show extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;
    use Concerns\ManagesParticipants;
    use Concerns\ManagesStayCosts;
    use Concerns\ManagesStayShopping;
    use Concerns\PlansStayMeals;

    public const TABS = [
        'participants' => 'Participants',
        'repas' => 'Repas',
        'courses' => 'Courses',
        'emporter' => 'À emporter',
        'frais' => 'Frais',
        'apporter' => 'Qui apporte quoi',   // lot 42 (42.2)
    ];

    #[Locked]
    public Stay $stay;

    #[Url(as: 'onglet', except: 'participants')]
    public string $tab = 'participants';

    /* Fenêtre « Modifier le séjour » */
    public bool $editing = false;

    public string $name = '';

    public string $place = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public string $notes = '';

    /** Lot 40 (40.3) : équipement du lieu ; « comme à la maison » tant que rien n'est choisi. */
    public bool $sameEquipment = true;

    /** @var list<string> */
    public array $equipment = [];

    public function mount(Stay $stay): void
    {
        $this->stay = $stay;
        $this->selectTab($this->tab);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = isset(self::TABS[$tab]) ? $tab : 'participants';
        $this->resetErrorBag();

        if ($this->tab === 'frais' && $this->expenseGroup === '') {
            $this->expenseGroup = (string) $this->stay->participants()->value('group_label');
        }
    }

    /** Lot 42 (42.1) : « organizer » ou « coorganizer ». */
    public function role(): ?string
    {
        return app(StayCoorganizers::class)->role($this->stay);
    }

    public function isOrganizer(): bool
    {
        return $this->role() === StayCoorganizers::ORGANIZER;
    }

    /** Réservé au foyer qui organise : dates, lieu, partage des frais, foyers invités, suppression. */
    private function organizerOnly(): bool
    {
        if (! $this->allowedToEdit()) {
            return false;
        }

        if (! $this->isOrganizer()) {
            $this->dispatch('notify', type: 'warning', message: 'Seul le foyer qui organise le séjour peut le faire.');

            return false;
        }

        return true;
    }

    /** Un foyer qui co-organise s'en va : ses dépenses restent dans les comptes (R45). */
    public function leave(StayCoorganizers $coorganizers): void
    {
        if (! $this->allowedToEdit() || $this->role() !== StayCoorganizers::COORGANIZER) {
            return;
        }

        $coorganizers->leave($this->stay);
        session()->flash('status', 'Vous ne co-organisez plus « '.$this->stay->name.' ».');
        $this->redirectRoute('stays.index', navigate: true);
    }

    public function edit(): void
    {
        if (! $this->isOrganizer()) {
            return;
        }

        $this->name = $this->stay->name;
        $this->place = (string) $this->stay->place;
        $this->startsOn = $this->stay->starts_on->toDateString();
        $this->endsOn = $this->stay->ends_on->toDateString();
        $this->notes = (string) $this->stay->notes;
        $this->sameEquipment = ! is_array($this->stay->equipment);
        $this->equipment = app(\App\Services\Recipes\KitchenEquipment::class)->available($this->stay);
        $this->resetErrorBag();
        $this->editing = true;
    }

    public function closeEdit(): void
    {
        $this->editing = false;
    }

    public function save(StayService $stays): void
    {
        if (! $this->organizerOnly()) {
            return;
        }

        try {
            $removed = $stays->update($this->stay, [
                'name' => $this->name, 'place' => $this->place, 'starts_on' => $this->startsOn, 'ends_on' => $this->endsOn,
                'notes' => $this->notes, 'split_mode' => $this->stay->split_mode,
                'equipment' => $this->sameEquipment ? null : $this->equipment,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->addError('endsOn', $e->getMessage());

            return;
        }

        $this->editing = false;
        $this->stay->refresh();
        $this->dispatch('notify', message: 'Séjour enregistré.'.($removed ? " {$removed} repas hors des nouvelles dates retiré".($removed > 1 ? 's' : '').'.' : ''));
    }

    public function delete(): void
    {
        if (! $this->organizerOnly()) {
            return;
        }

        // Lot 42 : les articles partis de chez n'importe quel foyer du séjour.
        if ($this->stay->packedItems()->whereNotNull('taken_at')->whereNull('returned_at')->exists()) {
            $this->addError('delete', 'Des articles sont partis de la maison : indiquez d\'abord ce qui revient (onglet « À emporter »).');
            $this->editing = false;

            return;
        }

        $this->stay->delete();
        session()->flash('status', 'Séjour supprimé.');
        $this->redirectRoute('stays.index', navigate: true);
    }

    public function render()
    {
        $this->stay->load('participants');

        $coorganizers = app(StayCoorganizers::class);

        return view('livewire.stays.show', [
            'tabs' => self::TABS,
            'role' => $this->role(),
            'householdNames' => $coorganizers->names($this->stay),
        ])->title($this->stay->name);
    }
}
