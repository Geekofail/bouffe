<?php

namespace App\Support\Concerns;

/**
 * Garde-fou des comptes « consultation et courses » (18.5).
 *
 * À appeler au début d'une action qui modifie des données :
 *
 *     if (! $this->allowedToEdit()) { return; }
 *
 * Ce n'est pas une barrière de sécurité (voir App\Enums\UserRole) : cela évite les fausses
 * manœuvres et explique pourquoi le bouton n'a rien fait.
 */
trait RequiresFullAccess
{
    protected function allowedToEdit(): bool
    {
        if (auth()->user()?->canEdit()) {
            return true;
        }

        $this->dispatch('notify', type: 'warning', message: 'Votre compte est en consultation : cette modification est réservée aux comptes complets.');

        return false;
    }
}
