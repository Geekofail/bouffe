<?php

namespace App\Services\People;

use App\Models\CanteenMeal;
use App\Models\HouseholdPerson;
use App\Models\MealSlot;
use App\Services\Planning\WeekBalance;
use App\Support\CurrentHousehold;
use App\Support\NameNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * La cantine du midi (lot 39, 39.2, R41).
 *
 * Une personne qui mange à la cantine a ses **jours de cantine** (lundi, mardi, jeudi…). Ces
 * jours-là, au déjeuner, elle n'est pas à table à la maison : les portions la retirent toute
 * seule. Le menu peut être saisi, collé depuis le site de l'école ou photographié ; il compte pour
 * l'équilibre de la semaine et pour éviter de servir la même chose le soir, **jamais** pour les
 * courses ni le stock. Un jour sans menu saisi est « cantine » sans détail : rien n'est déduit.
 * « Pas de cantine » (vacances, malade) la remet à table ce midi-là.
 */
class CanteenCalendar
{
    /** Jours de la semaine tels qu'on les écrit dans un menu. */
    private const DAY_WORDS = [
        1 => ['lundi', 'lun'], 2 => ['mardi', 'mar'], 3 => ['mercredi', 'mer'], 4 => ['jeudi', 'jeu'], 5 => ['vendredi', 'ven'],
    ];

    /** @var array<int, Collection<int, HouseholdPerson>> par foyer */
    private array $people = [];

    /** @var array<string, Collection<int, CanteenMeal>> par foyer et semaine */
    private array $rows = [];

    /** @var array<int, int|null> créneau du déjeuner, par foyer */
    private array $lunch = [];

    /** Le créneau du midi (« Déjeuner », « Midi »), d'après son nom. */
    public function lunchSlotId(): ?int
    {
        $household = (int) CurrentHousehold::id();

        if (! array_key_exists($household, $this->lunch)) {
            $this->lunch[$household] = MealSlot::query()->active()->ordered()->get(['id', 'name'])
                ->first(fn (MealSlot $slot) => self::isLunchName($slot->name))?->id;
        }

        return $this->lunch[$household];
    }

    public static function isLunchName(?string $name): bool
    {
        $name = NameNormalizer::normalize((string) $name);

        return ! str_contains($name, 'petit') && (str_contains($name, 'dejeuner') || str_contains($name, 'midi'));
    }

    public function isLunch(MealSlot|int|null $slot): bool
    {
        $id = $slot instanceof MealSlot ? $slot->id : $slot;

        return $id !== null && $id === $this->lunchSlotId();
    }

    /** @return Collection<int, HouseholdPerson> les personnes qui mangent à la cantine certains jours */
    public function people(): Collection
    {
        $household = (int) CurrentHousehold::id();

        return $this->people[$household] ??= HouseholdPerson::query()->withCanteen()->ordered()->get()
            ->filter(fn (HouseholdPerson $person) => $person->hasCanteen())->values();
    }

    public function anyone(): bool
    {
        return $this->people()->isNotEmpty();
    }

    /**
     * Personnes à la cantine ce midi-là (donc pas à table à la maison).
     *
     * @return list<int>
     */
    public function absentAt(Carbon|string $date, MealSlot|int|null $slot): array
    {
        if (! $this->anyone() || ! $this->isLunch($slot)) {
            return [];
        }

        return $this->onDay($date)->pluck('person.id')->all();
    }

    /**
     * Les midis de cantine d'un jour : une ligne par personne qui y mange ce jour-là.
     *
     * @return Collection<int, array{person: HouseholdPerson, label: string|null, families: list<string>}>
     */
    public function onDay(Carbon|string $date): Collection
    {
        $date = Carbon::parse($date)->startOfDay();

        return $this->week($date)->get($date->toDateString(), collect())
            ->filter(fn (array $entry) => $entry['status'] === CanteenMeal::CANTEEN)->values();
    }

    /**
     * La semaine (lundi à vendredi) de chaque personne qui a une cantine.
     *
     * @return Collection<string, Collection<int, array{person: HouseholdPerson, date: Carbon, status: string, label: string|null, families: list<string>, scheduled: bool}>>
     */
    public function week(Carbon|string $date): Collection
    {
        $monday = Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->startOfDay();
        $rows = $this->rowsFor($monday);
        $days = collect();

        for ($i = 0; $i < 5; $i++) {
            $day = $monday->copy()->addDays($i);
            $entries = collect();

            foreach ($this->people() as $person) {
                $row = $rows->first(fn (CanteenMeal $meal) => $meal->person_id === $person->id && $meal->date->isSameDay($day));
                $scheduled = in_array($day->dayOfWeekIso, $person->canteenDays(), true);

                if (! $scheduled && (! $row || $row->status !== CanteenMeal::CANTEEN)) {
                    continue;
                }

                $entries->push([
                    'person' => $person,
                    'date' => $day,
                    'status' => $row?->status ?? CanteenMeal::CANTEEN,
                    'label' => $row?->label,
                    'families' => array_values((array) $row?->families),
                    'scheduled' => $scheduled,
                ]);
            }

            $days->put($day->toDateString(), $entries);
        }

        return $days;
    }

