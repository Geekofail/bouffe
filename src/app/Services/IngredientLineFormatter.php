<?php

namespace App\Services;

use App\Enums\UnitType;
use App\Models\Ingredient;
use App\Models\Unit;
use Illuminate\Support\Str;

/**
 * Transforme une ligne d'ingrédient en phrase française lisible.
 *
 *   200 g + Farine          → « 200 g de farine »
 *   1 c. à soupe + Huile    → « 1 c. à soupe d'huile d'olive »
 *   2 pièces + Oignon       → « 2 oignons »
 *   null + Sel              → « sel »
 */
class IngredientLineFormatter
{
    /** Mots commençant par un h aspiré : pas d'élision (« de haricots »). */
    private const ASPIRATED_H = ['haricot', 'hachis', 'hareng', 'homard', 'houmous', 'hampe'];

    public function __construct(private readonly QuantityFormatter $formatter) {}

    /**
     * @return array{quantity: string, name: string, text: string}
     *                                                             quantity : partie quantité + unité (« 200 g », « 2 », « »)
     *                                                             name     : partie ingrédient (« de farine », « oignons », « sel »)
     *                                                             text     : phrase complète
     */
    public function format(?float $quantity, ?Unit $unit, Ingredient $ingredient, string $context = QuantityFormatter::RECIPE): array
    {
        // Pièce (ou pas d'unité) : « 2 oignons », « 1 ½ oignon »
        if ($quantity !== null && ($unit === null || $unit->code === 'piece')) {
            $qty = $this->formatter->format($quantity, null, $context);
            $name = $this->lower($ingredient->nameFor($this->formatter->rounded($quantity, null, $context)));

            return ['quantity' => $qty, 'name' => $name, 'text' => "{$qty} {$name}"];
        }

        // Sans quantité : « sel », « poivre »
        if ($quantity === null) {
            $name = $this->lower($ingredient->name);

            return ['quantity' => '', 'name' => $name, 'text' => $name];
        }

        // Avec unité : « 200 g de farine », « 2 c. à soupe d'huile »
        $qty = $this->formatter->format($quantity, $unit, $context);
        $noun = $this->lower($this->nounAfterUnit($ingredient, $unit));
        $name = $this->partitive($noun).$noun;

        return ['quantity' => $qty, 'name' => $name, 'text' => "{$qty} {$name}"];
    }

    /** Après une unité on emploie le pluriel des noms comptables (« 200 g de fraises »). */
    private function nounAfterUnit(Ingredient $ingredient, Unit $unit): string
    {
        return $ingredient->name_plural && $unit->type !== UnitType::Other
            ? $ingredient->name_plural
            : $ingredient->name;
    }

    private function partitive(string $noun): string
    {
        $ascii = Str::lower(Str::ascii($noun));

        if (preg_match('/^[aeiouy]/', $ascii)) {
            return "d'";
        }

        if (str_starts_with($ascii, 'h') && ! Str::startsWith($ascii, self::ASPIRATED_H)) {
            return "d'";
        }

        return 'de ';
    }

    /** « Huile d'olive » → « huile d'olive » ; les sigles (« IGP ») restent intacts. */
    private function lower(string $text): string
    {
        $first = mb_substr($text, 0, 1);
        $second = mb_substr($text, 1, 1);

        if ($second !== '' && mb_strtoupper($second) === $second && mb_strtolower($second) !== $second) {
            return $text;
        }

        return mb_strtolower($first).mb_substr($text, 1);
    }
}
