<?php

namespace App\Services\Receipts;

use App\Enums\StockMode;
use App\Models\BudgetCategory;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ReceiptLabelMapping;
use App\Models\ReceiptLine;
use App\Models\Store;
use App\Models\Unit;
use App\Support\NameNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * R28 — libellé de ticket → ingrédient, et apprentissage (24.5).
 *
 *  1. correspondance apprise dans ce magasin, puis dans n'importe quel magasin ;
 *  2. nom ou alias d'un ingrédient (mot pour mot), produit scanné (16.1) ;
 *  3. nom lisible proposé par le service ;
 *  4. sinon « non reconnu ».
 */
class ReceiptMatcher
{
    /** Mots qui désignent presque toujours un article non alimentaire (droguerie). */
    private const NON_FOOD = ['lessive', 'adoucissant', 'papier toilette', 'papier wc', 'essuie tout', 'mouchoir', 'shampo', 'gel douche', 'dentifrice', 'brosse a dent', 'deodorant', 'eponge', 'sac poubelle', 'sacs poubelle', 'liquide vaisselle', 'produit vaisselle', 'tablette lave', 'nettoyant', 'javel', 'savon', 'coton', 'rasoir', 'couche', 'lingette', 'ampoule', 'pile', 'aluminium', 'film etirable', 'litiere'];

    /** @var Collection<int, Ingredient>|null */
    private ?Collection $ingredients = null;

    /** Libellé normalisé (R28) : majuscules, sans accents ni ponctuation, sans nombres de fin. */
    public static function normalize(string $label): string
    {
        $text = Str::of($label)->replace(['œ', 'Œ'], 'OE')->ascii()->upper()->replaceMatches('/[^A-Z0-9%]+/', ' ')->squish()->value();
        $words = explode(' ', $text);

        // « LAIT DEMI ECR UHT 1L » → « LAIT DEMI ECR UHT » ; « BEURRE 250 G » → « BEURRE »
        while ($words !== []) {
            $last = (string) end($words);

            if (preg_match('/\d/', $last)) {
                array_pop($words);

                continue;
            }

            if (count($words) > 1 && in_array($last, ['G', 'GR', 'KG', 'L', 'CL', 'ML', 'X', 'PCS', 'ST'], true) && preg_match('/\d/', $words[count($words) - 2])) {
                array_splice($words, -2);

                continue;
            }

            break;
        }

        return mb_substr(trim(implode(' ', $words)), 0, 150);
    }

    /**
     * Rapproche chaque ligne d'achat : ingrédient, genre (hors stock), contenu du paquet, poste.
     *
     * @param  list<array<string, mixed>>  $lines  lignes interprétées (R27)
     * @return list<array<string, mixed>>
     */
    public function match(array $lines, ?Store $store): array
    {
        $groceries = BudgetCategory::groceries()?->id;
        $household = BudgetCategory::query()->where('kind', 'household')->whereNull('archived_at')->value('id');

        return array_map(function (array $line) use ($store, $groceries, $household) {
            $line += ['ingredient_id' => null, 'confidence' => 'none', 'pack_quantity' => null, 'pack_unit_id' => null, 'budget_category_id' => $groceries, 'to_stock' => false];

            if ($line['kind'] !== 'article') {
                return $line;
            }

            [$packQuantity, $packUnit] = $this->pack((string) $line['label']);
            $line['pack_quantity'] = $packQuantity;
            $line['pack_unit_id'] = $packUnit?->id;

            if ($mapping = $this->learned((string) $line['label'], $store)) {
                $line['kind'] = $mapping->kind === 'non_food' ? 'non_food' : ($mapping->kind === 'ignored' ? 'ignored' : 'article');
                $line['ingredient_id'] = $mapping->ingredient_id;
                $line['confidence'] = 'learned';
                $line['pack_quantity'] = $mapping->pack_quantity !== null ? (float) $mapping->pack_quantity : $line['pack_quantity'];
                $line['pack_unit_id'] = $mapping->pack_unit_id ?? $line['pack_unit_id'];
                $line['budget_category_id'] = $mapping->budget_category_id ?? ($line['kind'] === 'non_food' ? ($household ?? $groceries) : $groceries);
            } elseif ($ingredient = $this->byName((string) $line['label'])) {
                [$line['ingredient_id'], $line['confidence']] = [$ingredient->id, 'name'];
            } elseif ($ingredient = $this->byProduct((string) $line['label'])) {
                [$line['ingredient_id'], $line['confidence']] = [$ingredient->id, 'product'];
            } elseif ($line['suggested_name'] && ($ingredient = $this->byName((string) $line['suggested_name']))) {
                [$line['ingredient_id'], $line['confidence']] = [$ingredient->id, 'suggested'];
            } elseif ($this->looksNonFood((string) $line['label'].' '.$line['suggested_name'])) {
                $line['kind'] = 'non_food';
                $line['budget_category_id'] = $household ?? $groceries;
            }

            $ingredient = $line['ingredient_id'] ? $this->ingredients()->firstWhere('id', $line['ingredient_id']) : null;
            $line['to_stock'] = $line['kind'] === 'article' && $ingredient && $ingredient->stock_mode !== StockMode::None;

            return $line;
        }, $lines);
    }