    /**
     * Ce que la cantine a servi ce midi-là, pour éviter de le resservir le soir (R41).
     *
     * @return array{labels: list<string>, families: list<string>}
     */
    public function servedOn(Carbon|string $date): array
    {
        $served = $this->onDay($date)->filter(fn (array $entry) => filled($entry['label']));

        return [
            'labels' => $served->pluck('label')->unique()->values()->all(),
            'families' => $served->pluck('families')->flatten()->unique()->values()->all(),
        ];
    }

    /** Midis de cantine **avec un menu** entre deux dates, pour l'équilibre. @return Collection<int, array{date: Carbon, label: string, families: list<string>}> */
    public function servedBetween(Carbon $from, Carbon $to): Collection
    {
        $served = collect();

        for ($week = $from->copy()->startOfWeek(Carbon::MONDAY); $week->lte($to); $week->addWeek()) {
            foreach ($this->week($week) as $date => $entries) {
                if (Carbon::parse($date)->between($from->copy()->startOfDay(), $to->copy()->endOfDay())) {
                    foreach ($entries as $entry) {
                        if ($entry['status'] === CanteenMeal::CANTEEN && filled($entry['label'])) {
                            $served->push(['date' => $entry['date'], 'label' => (string) $entry['label'], 'families' => $entry['families']]);
                        }
                    }
                }
            }
        }

        return $served;
    }

    /* ================================================================ Écriture */

