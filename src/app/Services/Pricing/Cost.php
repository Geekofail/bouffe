<?php

namespace App\Services\Pricing;

/**
 * Résultat d'un calcul de coût (règle R17).
 *
 * Le point important est `missing` : tant qu'il reste des ingrédients sans prix, le total est
 * un **minimum**, et c'est ainsi qu'il est présenté (« ≥ 6,40 € · 3 prix manquants »). Un coût
 * approximatif présenté comme un coût exact serait pire que pas de coût du tout.
 */
final class Cost
{
    public function __construct(
        public readonly float $total = 0.0,
        public readonly int $counted = 0,      // lignes chiffrées
        public readonly int $missing = 0,      // lignes qu'on n'a pas su chiffrer
        public readonly float $servings = 1,     // portions, par demi-portion (lot 32)
        public readonly float $paid = 0.0,     // ce qui a été réellement payé (15.7)
    ) {}

    public function isKnown(): bool
    {
        return $this->counted > 0;
    }

    public function isComplete(): bool
    {
        return $this->counted > 0 && $this->missing === 0;
    }

    public function perServing(): ?float
    {
        return $this->isKnown() && $this->servings > 0 ? $this->total / $this->servings : null;
    }

    public function plus(self $other): self
    {
        return new self(
            total: $this->total + $other->total,
            counted: $this->counted + $other->counted,
            missing: $this->missing + $other->missing,
            servings: $this->servings,
            paid: $this->paid + $other->paid,
        );
    }

    public function withServings(int|float $servings): self
    {
        return new self($this->total, $this->counted, $this->missing, max(0.5, (float) $servings), $this->paid);
    }

    /** « 12,40 € » ou « ≥ 12,40 € » quand il manque des prix. */
    public function label(PriceBook $prices, ?float $amount = null): string
    {
        $amount ??= $this->total;

        if (! $this->isKnown()) {
            return 'prix inconnu';
        }

        return ($this->isComplete() ? '' : "≥\u{00A0}").$prices->money($amount);
    }

    /** « 3 prix manquants », ou null si tout est chiffré. */
    public function missingLabel(): ?string
    {
        return match (true) {
            $this->missing === 0 => null,
            $this->missing === 1 => '1 prix manquant',
            default => $this->missing.' prix manquants',
        };
    }
}
