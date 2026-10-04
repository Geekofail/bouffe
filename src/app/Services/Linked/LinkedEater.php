<?php

namespace App\Services\Linked;

use Illuminate\Support\Collection;

/**
 * Membre d'un foyer relié à table (26.6), de la même forme qu'un invité pour GuestCompatibility.
 */
final class LinkedEater
{
    /**
     * @param  Collection<int, \App\Models\PersonRestriction>  $restrictions  contraintes utilisables pour les alertes
     * @param  Collection<int, \App\Models\PersonRestriction>  $all  toutes ses contraintes partagées (affichage)
     */
    public function __construct(
        public string $name,
        public bool $shared,
        public Collection $restrictions,
        public Collection $all,
        public ?int $userId = null,
    ) {}

    /** Compatibilité avec les modèles Eloquent (GuestCompatibility charge les relations). */
    public function loadMissing(mixed ...$relations): self
    {
        return $this;
    }
}
