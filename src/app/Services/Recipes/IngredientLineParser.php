<?php

namespace App\Services\Recipes;

use App\Models\Ingredient;
use App\Models\Unit;
use App\Services\QuantityParser;
use App\Support\NameNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Analyse d'une ligne d'ingrédient écrite en français (règle R14) :
 * « 1,5 kg de pommes de terre à chair ferme, épluchées »
 *   → 1,5 · kg · Pomme de terre · « épluchées » · confiance haute.
 *
 * Sert à l'import depuis une URL (R13), au collage de texte et à la saisie rapide du formulaire.
 */
class IngredientLineParser
{
    /** Mots qui rendent la ligne facultative. */
    private const OPTIONAL = ['facultatif', 'facultative', 'optionnel', 'optionnelle', 'si vous aimez', 'selon le gout', 'selon les gouts', 'pour servir', 'au gout'];

    /** Participes et compléments qui décrivent la préparation, pas l'ingrédient : « parmesan râpé ». */
    private const PREPARATIONS = [
        'rapé', 'rapée', 'rapés', 'rapées', 'râpé', 'râpée', 'râpés', 'râpées', 'émincé', 'émincée', 'émincés', 'émincées',
        'haché', 'hachée', 'hachés', 'hachées', 'coupé', 'coupée', 'coupés', 'coupées', 'ciselé', 'ciselée', 'ciselés', 'ciselées',
        'pelé', 'pelée', 'pelés', 'pelées', 'épluché', 'épluchée', 'épluchés', 'épluchées', 'fondu', 'fondue', 'fondus', 'fondues',
        'mou', 'molle', 'ramolli', 'ramollie', 'tiède', 'battu', 'battus', 'battue', 'battues', 'égoutté', 'égouttée', 'égouttés', 'égouttées',
        'en poudre', 'en morceaux', 'en tranches', 'en dés', 'en rondelles', 'en lamelles', 'en quartiers', 'du moulin',
    ];

    /** Adjectifs et compléments retirés quand l'ingrédient n'est pas reconnu tel quel. */
    private const QUALIFIERS = [
        'frais', 'fraiche', 'fraiches', 'bio', 'biologique', 'entier', 'entiere', 'entiers', 'entieres',
        'gros', 'grosse', 'grosses', 'petit', 'petite', 'petits', 'petites', 'moyen', 'moyenne',
        'nouveau', 'nouvelle', 'nouvelles', 'jeune', 'jeunes', 'de qualite', 'maison', 'du commerce',
        'liquide', 'semi complet', 'complet', 'demi', 'rose', 'jaune', 'rouge', 'vert', 'verte', 'blanc', 'blanche', 'noir', 'noire',
        'en poudre', 'en morceaux', 'en tranches', 'rape', 'rapee', 'rapes', 'rapees', 'surgele', 'surgelee', 'surgeles', 'surgelees',
    ];

    public function __construct(private readonly QuantityParser $quantities) {}

    /**
     * @return array{
     *   quantity: float|null, unit: Unit|null, ingredient: Ingredient|null, name: string,
     *   preparation: string|null, optional: bool, confidence: string, raw: string
     * }
     *   confidence : high (nom exact ou alias) · medium (approchant) · none (inconnu)
     */
    public function parse(string $line): array
    {
        $raw = trim($line);
        $text = $this->clean($raw);
        $result = ['quantity' => null, 'unit' => null, 'ingredient' => null, 'name' => '', 'preparation' => null, 'optional' => false, 'confidence' => 'none', 'raw' => $raw];

        if ($text === '') {
            return $result;
        }

        [$text, $result['optional']] = $this->extractOptional($text);
        [$text, $result['quantity']] = $this->extractQuantity($text);
        [$text, $result['unit']] = $this->extractUnit($text);
        [$text, $result['preparation']] = $this->extractPreparation($text);
        [$text, $result['preparation']] = $this->extractTrailingPreparation($text, $result['preparation']);

        $name = trim(preg_replace('/^(?:de\s+la\s+|de\s+l\'|du\s+|des\s+|de\s+|d\')/iu', '', trim($text, " \t.,;:-–—")));
        $result['name'] = Str::ucfirst(Str::squish($name));

        [$result['ingredient'], $result['confidence']] = $this->matchIngredient($result['name']);

        if ($result['ingredient'] && $result['confidence'] === 'high') {
            $result['name'] = $result['ingredient']->name;
        }

        return $result;
    }

