<?php

namespace App\Services\Receipts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * R27 — de ce que le service a lu aux lignes d'un ticket.
 *
 *  - genre de chaque ligne : article, remise, consigne, bon d'achat, sac, TVA (d'après le service
 *    et, par sécurité, d'après le libellé) ; total et moyens de paiement sont écartés ;
 *  - « 2 × 1,29 » → 2 pièces à 1,29 € ; « 0,532 kg × 3,99 €/kg » → 532 g ;
 *  - une remise qui suit directement un article lui est rattachée ;
 *  - une ligne dont quantité × prix ≠ montant, ou sans montant, est « douteuse » ;
 *  - cohérence : somme des lignes = total à 0,05 € près.
 */
class ReceiptInterpreter
{
    public const TOLERANCE = 0.05;

    private const PATTERNS = [
        'payment' => '/\b(total|sous[\s\-]?total|subtotal|a payer|montant du|net a payer|rendu|monnaie|especes|cash|visa|mastercard|maestro|bancontact|payconiq|digicash|v pay|carte bancaire|paiement|cb)\b/',
        'tax' => '/\b(tva|mwst|btw|vat|taxe)\b/',
        'deposit' => '/(consigne|pfand|vidange|leergut|leeggoed|retour bouteille|statiegeld)/',
        'voucher' => '/(bon d ?achat|coupon|voucher|cheque cadeau|bon de reduction|carte cadeau)/',
        'bag' => '/\b(sac|sacs|sachet caisse|tute|tuete|cabas)\b/',
        'discount' => '/(remise|reduction|rabais|ristourne|promo|avantage|korting|rabatt|\-\s?\d+\s?%|offert)/',
    ];

    /**
     * @return array{store_name: string|null, date: string|null, total: float|null, lines: list<array<string, mixed>>}
     */
    public function interpret(ReceiptReading $reading): array
    {
        $lines = [];

        foreach ($reading->lines as $raw) {
            $label = CardScrubber::text(trim((string) $raw['label'])) ?? '';

            if ($label === '') {
                continue;
            }

            $amount = $raw['amount'];
            $kind = $this->kind($label, (string) ($raw['kind'] ?? 'article'), $amount);

            if ($kind === 'payment') {
                continue;   // total, carte, rendu : jamais une ligne du ticket
            }

            [$count, $weight, $unitPrice, $label] = $this->quantities($label, $raw['quantity'] ?? null, $raw['unit'] ?? null, $raw['unit_price'] ?? null);

            $doubtful = false;

            if ($amount === null) {
                $computed = $weight !== null && $unitPrice !== null ? $weight / 1000 * $unitPrice : ($count !== null && $unitPrice !== null ? $count * $unitPrice : null);
                $amount = $computed !== null ? round($computed, 2) : 0.0;
                $doubtful = true;
            } elseif (in_array($kind, ['article', 'non_food'], true)) {
                $expected = $weight !== null && $unitPrice !== null ? $weight / 1000 * $unitPrice : ($count !== null && $count != 1 && $unitPrice !== null ? $count * $unitPrice : null);
                $doubtful = $expected !== null && abs($expected - $amount) > 0.02;
            }

            // Remises, bons et retours de consigne se comptent en négatif, même si le service a oublié le signe.
            if (in_array($kind, ['discount', 'voucher'], true) && $amount > 0) {
                $amount = -$amount;
            }

            // Une remise qui suit directement un article lui est rattachée (R27).
            $previous = array_key_last($lines);

            if ($kind === 'discount' && $previous !== null && $lines[$previous]['kind'] === 'article' && (float) $lines[$previous]['discount'] === 0.0) {
                $lines[$previous]['discount'] = round($amount, 2);

                continue;
            }

            $lines[] = [
                'position' => count($lines) + 1,
                'label' => mb_substr($label, 0, 200),
                'suggested_name' => $raw['name'] ? mb_substr((string) $raw['name'], 0, 150) : null,
                'kind' => $kind,
                'count' => $count,
                'weight' => $weight,
                'unit_price' => $unitPrice,
                'amount' => round((float) $amount, 2),
                'discount' => 0.0,
                'doubtful' => $doubtful,
            ];
        }

        return [
            'store_name' => $reading->storeName ? mb_substr(CardScrubber::text($reading->storeName) ?? '', 0, 150) : null,
            'date' => $this->date($reading->date),
            'total' => $reading->total !== null ? round($reading->total, 2) : null,
            'lines' => $lines,
        ];
    }

