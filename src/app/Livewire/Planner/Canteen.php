<?php

namespace App\Livewire\Planner;

use App\Models\CanteenMeal;
use App\Models\HouseholdPerson;
use App\Services\People\CanteenCalendar;
use App\Services\Receipts\ReadingFailed;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Planning › Cantine (lot 39, 39.2, R41) : ce que les enfants mangent le midi à l'école, semaine
 * par semaine. Saisi jour par jour, collé depuis le site ou l'application de l'école, ou
 * photographié (lu par le service des tickets).
 */
#[Title('Cantine')]
class Canteen extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;
    use WithFileUploads;

    #[Url(as: 'semaine')]
    public string $week = '';

    public string $pasted = '';

    /** @var list<int|string> pour qui le menu collé vaut */
    public array $pasteFor = [];

    public $photo = null;

    public function mount(): void
    {
        $this->week = $this->monday()->toDateString();
        $this->pasteFor = app(CanteenCalendar::class)->people()->pluck('id')->all();
    }

    public function monday(): Carbon
    {
        try {
            $date = $this->week !== '' ? Carbon::parse($this->week) : Carbon::today();
        } catch (\Throwable) {
            $date = Carbon::today();
        }

        // Le week-end, on prépare la semaine qui vient.
        if ($this->week === '' && $date->isWeekend()) {
            $date->addWeek();
        }

        return $date->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    public function previousWeek(): void
    {
        $this->week = $this->monday()->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->week = $this->monday()->addWeek()->toDateString();
    }

    /** Le menu d'un jour (enregistré quand le champ perd le focus). */
    public function saveLabel(int $personId, string $date, string $label, CanteenCalendar $canteen): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->apply(fn () => $canteen->setDay($this->person($personId), $date, CanteenMeal::CANTEEN, $label));
    }

    /** Cantine ou maison ce jour-là (vacances, malade, sortie). */
    public function toggleHome(int $personId, string $date, CanteenCalendar $canteen): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $entry = $canteen->week($date)->get(Carbon::parse($date)->toDateString(), collect())->firstWhere('person.id', $personId);
        $home = ($entry['status'] ?? CanteenMeal::CANTEEN) === CanteenMeal::CANTEEN;

        $this->apply(fn () => $canteen->setDay($this->person($personId), $date, $home ? CanteenMeal::HOME : CanteenMeal::CANTEEN, $home ? null : ($entry['label'] ?? null)),
            $home ? 'À la maison ce midi-là : compté dans les portions.' : 'À la cantine ce midi-là.');
    }

    public function homeAllWeek(int $personId, CanteenCalendar $canteen): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $person = $this->person($personId);
        $this->apply(fn () => $canteen->homeAllWeek($person, $this->monday()), "Pas de cantine cette semaine pour {$person->name}.");
    }

    public function paste(CanteenCalendar $canteen): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->validate(['pasted' => 'required|string|max:5000'], [], ['pasted' => 'menu']);
        $this->applyToPeople(fn (HouseholdPerson $person) => $canteen->paste($person, $this->monday(), $this->pasted), 'pasted');
    }

    public function updatedPhoto(CanteenCalendar $canteen): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->validate(['photo' => 'required|file|max:12288'], [], ['photo' => 'photo']);
        $path = $this->photo->getRealPath();
        $file = new \Illuminate\Http\UploadedFile($path, $this->photo->getClientOriginalName(), $this->photo->getMimeType(), null, true);

        try {
            // Une seule lecture (payante) : le texte lu sert à chaque personne choisie.
            $text = app(\App\Services\Receipts\OcrService::class)->readDocumentText($file, 'canteen');
        } catch (ReadingFailed|InvalidArgumentException $e) {
            $this->addError('photo', $e->getMessage());
            $this->photo = null;

            return;
        }

        $this->photo = null;
        $this->applyToPeople(fn (HouseholdPerson $person) => $canteen->paste($person, $this->monday(), $text, 'photo'), 'photo');
    }

    /** Applique un menu collé ou photographié à chaque personne choisie. */
    private function applyToPeople(callable $apply, string $field): void
    {
        $people = HouseholdPerson::query()->whereIn('id', array_map('intval', $this->pasteFor))->ordered()->get()->filter->hasCanteen();

        if ($people->isEmpty()) {
            $this->addError($field, 'Choisissez pour qui vaut ce menu.');

            return;
        }

        $days = 0;

        try {
            foreach ($people as $person) {
                $days = max($days, $apply($person));
            }
        } catch (InvalidArgumentException $e) {
            $this->addError($field, $e->getMessage());

            return;
        }

        $this->pasted = '';
        $this->resetErrorBag();
        $this->dispatch('notify', message: $days.' jour'.($days > 1 ? 's' : '').' rempli'.($days > 1 ? 's' : '').' : relisez avant de compter dessus.');
    }

    private function apply(callable $action, ?string $message = null): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        if ($message) {
            $this->dispatch('notify', message: $message);
        }
    }

    private function person(int $id): HouseholdPerson
    {
        return HouseholdPerson::query()->findOrFail($id);
    }

    public function render(CanteenCalendar $canteen)
    {
        $monday = $this->monday();
        $week = $canteen->week($monday);

        return view('livewire.planner.canteen', [
            'monday' => $monday,
            'people' => $canteen->people(),
            'days' => $week,
            'canEdit' => (bool) auth()->user()?->canEdit(),
            'ocr' => app(\App\Services\Receipts\OcrService::class)->status()['available'] ?? false,
            'lunch' => $canteen->lunchSlotId(),
        ]);
    }
}
