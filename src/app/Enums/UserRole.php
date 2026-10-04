<?php

namespace App\Enums;

/**
 * Rôle d'un membre du foyer (18.5).
 *
 * C'est un garde-fou de confort entre personnes de confiance, pas une barrière de sécurité :
 * il évite les fausses manœuvres (un enfant qui vide le planning), il ne protège pas d'une
 * personne mal intentionnée ayant le mot de passe.
 */
enum UserRole: string
{
    case Owner = 'owner';     // responsable du foyer : tout, plus inviter, retirer, supprimer le foyer (lot 24, 25.3)
    case Full = 'full';       // tout faire
    case Viewer = 'viewer';   // consulter, et cocher la liste de courses

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Responsable du foyer',
            self::Full => 'Complet',
            self::Viewer => 'Consultation et courses',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Owner => 'Tout, plus inviter et retirer des membres, exporter ou supprimer le foyer.',
            self::Full => 'Voit et modifie tout : recettes, planning, stock, réglages.',
            self::Viewer => 'Consulte tout et utilise la liste de courses (cocher, ajouter un article), sans rien modifier d\'autre.',
        };
    }

    public function canEdit(): bool
    {
        return $this === self::Full || $this === self::Owner;
    }

    public function managesHousehold(): bool
    {
        return $this === self::Owner;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $r) => [$r->value => $r->label()])->all();
    }
}
