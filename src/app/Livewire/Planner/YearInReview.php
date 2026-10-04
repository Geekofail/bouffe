<?php

namespace App\Livewire\Planner;

use App\Services\Planning\PlanningStats;
use App\Services\Pricing\PriceBook;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * « L'année en cuisine » (lot 35, 35.3) : le bilan d'une année, à imprimer ou à partager.
 * Uniquement des chiffres réels, chacun avec sa base ; rien n'est publié en ligne.
 */
#[Title('L\'année en cuisine')]
class YearInReview extends Component
{
    public int $year;

    public function mount(?int $year = null): void
    {
        $today = Carbon::today();
        // Début janvier : c'est l'année qui vient de finir qu'on a envie de revoir.
        $this->year = $year ?? ($today->month === 1 && $today->day <= 15 ? $today->year - 1 : $today->year);

        abort_if($this->year > $today->year || $this->year < 2000, 404);
    }

    /** Le texte à partager : les mêmes chiffres que la page, sans nom de personne. */
    public static function shareText(array $stats, PriceBook $prices): string
    {
        $parts = [];
        $parts[] = $stats['dishes'].' plat'.($stats['dishes'] > 1 ? 's' : '').' mangé'.($stats['dishes'] > 1 ? 's' : '');
        $parts[] = $stats['distinct'].' recette'.($stats['distinct'] > 1 ? 's' : '').' différente'.($stats['distinct'] > 1 ? 's' : '');

        if ($stats['new']['count'] > 0) {
            $parts[] = $stats['new']['count'].' découverte'.($stats['new']['count'] > 1 ? 's' : '');
        }

        $text = 'Notre année '.$stats['year'].' en cuisine'.($stats['complete'] ? '' : ' (au '.$stats['to']->locale('fr')->isoFormat('D MMMM').')').' : '.implode(', ', $parts).'.';

        if ($top = $stats['top']->first()) {
            $text .= ' La plus cuisinée : '.$top['recipe']->title.', '.$top['count'].' fois.';
        }

        if ($stats['vegetarian']['share'] !== null) {
            $text .= ' '.$stats['vegetarian']['share'].' % de plats sans viande ni poisson.';
        }

        if (($diff = self::wasteDifference($stats)) !== null && $diff > 0.5) {
            $text .= ' '.$prices->money($diff).' jetés de moins que l\'an dernier.';
        }

        return $text.' (Bouffe)';
    }

    /** Euros jetés en moins par rapport à la même période l'an dernier ; null si l'une des deux n'a aucun prix. */
    public static function wasteDifference(array $stats): ?float
    {
        $previous = $stats['previous']['waste'] ?? null;

        if (! $previous || $previous['priced'] === 0 || $stats['waste']['priced'] === 0) {
            return null;
        }

        return round($previous['cost'] - $stats['waste']['cost'], 2);
    }

    public function render(PlanningStats $planning, PriceBook $prices)
    {
        $stats = $planning->year($this->year);

        return view('livewire.planner.year-in-review', [
            'stats' => $stats,
            'years' => $planning->years(),
            'shareText' => self::shareText($stats, $prices),
            'wasteDiff' => self::wasteDifference($stats),
            'prices' => $prices,
        ])->title('L\'année '.$this->year.' en cuisine');
    }
}
