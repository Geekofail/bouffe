<?php

namespace App\Enums;

/**
 * Place d'un plat dans un menu (21.1) : plusieurs recettes dans une même case du planning.
 */
enum Course: string
{
    case Aperitif = 'aperitif';
    case Starter = 'entree';
    case Main = 'plat';
    case Cheese = 'fromage';
    case Dessert = 'dessert';

    public function label(): string
    {
        return match ($this) {
            self::Aperitif => 'Apéritif',
            self::Starter => 'Entrée',
            self::Main => 'Plat',
            self::Cheese => 'Fromage',
            self::Dessert => 'Dessert',
        };
    }

    public function order(): int
    {
        return match ($this) {
            self::Aperitif => 1,
            self::Starter => 2,
            self::Main => 3,
            self::Cheese => 4,
            self::Dessert => 5,
        };
    }

    /**
     * Moment où le plat est servi, en minutes après l'heure du repas (indicatif, pour le
     * rétroplanning) : l'apéritif à l'arrivée, l'entrée une demi-heure plus tard, etc.
     */
    public function serveOffset(): int
    {
        return match ($this) {
            self::Aperitif => 0,
            self::Starter => 30,
            self::Main => 60,
            self::Cheese => 105,
            self::Dessert => 120,
        };
    }

    /** @return list<self> */
    public static function ordered(): array
    {
        $cases = self::cases();
        usort($cases, fn (self $a, self $b) => $a->order() <=> $b->order());

        return $cases;
    }
}
