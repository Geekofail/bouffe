<?php

namespace App\Services\Stock;

use App\Enums\ExpiryLevel;
use App\Enums\ExpiryType;
use App\Models\StockItem;
use App\Support\Settings;
use App\Support\StockDefaults;
use Illuminate\Support\Carbon;

/**
 * Date effective et niveau d'alerte d'un article en stock (règle R11).
 *
 *  - date effective = min(date imprimée, ouvert le + conservation après ouverture)
 *  - congelé  : congelé le + durée de congélation (la date imprimée ne compte plus), type DDM
 *  - décongelé : décongelé le + 1 jour, type DLC
 *  - plat préparé sans date : ajouté le + 3 jours
 */
class ExpiryCalculator
{
    /** @return array{date: Carbon|null, type: ExpiryType} */
    public function effective(StockItem $item): array
    {
        if ($item->thawed_on) {
            return ['date' => $item->thawed_on->copy()->addDay(), 'type' => ExpiryType::Dlc];
        }

        if ($item->frozen_on) {
            $months = $item->ingredient?->freezer_months ?? StockDefaults::PREPARED_FREEZER_MONTHS;

            return ['date' => $item->frozen_on->copy()->addMonths($months), 'type' => ExpiryType::Ddm];
        }

        $date = $item->expires_on?->copy();
        $type = $item->expiry_type ?? ExpiryType::None;

        $afterOpening = $item->ingredient?->days_after_opening;

        if ($item->opened_on && $afterOpening !== null) {
            $openedLimit = $item->opened_on->copy()->addDays($afterOpening);

            if ($date === null || $openedLimit->lt($date)) {
                // Un produit ouvert se garde comme un produit frais.
                return ['date' => $openedLimit, 'type' => ExpiryType::Dlc];
            }
        }

        if ($date === null && $item->isPrepared()) {
            return ['date' => ($item->created_at ?? Carbon::today())->copy()->startOfDay()->addDays(StockDefaults::PREPARED_FRIDGE_DAYS), 'type' => ExpiryType::Dlc];
        }

        return ['date' => $date, 'type' => $type];
    }

    public function daysLeft(StockItem $item, ?Carbon $today = null): ?int
    {
        $date = $this->effective($item)['date'];

        return $date ? (int) ($today ?? Carbon::today())->copy()->startOfDay()->diffInDays($date->copy()->startOfDay(), false) : null;
    }

    public function level(StockItem $item, ?Carbon $today = null): ExpiryLevel
    {
        ['date' => $date, 'type' => $type] = $this->effective($item);

        if ($date === null) {
            return ExpiryLevel::Unknown;
        }

        $days = $this->daysLeft($item, $today);

        if ($type === ExpiryType::Dlc) {
            return match (true) {
                $days < 0 => ExpiryLevel::Expired,
                $days <= 1 => ExpiryLevel::Urgent,
                $days <= Settings::int('stock.dlc_soon_days', 3) => ExpiryLevel::Soon,
                default => ExpiryLevel::Ok,
            };
        }

        // DDM : prévenir une semaine avant ; date estimée (vrac, légumes) : comme une DLC, sans « à jeter ».
        $soon = $type === ExpiryType::Ddm ? Settings::int('stock.ddm_soon_days', 7) : Settings::int('stock.dlc_soon_days', 3);

        return match (true) {
            $days < 0 => ExpiryLevel::DdmPassed,
            $days <= $soon => ExpiryLevel::Soon,
            default => ExpiryLevel::Ok,
        };
    }

    /**
     * Badge court : « Dépassée », « Aujourd'hui », « Demain », « J-3 », « 12 oct. ».
     *
     * @return array{text: string, color: string, level: ExpiryLevel, title: string}|null
     */
    public function badge(StockItem $item, ?Carbon $today = null): ?array
    {
        ['date' => $date, 'type' => $type] = $this->effective($item);

        if ($date === null) {
            return null;
        }

        $level = $this->level($item, $today);
        $days = $this->daysLeft($item, $today);

        $text = match (true) {
            $days < 0 && $type === ExpiryType::Dlc => 'Dépassée',
            $days < 0 => 'À vérifier',
            $days === 0 => 'Aujourd\'hui',
            $days === 1 => 'Demain',
            $days <= 14 => 'J-'.$days,
            default => $date->locale('fr')->isoFormat($days > 300 ? 'MMM YYYY' : 'D MMM'),
        };

        $title = ($type === ExpiryType::Ddm ? 'À consommer de préférence avant le ' : ($type === ExpiryType::Dlc ? 'À consommer jusqu\'au ' : 'Estimé jusqu\'au '))
            .$date->locale('fr')->isoFormat('D MMMM YYYY');

        return ['text' => $text, 'color' => $level->color(), 'level' => $level, 'title' => $title];
    }
}