    /** Correspondance apprise : ce magasin d'abord, puis la plus confirmée ailleurs. */
    public function learned(string $label, ?Store $store): ?ReceiptLabelMapping
    {
        $normalized = self::normalize($label);

        if ($normalized === '') {
            return null;
        }

        $query = ReceiptLabelMapping::query()->where('normalized_label', $normalized);

        return ($store ? (clone $query)->where('store_id', $store->id)->first() : null)
            ?? (clone $query)->orderByDesc('confirmations')->orderByDesc('id')->first();
    }

    /**
     * Retient les choix confirmés à la validation. Un choix différent remplace la correspondance
     * (écart assumé avec R28, voir la documentation du lot).
     */
    public function learn(ReceiptLine $line, ?Store $store): void
    {
        $normalized = self::normalize($line->label);

        if ($normalized === '' || ! in_array($line->kind, ['article', 'non_food', 'ignored'], true)) {
            return;
        }

        // Un article resté sans ingrédient n'apprend rien : on ne retient pas une absence de choix.
        if ($line->kind === 'article' && ! $line->ingredient_id) {
            return;
        }

        $mapping = ReceiptLabelMapping::query()->firstOrNew(['store_id' => $store?->id, 'normalized_label' => $normalized]);
        $same = $mapping->exists && $mapping->kind === $line->kind && (int) $mapping->ingredient_id === (int) $line->ingredient_id;

        $mapping->fill([
            'kind' => $line->kind,
            'ingredient_id' => $line->kind === 'article' ? $line->ingredient_id : null,
            'pack_quantity' => $line->pack_quantity,
            'pack_unit_id' => $line->pack_unit_id,
            'budget_category_id' => $line->budget_category_id,
            'confirmations' => $same ? $mapping->confirmations + 1 : 1,
        ])->save();
    }

    /** « 1L », « 500G », « 6X125G » en fin de libellé → contenu d'un paquet. @return array{0: float|null, 1: Unit|null} */
    public function pack(string $label): array
    {
        $text = mb_strtolower(str_replace(',', '.', $label));

        if (preg_match('/(\d+)\s*[x×]\s*(\d+(?:\.\d+)?)\s*(kg|g|gr|l|cl|ml)\b/u', $text, $m)) {
            return [(float) $m[1] * (float) $m[2], $this->unit($m[3])];
        }

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(kg|g|gr|l|cl|ml)\b/u', $text, $all, PREG_SET_ORDER)) {
            $m = end($all);

            return [(float) $m[1], $this->unit($m[2])];
        }

        return [null, null];
    }

    private function unit(string $code): ?Unit
    {
        return Unit::firstWhere('code', $code === 'gr' ? 'g' : $code);
    }

    /** Nom d'ingrédient (ou alias) exact, ou contenu mot pour mot dans le libellé (le plus long gagne). */
    public function byName(string $label): ?Ingredient
    {
        $clean = trim(preg_replace('/\d+([.,]\d+)?\s*(kg|g|gr|l|cl|ml|x)?\b/iu', ' ', $label) ?? $label);

        if ($exact = Ingredient::findByName($clean)) {
            return $exact;
        }

        $normalized = ' '.NameNormalizer::normalize($clean).' ';

        if (trim($normalized) === '') {
            return null;
        }

        return $this->ingredients()
            ->filter(fn (Ingredient $i) => mb_strlen($i->search_name) >= 3 && str_contains($normalized, ' '.$i->search_name.' '))
            ->sortByDesc(fn (Ingredient $i) => mb_strlen($i->search_name))
            ->first();
    }

    private function byProduct(string $label): ?Ingredient
    {
        $normalized = NameNormalizer::normalize(self::normalize($label));

        if ($normalized === '') {
            return null;
        }

        return Product::query()->whereNotNull('ingredient_id')->with('ingredient')->get(['id', 'label', 'ingredient_id'])
            ->first(fn (Product $p) => NameNormalizer::normalize(self::normalize((string) $p->label)) === $normalized)?->ingredient;
    }

    private function looksNonFood(string $text): bool
    {
        $text = ' '.NameNormalizer::normalize($text).' ';

        foreach (self::NON_FOOD as $word) {
            if (str_contains($text, ' '.NameNormalizer::normalize($word))) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, Ingredient> */
    private function ingredients(): Collection
    {
        return $this->ingredients ??= Ingredient::query()->get(['id', 'name', 'search_name', 'stock_mode', 'default_unit_id']);
    }
}
