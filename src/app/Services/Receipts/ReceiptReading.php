<?php

namespace App\Services\Receipts;

/**
 * Ce qu'un service a lu sur un ticket, avant toute interprétation (§7.3).
 *
 * Chaque ligne : label (tel qu'imprimé), name (nom lisible proposé, facultatif), quantity, unit
 * (piece · kg · l · null), unit_price, amount, kind (article · discount · deposit · voucher · bag ·
 * tax · payment · other).
 */
final class ReceiptReading
{
    /**
     * @param  list<array{label: string, name: string|null, quantity: float|null, unit: string|null, unit_price: float|null, amount: float|null, kind: string}>  $lines
     */
    public function __construct(
        public readonly string $provider,
        public readonly ?string $storeName,
        public readonly ?string $date,
        public readonly ?float $total,
        public readonly array $lines,
        public readonly int $pages,
        public readonly array $raw = [],
    ) {}
}