    /**
     * Somme des lignes comptées et écart au total (R27).
     *
     * @param  iterable<array{kind: string, amount: float|string, discount?: float|string}|\App\Models\ReceiptLine>  $lines
     * @return array{sum: float, total: float|null, gap: float|null, ok: bool}
     */
    public function consistency(iterable $lines, ?float $total): array
    {
        $sum = 0.0;

        foreach ($lines as $line) {
            $kind = is_array($line) ? $line['kind'] : $line->kind;

            if (in_array($kind, \App\Models\ReceiptLine::COUNTED, true)) {
                $sum += (float) (is_array($line) ? $line['amount'] : $line->amount) + (float) (is_array($line) ? ($line['discount'] ?? 0) : $line->discount);
            }
        }

        $sum = round($sum, 2);
        $gap = $total !== null ? round($total - $sum, 2) : null;

        return ['sum' => $sum, 'total' => $total, 'gap' => $gap, 'ok' => $gap !== null && abs($gap) <= self::TOLERANCE];
    }

    public function kind(string $label, string $proposed, ?float $amount = null): string
    {
        $text = ' '.Str::of($label)->ascii()->lower()->replaceMatches('/[^a-z0-9%\-]+/', ' ')->value().' ';

        foreach (['payment', 'tax', 'deposit', 'voucher'] as $kind) {
            if (preg_match(self::PATTERNS[$kind], $text)) {
                return $kind;
            }
        }

        if (preg_match(self::PATTERNS['bag'], $text) && ($amount === null || abs($amount) < 3)) {
            return 'bag';
        }

        if ($proposed === 'discount' || preg_match(self::PATTERNS['discount'], $text) || ($amount !== null && $amount < 0 && $proposed === 'article')) {
            return 'discount';
        }

        return match ($proposed) {
            'deposit', 'voucher', 'bag', 'tax', 'payment' => $proposed,
            default => 'article',
        };
    }

    /**
     * @return array{0: float|null, 1: float|null, 2: float|null, 3: string} nombre, poids (g), prix unitaire, libellé nettoyé
     */
    public function quantities(string $label, ?float $quantity, ?string $unit, ?float $unitPrice): array
    {
        $count = null;
        $weight = null;
        $unit = $unit ? mb_strtolower(trim($unit)) : null;

        // « 0,532 kg x 3,99 €/kg » dans le libellé
        if (preg_match('/(\d+[.,]\d{1,3})\s*kg\s*[x×\*]\s*(\d+[.,]\d{1,2})\s*(?:€|eur)?\s*\/\s*kg/iu', $label, $m)) {
            $weight = round((float) str_replace(',', '.', $m[1]) * 1000, 3);
            $unitPrice = (float) str_replace(',', '.', $m[2]);
            $label = trim(str_replace($m[0], '', $label));
        } elseif (preg_match('/^(\d{1,3})\s*[x×\*]\s*(\d+[.,]\d{2})\b\s*/u', $label, $m) || preg_match('/\s(\d{1,3})\s*[x×\*]\s*(\d+[.,]\d{2})\s*(?:€|eur)?$/iu', $label, $m)) {
            // « 2 x 1,29 » en tête ou en fin de libellé
            $count = (float) $m[1];
            $unitPrice = (float) str_replace(',', '.', $m[2]);
            $label = trim(str_replace($m[0], ' ', $label));
        }

        if ($weight === null && $count === null && $quantity !== null && $quantity > 0) {
            if ($unit === 'kg') {
                $weight = round($quantity * 1000, 3);
            } elseif ($unit === 'l') {
                $count = 1.0;   // un litre vendu au litre (rare) : la contenance reste celle du paquet
            } elseif (abs($quantity - round($quantity)) < 0.001) {
                $count = $quantity;
            } else {
                $weight = round($quantity * 1000, 3);   // « 0.532 » sans unité : un poids
            }
        }

        return [$count, $weight, $unitPrice, preg_replace('/\s{2,}/', ' ', $label) ?: $label];
    }

    private function date(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd.m.Y', 'd-m-Y', 'd/m/y', 'd.m.y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, trim($value));
            } catch (\Throwable) {
                continue;
            }

            // Une date lue dans le futur ou très ancienne est une erreur de lecture.
            if ($date && $date->lte(Carbon::today()) && $date->gt(Carbon::today()->subYears(2))) {
                return $date->toDateString();
            }
        }

        return null;
    }
}
