<?php

namespace App\Livewire\Contributions;

use App\Models\Contribution;
use App\Models\MealOccasion;
use App\Models\MealOccasionHousehold;
use App\Models\Scopes\HouseholdScope;
use App\Models\Stay;
use App\Services\Stays\StayCoorganizers;
use App\Services\Together\Contributions;
use App\Support\CurrentHousehold;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * « Qui apporte quoi » (lot 42, 42.2) d'une réception ou d'un séjour.
 *
 *  - côté foyer qui reçoit (et foyers qui co-organisent le séjour) : écrire ce qu'il faut, dire
 *    qui l'apporte, lier un plat prévu, envoyer un lien à ceux qui n'ont pas Bouffe ;
 *  - côté foyer relié invité à une réception (repas commun, 26.5) : « Nous l'apportons ».
 */
class Board extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    /** « stay » ou « occasion » */
    #[Locked]
    public string $subject = 'occasion';

    #[Locked]
    public int $subjectId = 0;

    public string $label = '';

    public string $by = '';

    public string $dish = '';

    public bool $showLink = false;

    public function mount(string $subject, int $subjectId): void
    {
        $this->subject = $subject === 'stay' ? 'stay' : 'occasion';
        $this->subjectId = $subjectId;
        $this->access();   // 404 si ce foyer n'y a pas accès
    }

    /**
     * L'évènement et notre place : « host » (qui reçoit, ou co-organise le séjour) ou « guest ».
     *
     * @return array{target: MealOccasion|Stay, mode: string}
     */
    private function access(): array
    {
        $current = (int) CurrentHousehold::id();

        if ($this->subject === 'stay') {
            $stay = Stay::query()->withoutGlobalScope(HouseholdScope::class)->find($this->subjectId);
            abort_unless($stay && app(StayCoorganizers::class)->role($stay, $current), 404);

            return ['target' => $stay, 'mode' => 'host'];
        }

        if ($occasion = MealOccasion::query()->find($this->subjectId)) {
            return ['target' => $occasion, 'mode' => 'host'];
        }

        $row = MealOccasionHousehold::query()->where('meal_occasion_id', $this->subjectId)->where('household_id', $current)
            ->whereIn('status', ['invited', 'accepted'])->first();
        abort_unless($row && $row->occasion, 404);

        return ['target' => $row->occasion->loadMissing(['slot' => fn ($q) => $q->withoutGlobalScope(HouseholdScope::class)]), 'mode' => 'guest'];
    }

    private function ourName(): string
    {
        return (string) (CurrentHousehold::get()?->name ?: auth()->user()?->name);
    }

    public function add(Contributions $contributions): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        ['target' => $target, 'mode' => $mode] = $this->access();
        $this->validate(['label' => 'nullable|string|max:100', 'by' => 'nullable|string|max:60', 'dish' => 'nullable|string|max:20'], [], ['label' => 'quoi', 'by' => 'qui']);

        try {
            $contributions->add($target, $this->label, $mode === 'host'
                ? ['by_label' => $this->by ?: null, 'dish' => $mode === 'host' ? ($this->dish ?: null) : null,
                    'by_household_id' => $this->by === $this->ourName() ? CurrentHousehold::id() : null]
                : ['by_label' => $this->ourName(), 'by_household_id' => CurrentHousehold::id()]);
        } catch (InvalidArgumentException $e) {
            $this->addError('label', $e->getMessage());

            return;
        }

        $contributions->afterChange($target);
        $this->reset('label', 'by', 'dish');
        $this->resetErrorBag();
    }

    /** « Nous l'apportons » */
    public function take(int $id, Contributions $contributions): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        ['target' => $target] = $this->access();

        try {
            $contributions->claim($contributions->find($target, $id), $this->ourName(), ['by_household_id' => CurrentHousehold::id()]);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        $contributions->afterChange($target);
    }

    /** Plus personne ne l'apporte (le foyer qui reçoit, ou celui qui s'était inscrit). */
    public function release(int $id, Contributions $contributions): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        ['target' => $target, 'mode' => $mode] = $this->access();
        $row = $this->row($contributions, $target, $id);

        if ($row && ($mode === 'host' || (int) $row->by_household_id === (int) CurrentHousehold::id())) {
            $contributions->release($row);
            $contributions->afterChange($target);
        }
    }

    public function remove(int $id, Contributions $contributions): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        ['target' => $target, 'mode' => $mode] = $this->access();
        $row = $this->row($contributions, $target, $id);

        // Un foyer invité retire ce qu'il a ajouté lui-même ; le foyer qui reçoit, tout.
        if ($row && ($mode === 'host' || ((int) $row->by_household_id === (int) CurrentHousehold::id() && (int) $row->created_by === (int) auth()->id()))) {
            $contributions->remove($row);
            $contributions->afterChange($target);
        }
    }

    public function createLink(Contributions $contributions): void
    {
        ['target' => $target, 'mode' => $mode] = $this->access();

        if ($mode !== 'host' || ! $this->allowedToEdit()) {
            return;
        }

        try {
            $contributions->createLink($target);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        $this->showLink = true;
    }

    public function revokeLink(Contributions $contributions): void
    {
        ['target' => $target, 'mode' => $mode] = $this->access();

        if ($mode === 'host' && $this->allowedToEdit()) {
            $contributions->revokeLink($target);
            $this->showLink = false;
            $this->dispatch('notify', message: 'Le lien ne marche plus.');
        }
    }

    private function row(Contributions $contributions, MealOccasion|Stay $target, int $id): ?Contribution
    {
        try {
            return $contributions->find($target, $id);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function render()
    {
        $contributions = app(Contributions::class);
        ['target' => $target, 'mode' => $mode] = $this->access();
        $rows = $contributions->of($target);
        $link = $mode === 'host' ? $contributions->link($target) : null;

        return view('livewire.contributions.board', [
            'mode' => $mode,
            'rows' => $rows,
            'duplicates' => $contributions->duplicates($rows),
            'dishes' => $mode === 'host' ? $contributions->dishes($target) : collect(),
            'link' => $link,
            'linkUrl' => $link ? $contributions->url($link) : null,
            'isStay' => $target instanceof Stay,
            'us' => (int) CurrentHousehold::id(),
            'past' => $contributions->lastDay($target)->isPast(),
        ]);
    }
}
