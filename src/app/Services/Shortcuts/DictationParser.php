<?php

namespace App\Services\Shortcuts;

use Illuminate\Support\Str;

/**
 * Ce que Siri a compris (lot 38, 38.1) : « deux baguettes et du beurre » → « 2 baguettes », « Beurre ».
 *
 * Une phrase dictée devient une liste d'articles : on coupe aux virgules et aux « et », on retire
 * « ajoute », « à la liste », les articles (du, de la, des…), et les nombres dits en toutes lettres
 * deviennent des chiffres. Un « un » ou « une » suivi d'une unité est gardé (« un litre de lait » →
 * « 1 litre de lait »), sinon il disparaît (« une baguette » → « Baguette »).
 */
class DictationParser
{
    private const NUMBERS = [
        'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'neuf' => 9,
        'dix' => 10, 'onze' => 11, 'douze' => 12, 'treize' => 13, 'quatorze' => 14, 'quinze' => 15, 'seize' => 16,
        'vingt' => 20, 'trente' => 30, 'quarante' => 40, 'cinquante' => 50, 'soixante' => 60, 'cent' => 100,
    ];

    private const UNITS = ['litre', 'litres', 'kilo', 'kilos', 'kg', 'paquet', 'boîte', 'boite', 'bouteille', 'pot', 'sachet',
        'tranche', 'barquette', 'filet', 'botte', 'brique', 'pack', 'carton', 'livre', 'pain', 'bocal'];

    /** @return list<string> articles, dans l'ordre dicté, sans doublon */
    public function items(?string $text, int $max = 20): array
    {
        $text = Str::of((string) $text)->replace(['’', '‘'], "'")->lower()->squish()->toString();

        // Les mots qui servent à demander, pas à nommer l'article.
        $text = preg_replace('/^(?:s\'il te pla[iî]t\s+)?(?:ajoute[rsz]?|rajoute[rsz]?|mets?|mettre|note[rsz]?|il (?:me |nous )?faut|il n\'y a plus d[e\']|on n\'a plus d[e\']|plus de)\s*/u', '', $text) ?? $text;
        $text = preg_replace('/\s*(?:(?:à|a) la liste(?: de courses| des courses)?|aux courses|(?:à|a) bouffe|au stock|dans le stock|dans le frigo|au frigo|au congélateur)\s*[.!]?$/u', '', $text) ?? $text;

        $parts = preg_split('/\s*(?:,|;|\n|\bet\b|\bpuis\b|\bainsi que\b)\s*/u', $text) ?: [];
        $items = [];

        foreach ($parts as $part) {
            $item = $this->item($part);

            if ($item !== '' && ! in_array(mb_strtolower($item), array_map('mb_strtolower', $items), true)) {
                $items[] = $item;
            }

            if (count($items) >= $max) {
                break;
            }
        }

        return $items;
    }

    private function item(string $part): string
    {
        $part = trim($part, " \t.!?");

        // « une douzaine d'œufs », « une demi-douzaine d'œufs ».
        $part = preg_replace('/^(?:une\s+)?demi-douzaine\s+(?:de\s+|d\')/u', '6 ', $part) ?? $part;
        $part = preg_replace('/^une\s+douzaine\s+(?:de\s+|d\')/u', '12 ', $part) ?? $part;

        // Nombres en toutes lettres (« deux », « cinq cents grammes »).
        $part = preg_replace_callback('/^('.implode('|', array_keys(self::NUMBERS)).')(?:\s+(cents?))?\b/u', function (array $m) {
            $value = self::NUMBERS[$m[1]];

            return (string) (isset($m[2]) && $m[2] !== '' && $value < 100 ? $value * 100 : $value);
        }, $part) ?? $part;

        // « un » / « une » : gardé devant une unité, sinon retiré.
        if (preg_match('/^(?:un|une)\s+(\S+)/u', $part, $m)) {
            $part = in_array($m[1], self::UNITS, true) ? preg_replace('/^(?:un|une)\s+/u', '1 ', $part) : preg_replace('/^(?:un|une)\s+/u', '', $part);
        }

        // Articles et « un peu de ».
        $part = preg_replace('/^(?:un peu (?:de\s+|d\')|du\s+|de la\s+|de l\'|des\s+|de\s+|d\'|le\s+|la\s+|les\s+|l\')/u', '', (string) $part) ?? $part;
        $part = trim((string) $part);

        return $part === '' ? '' : mb_strtoupper(mb_substr($part, 0, 1)).mb_substr($part, 1);
    }
}
