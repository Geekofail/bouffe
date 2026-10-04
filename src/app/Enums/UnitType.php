<?php

namespace App\Enums;

/**
 * Famille d'une unité de mesure.
 *
 * Deux unités de la même famille « masse » ou « volume » sont convertibles entre elles
 * grâce à leur facteur vers l'unité de base (g ou ml).
 */
enum UnitType: string
{
    case Mass = 'mass';
    case Volume = 'volume';
    case Piece = 'piece';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Mass => 'Masse',
            self::Volume => 'Volume',
            self::Piece => 'Comptage',
            self::Other => 'Autre',
        };
    }

    /** Code de l'unité de base de la famille, ou null si non convertible. */
    public function baseUnitCode(): ?string
    {
        return match ($this) {
            self::Mass => 'g',
            self::Volume => 'ml',
            default => null,
        };
    }

    public function isConvertible(): bool
    {
        return $this->baseUnitCode() !== null;
    }

    /** @return array<string, string> valeur => libellé, pour les listes déroulantes */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type) => [$type->value => $type->label()])->all();
    }
}