    /** Une ligne ressemble-t-elle à un ingrédient (et non à une étape) ? */
    public function looksLikeIngredient(string $line): bool
    {
        $text = $this->clean($line);

        if ($text === '' || mb_strlen($text) > 120 || str_word_count($text, 0, 'àâäéèêëîïôöùûüçœ') > 14) {
            return false;
        }

        // Une étape contient presque toujours un verbe conjugué ou un point final.
        if (preg_match('/\b(mélanger|mettre|ajouter|verser|cuire|faire|laisser|couper|éplucher|préchauffer|servir|déposer|remuer|saler|poivrer|battre|fouetter|enfourner|réserver)\b/iu', $text)) {
            return false;
        }

        return (bool) preg_match('/^\s*(\d|½|¼|¾|une?\s|quelques\s|un peu\s)/iu', $text) || mb_strlen($text) <= 40;
    }

    /* ================================================================ Étapes de l'analyse */

    private function clean(string $line): string
    {
        $text = Str::of($line)
            ->replace(["\u{00A0}", "\u{202F}", '•', '·', '–', '—', '*'], [' ', ' ', ' ', ' ', '-', '-', ' '])
            ->replaceMatches('/^\s*(?:[-–—*°>]+\s*|\d{1,2}[).]\s+)/u', '')   // puces et numérotation en tête
            ->squish()
            ->value();

        return trim($text);
    }

    /** @return array{0: string, 1: bool} */
    private function extractOptional(string $text): array
    {
        $normalized = NameNormalizer::normalize($text);

        foreach (self::OPTIONAL as $word) {
            if (str_contains($normalized, NameNormalizer::normalize($word))) {
                $text = preg_replace('/\s*[(\[]?\s*(facultatif\w*|optionnel\w*|si vous aimez|selon (le|les) go[uû]ts?|pour servir|au go[uû]t)\s*[)\]]?\s*/iu', ' ', $text);

                return [Str::squish(trim($text, ' ,;-')), true];
            }
        }

        return [$text, false];
    }

    /** @return array{0: string, 1: string|null} */
    private function extractPreparation(string $text): array
    {
        // « (environ 3) » ou « , épluchées »
        $preparation = null;

        if (preg_match('/\s*\(([^)]{2,60})\)\s*$/u', $text, $m)) {
            $preparation = trim($m[1]);
            $text = trim(str_replace($m[0], '', $text));
        }

        if (str_contains($text, ',')) {
            [$before, $after] = explode(',', $text, 2);
            $after = trim($after);

            if ($after !== '' && mb_strlen($after) <= 80) {
                $preparation = $preparation === null ? $after : $after.' ('.$preparation.')';
                $text = trim($before);
            }
        }

        return [$text, $preparation === null ? null : Str::limit($preparation, 150, '')];
    }

    /**
     * « parmesan râpé » → « parmesan » + préparation « râpé ».
     *
     * @return array{0: string, 1: string|null}
     */
    private function extractTrailingPreparation(string $text, ?string $preparation): array
    {
        foreach (self::PREPARATIONS as $word) {
            if (preg_match('/\s+'.preg_quote($word, '/').'$/iu', $text, $m)) {
                $text = trim(mb_substr($text, 0, mb_strlen($text) - mb_strlen($m[0])));
                $preparation = trim($word.($preparation ? ', '.$preparation : ''));

                break;
            }
        }

        return [$text, $preparation];
    }

    /** @return array{0: string, 1: float|null} */
    private function extractQuantity(string $text): array
    {
        // « 1 1/2 », « 1,5 », « 2 à 3 » (on garde la borne haute), « ½ », « une »
        if (preg_match('/^((?:\d+\s+)?\d+\s*\/\s*\d+|\d+(?:[.,]\d+)?(?:\s*[àa-]\s*\d+(?:[.,]\d+)?)?|[½¼¾])\s*(.*)$/u', $text, $m)) {
            $value = $m[1];

            if (preg_match('/^(\d+(?:[.,]\d+)?)\s*[àa-]\s*(\d+(?:[.,]\d+)?)$/u', $value, $range)) {
                $value = $range[2];
            }

            $parsed = $this->quantities->tryParse(trim($value));

            if ($parsed !== false && $parsed !== null) {
                return [trim($m[2]), $parsed];
            }
        }

        if (preg_match('/^(une?|quelques)\s+(.*)$/iu', $text, $m)) {
            return [trim($m[2]), mb_strtolower($m[1]) === 'quelques' ? 3.0 : 1.0];
        }

        return [$text, null];
    }

