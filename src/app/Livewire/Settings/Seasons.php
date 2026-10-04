<?php

namespace App\Livewire\Settings;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Services\Seasons\SeasonCalendar;
use Database\Seeders\SeasonSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Paramètres → Saisons (17.1).
 *
 * Les mois de saison sont des repères locaux, pas une vérité : ils se règlent ici, ingrédient
 * par ingrédient, d'un clic sur un mois. Un ingrédient sans mois coché n'est jamais signalé
 * hors saison — c'est le bon réglage pour tout ce qui n'a pas de saison.
 */
#[Title('Saisons')]
class Seasons extends Component
{
    use \App\Support\Concerns\GuardsCatalog;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** tous | dates | vides — on ouvre sur ce qui a une saison, le reste est du bruit. */
    #[Url(as: 'filtre', except: 'dates')]
    public string $filter = 'dates';

    #[Url(as: 'rayon', except: null)]
    public ?int $aisleId = null;

    /** Bascule un mois pour un ingrédient. */
    public function toggle(int $ingredientId, int $month): void
    {
        if (! $this->catalogEditable()) {
            return;
        }

        $ingredient = Ingredient::findOrFail($ingredientId);
        $months = collect($ingredient->season_months ?? []);

        $months = $months->contains($month)
            ? $months->reject(fn (int $m) => $m === $month)
            : $months->push($month);

        $ingredient->forceFill(['season_months' => $months->sort()->values()->all() ?: null])->save();

        unset($this->ingredients);
    }

    /** Toute l'année : équivaut à « pas de saison », donc on vide. */
    public function clear(int $ingredientId): void
    {
        if (! $this->catalogEditable()) {
            return;
        }

        Ingredient::whereKey($ingredientId)->update(['season_months' => null]);

        unset($this->ingredients);
        $this->dispatch('notify', message: 'Plus de saison pour cet ingrédient : il ne sera jamais signalé hors saison.');
    }

    /** Reprend les valeurs proposées par Bouffe pour les ingrédients encore vides. */
    public function restoreDefaults(): void
    {
        (new SeasonSeeder)->run();

        unset($this->ingredients);
        $this->dispatch('notify', message: 'Valeurs proposées reprises pour les ingrédients sans saison.');
    }

    /** @return Collection<int, Ingredient> */
    #[Computed]
    public function ingredients(): Collection
    {
        return Ingredient::query()
            ->with('aisle')
            ->when(trim($this->search) !== '', fn ($query) => $query->search(trim($this->search)))
            ->when($this->aisleId, fn ($query) => $query->whereSetting('aisle_id', '=', $this->aisleId))
            ->when($this->filter === 'dates', fn ($query) => $query->whereNotNull('season_months'))
            ->when($this->filter === 'vides', fn ($query) => $query->whereNull('season_months'))
            ->orderBy('name')
            ->limit(200)
            ->get();
    }

    /** @return Collection<int, Aisle> */
    #[Computed]
    public function aisles(): Collection
    {
        return Aisle::query()->ordered()->get(['id', 'name']);
    }

    public function render(SeasonCalendar $calendar)
    {
        return view('livewire.settings.seasons', [
            'calendar' => $calendar,
            'months' => SeasonCalendar::months(),
            'currentMonth' => (int) Carbon::today()->month,
            'datedCount' => Ingredient::whereNotNull('season_months')->count(),
            'inSeason' => $calendar->ingredientsInSeason(),
        ]);
    }
}