    /**
     * Le midi d'une personne : à la cantine (avec ou sans menu) ou à la maison.
     */
    public function setDay(HouseholdPerson $person, Carbon|string $date, string $status, ?string $label = null, string $source = 'manual'): void
    {
        $date = Carbon::parse($date)->startOfDay();

        if (! $person->hasCanteen()) {
            throw new InvalidArgumentException("{$person->name} ne mange pas à la cantine (Paramètres › Foyer).");
        }

        if ($date->isWeekend()) {
            throw new InvalidArgumentException('Pas de cantine le week-end.');
        }

        $label = Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $label) ?? ''), 200, '') ?: null;
        $status = $status === CanteenMeal::HOME ? CanteenMeal::HOME : CanteenMeal::CANTEEN;
        $scheduled = in_array($date->dayOfWeekIso, $person->canteenDays(), true);
        $existing = CanteenMeal::query()->where('person_id', $person->id)->whereDate('date', $date->toDateString())->first();

        // Le cas habituel (jour de cantine, sans menu) n'a pas besoin de ligne.
        if ($status === CanteenMeal::CANTEEN && $label === null && $scheduled) {
            $existing?->delete();
        } else {
            $values = [
                'status' => $status,
                'label' => $status === CanteenMeal::CANTEEN ? $label : null,
                'families' => $status === CanteenMeal::CANTEEN && $label ? app(WeekBalance::class)->familiesOfText($label) : null,
                'source' => in_array($source, ['manual', 'paste', 'photo'], true) ? $source : 'manual',
            ];
            $existing ? $existing->update($values) : CanteenMeal::create(['person_id' => $person->id, 'date' => $date->toDateString(), ...$values]);
        }

        $this->forget();
    }

    /**
     * Enregistre la semaine d'une personne d'après le formulaire.
     *
     * @param  array<string, array{home?: bool, label?: string|null}>  $days  « AAAA-MM-JJ » => saisie
     */
    public function saveWeek(HouseholdPerson $person, array $days, string $source = 'manual'): void
    {
        DB::transaction(function () use ($person, $days, $source) {
            foreach ($days as $date => $day) {
                $this->setDay($person, $date, ! empty($day['home']) ? CanteenMeal::HOME : CanteenMeal::CANTEEN, $day['label'] ?? null, $source);
            }
        });
    }

    /** « Pas de cantine cette semaine » (vacances) : tous ses jours de cantine passent à la maison. */
    public function homeAllWeek(HouseholdPerson $person, Carbon|string $date): int
    {
        $monday = Carbon::parse($date)->startOfWeek(Carbon::MONDAY);
        $count = 0;

        foreach ($person->canteenDays() as $day) {
            $this->setDay($person, $monday->copy()->addDays($day - 1), CanteenMeal::HOME);
            $count++;
        }

        return $count;
    }

    /**
     * Un menu collé (site de l'école, message) : chaque jour trouvé remplit le midi correspondant.
     *
     * @return int jours remplis
     */
    public function paste(HouseholdPerson $person, Carbon|string $weekOf, string $text, string $source = 'paste'): int
    {
        $parsed = $this->parse($text);

        if ($parsed === []) {
            throw new InvalidArgumentException('Aucun jour reconnu : le menu doit nommer les jours (« Lundi : … »).');
        }

        $monday = Carbon::parse($weekOf)->startOfWeek(Carbon::MONDAY);
        $count = 0;

        DB::transaction(function () use ($person, $parsed, $monday, $source, &$count) {
            foreach ($parsed as $day => $label) {
                $date = $monday->copy()->addDays($day - 1);

                // Un jour sans cantine pour cette personne reste à la maison : le menu n'y est pas noté.
                if (! in_array($day, $person->canteenDays(), true)) {
                    continue;
                }

                $this->setDay($person, $date, CanteenMeal::CANTEEN, $label, $source);
                $count++;
            }
        });

        return $count;
    }

    /**
     * La photo du menu affiché à l'école : lue par le service des tickets (lot 23), puis comme un menu
     * collé. Seule l'image est envoyée, sans nom (R41).
     */
    public function photo(HouseholdPerson $person, Carbon|string $weekOf, UploadedFile $file): int
    {
        $text = app(\App\Services\Receipts\OcrService::class)->readDocumentText($file, 'canteen');

        return $this->paste($person, $weekOf, $text, 'photo');
    }

    /**
     * Découpe un menu en jours : « Lundi : poisson pané, purée » ou un bloc par jour.
     *
     * @return array<int, string> jour (1 à 5) => menu
     */
    public function parse(string $text): array
    {
        $days = [];
        $current = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim(preg_replace('/^[#*\-•\s|]+|[|*\s]+$/u', '', $line) ?? '');

            if ($line === '') {
                continue;
            }

            $plain = NameNormalizer::normalize($line);
            $found = null;

            foreach (self::DAY_WORDS as $day => $words) {
                if (preg_match('/^(?:'.implode('|', $words).')\b\.?/u', $plain)) {
                    $found = $day;

                    break;
                }
            }

            if ($found !== null) {
                $current = $found;
                // Ce qui suit le jour (et sa date) sur la même ligne : « Lundi 6/10 : potage, … ».
                $rest = preg_replace('/^\S+\.?\s*(?:\d{1,2}(?:[\/.\-]\d{1,2}(?:[\/.\-]\d{2,4})?)?(?:\s+[[:alpha:]éû]+)?)?\s*[:\-–—]?\s*/u', '', $line) ?? '';
                $rest = $this->cleanLine($rest);
                $days[$current] = $rest !== '' ? [$rest] : [];

                continue;
            }

            if ($current !== null && ($clean = $this->cleanLine($line)) !== '') {
                $days[$current][] = $clean;
            }
        }

        $menus = [];

        foreach ($days as $day => $lines) {
            $label = Str::limit(implode(', ', array_unique($lines)), 200, '');

            if ($label !== '') {
                $menus[$day] = $label;
            }
        }

        ksort($menus);

        return $menus;
    }

    /** Retire les allergènes chiffrés « (1, 3, 7) », les prix, les mots « Menu », « Entrée : ». */
    private function cleanLine(string $line): string
    {
        $line = preg_replace('/\s*\((?:\s*\d+\s*[,;]?)+\)|\s*\b\d+(?:[.,]\d{2})?\s*€/u', '', $line) ?? $line;
        $line = preg_replace('/^(?:menu|entr[ée]e|plat|dessert|accompagnement|potage|laitage|fromage)\s*[:\-–]\s*/iu', '', trim($line)) ?? $line;
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '', " \t,;:-–");

        return preg_match('/^(?:menu|semaine|du\s+\d)/iu', $line) ? '' : $line;
    }

    /** @return Collection<int, CanteenMeal> */
    private function rowsFor(Carbon $monday): Collection
    {
        $key = CurrentHousehold::id().'|'.$monday->toDateString();

        return $this->rows[$key] ??= CanteenMeal::query()
            ->whereDate('date', '>=', $monday->toDateString())->whereDate('date', '<=', $monday->copy()->addDays(4)->toDateString())
            ->get();
    }

    /** Après une écriture : relire. */
    public function forget(): void
    {
        $this->people = [];
        $this->rows = [];
        $this->lunch = [];
    }
}