    /** @return array{0: string, 1: Unit|null} */
    private function extractUnit(string $text): array
    {
        foreach ($this->unitSynonyms() as $needle => $unit) {
            if (preg_match('/^'.preg_quote($needle, '/').'(?:\b|\s|$)/iu', $text, $m)) {
                return [trim(mb_substr($text, mb_strlen($m[0]))), $unit];
            }
        }

        return [$text, null];
    }

    /** @return Collection<string, Unit> synonymes triés du plus long au plus court */
    private function unitSynonyms(): Collection
    {
        return once(function () {
            $extra = [
                'c. à s.' => 'cs', 'c à s' => 'cs', 'cuillère à soupe' => 'cs', 'cuillères à soupe' => 'cs', 'cuillere a soupe' => 'cs', 'cuilleres a soupe' => 'cs', 'cas' => 'cs',
                'c. à c.' => 'cc', 'c à c' => 'cc', 'cuillère à café' => 'cc', 'cuillères à café' => 'cc', 'cuillere a cafe' => 'cc', 'cuilleres a cafe' => 'cc', 'cac' => 'cc',
                'grammes' => 'g', 'gramme' => 'g', 'kilos' => 'kg', 'kilo' => 'kg', 'kilogrammes' => 'kg', 'kilogramme' => 'kg',
                'litres' => 'l', 'litre' => 'l', 'millilitres' => 'ml', 'millilitre' => 'ml', 'centilitres' => 'cl', 'centilitre' => 'cl',
                'pincées' => 'pincee', 'pincée' => 'pincee', 'gousses' => 'gousse', 'brins' => 'brin', 'branches' => 'branche',
                'sachets' => 'sachet', 'boîtes' => 'boite', 'boites' => 'boite', 'bottes' => 'botte', 'tranches' => 'tranche', 'pots' => 'pot',
            ];

            $units = Unit::query()->get();
            $synonyms = collect();

            foreach ($units as $unit) {
                foreach (array_filter([$unit->code, $unit->label, $unit->label_plural]) as $word) {
                    $synonyms[mb_strtolower($word)] = $unit;
                }
            }

            foreach ($extra as $word => $code) {
                if ($unit = $units->firstWhere('code', $code)) {
                    $synonyms[mb_strtolower($word)] = $unit;
                }
            }

            return $synonyms->sortKeysUsing(fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        });
    }

    /** @return array{0: Ingredient|null, 1: string} */
    private function matchIngredient(string $name): array
    {
        if (trim($name) === '') {
            return [null, 'none'];
        }

        if ($exact = Ingredient::findByName($name)) {
            return [$exact, 'high'];
        }

        // Sans les adjectifs : « ail rose de Lautrec » → « ail »
        $words = explode(' ', NameNormalizer::normalize($name));
        $kept = array_values(array_filter($words, fn (string $word) => ! in_array($word, array_map(fn ($q) => NameNormalizer::normalize($q), self::QUALIFIERS), true)));

        for ($length = count($kept); $length >= 1; $length--) {
            $candidate = implode(' ', array_slice($kept, 0, $length));

            if ($candidate !== '' && ($match = Ingredient::findByName($candidate))) {
                return [$match, $length === count($words) ? 'high' : 'medium'];
            }
        }

        // Nom très proche (faute de frappe)
        $normalized = NameNormalizer::normalize($name);
        $similar = Ingredient::query()->get(['id', 'name', 'search_name'])
            ->first(fn (Ingredient $ingredient) => $this->isClose($normalized, $ingredient->search_name));

        return $similar ? [$similar, 'medium'] : [null, 'none'];
    }

    private function isClose(string $a, string $b): bool
    {
        if ($a === '' || $b === '' || min(strlen($a), strlen($b)) < 4) {
            return false;
        }

        similar_text($a, $b, $percent);

        return $percent >= 88;
    }
}
