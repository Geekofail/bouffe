<?php

namespace App\Services\Stock;

use App\Enums\UnitType;
use App\Models\Ingredient;
use App\Models\Unit;
use App\Services\QuantityParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Ajout rapide au stock (9.5) : « 2 kg de pommes de terre », « 6 œufs », « 1/2 botte de persil », « crème fraîche ».
 */
class QuickAddParser
{
    public function __construct(private readonly QuantityParser $quantities) {}

    /**
     * @return array{quantity: float|null, unit: Unit|null, ingredient: Ingredient|null, name: string}
     */
    public function parse(string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        $quantity = null;
        $unit = null;
        $rest = $text;

        // Quantité en tête : « 2 », « 1,5 », « 1/2 », « ½ », « 1 1/2 »
        if (preg_match('/^((?:\d+\s)?\d+\/\d+|\d+(?:[.,]\d+)?\s?[½¼¾]?|[½¼¾])\s*(.*)$/u', $text, $m)) {
            $parsed = $this->quantities->tryParse(trim($m[1]));

            if ($parsed !== false && $parsed !== null) {
                $quantity = $parsed;
                $rest = $m[2];

                [$unit, $rest] = $this->extractUnit($rest);
            }
        }

        $name = trim(preg_replace('/^(?:de |d\'|d’|du |des )/iu', '', $rest));
        $ingredient = $name === '' ? null : Ingredient::findByName($name);

        // « 6 œufs » : unité habituelle de l'ingrédient si elle compte des pièces
        if ($quantity !== null && $unit === null && $ingredient?->defaultUnit && $ingredient->defaultUnit->type === UnitType::Piece) {
            $unit = $ingredient->defaultUnit;
        }

        return [
            'quantity' => $quantity,
            'unit' => $unit,
            'ingredient' => $ingredient,
            'name' => $ingredient?->name ?? Str::ucfirst($name),
        ];
    }

    /** @return array{0: Unit|null, 1: string} */
    private function extractUnit(string $rest): array
    {
        $words = $this->unitWords();
        $lower = mb_strtolower($rest);

        foreach ($words as $word => $unit) {
            if ($lower === $word || str_starts_with($lower, $word.' ')) {
                return [$unit, ltrim(mb_substr($rest, mb_strlen($word)))];
            }
        }

        return [null, $rest];
    }

    /** @return Collection<string, Unit> mot => unité, les plus longs d'abord (« c. à soupe » avant « c ») */
    private function unitWords(): Collection
    {
        $words = [];

        foreach (Unit::query()->get() as $unit) {
            foreach (array_filter([$unit->code, $unit->label, $unit->label_plural]) as $word) {
                $word = mb_strtolower($word);
                $words[$word] = $unit;
                $words[Str::ascii($word)] ??= $unit;   // « boite » pour « boîte »
            }
        }

        // Écritures courantes
        foreach (['cuillère à soupe' => 'cs', 'cuillères à soupe' => 'cs', 'cuillère à café' => 'cc', 'cuillères à café' => 'cc', 'litre' => 'l', 'litres' => 'l', 'gr' => 'g', 'kilo' => 'kg', 'kilos' => 'kg', 'pièce' => 'piece', 'pièces' => 'piece', 'pieces' => 'piece'] as $word => $code) {
            if ($unit = Unit::firstWhere('code', $code)) {
                $words[$word] ??= $unit;
            }
        }

        return collect($words)->sortByDesc(fn ($unit, string $word) => mb_strlen($word));
    }
}
