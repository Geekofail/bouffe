<?php

namespace App\Enums;

/** Niveau d'alerte d'un article en stock (module 11.3), toujours recalculé. */
enum ExpiryLevel: string
{
    case Expired = 'expired';      // DLC dépassée
    case Urgent = 'urgent';        // DLC aujourd'hui ou demain
    case Soon = 'soon';            // bientôt
    case DdmPassed = 'ddm_passed'; // DDM ou date estimée dépassée : à vérifier
    case Ok = 'ok';
    case Unknown = 'unknown';      // pas de date

    public function color(): string
    {
        return match ($this) {
            self::Expired, self::Urgent => 'red',
            self::Soon => 'orange',
            self::DdmPassed => 'yellow',
            self::Ok => 'stone',
            self::Unknown => 'stone',
        };
    }

    /** Ordre de tri : le plus urgent d'abord. */
    public function rank(): int
    {
        return match ($this) {
            self::Expired => 0,
            self::Urgent => 1,
            self::DdmPassed => 2,
            self::Soon => 3,
            self::Ok => 4,
            self::Unknown => 5,
        };
    }

    public function needsAttention(): bool
    {
        return in_array($this, [self::Expired, self::Urgent, self::Soon, self::DdmPassed], true);
    }
}
