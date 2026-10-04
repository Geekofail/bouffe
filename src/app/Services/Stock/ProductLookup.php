<?php

namespace App\Services\Stock;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Unit;
use App\Support\NameNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Reconnaissance d'un produit par son code-barres (16.1).
 *
 * Ordre de recherche, volontairement dans cet ordre :
 *  1. **la maison** : un code-barres déjà scanné est connu, avec l'ingrédient qu'on lui a
 *     associé. Aucun réseau, aucune question : c'est le cas courant ;
 *  2. **Open Food Facts** : seulement pour un code-barres jamais vu. La réponse est mémorisée
 *     pour ne plus jamais redemander.
 *
 * Open Food Facts est gratuit (données ODbL) mais limite la lecture à 15 requêtes par minute et
 * par adresse IP, et demande un `User-Agent` qui identifie l'application : les deux sont respectés
 * ici. Sans réseau, le scan continue de fonctionner pour tout ce qui est déjà connu.
 */
class ProductLookup
{
    public const ENDPOINT = 'https://world.openfoodfacts.org/api/v2/product/';

    /** Demandé par Open Food Facts : une application identifiée, avec un moyen de contact. */
    public const USER_AGENT = 'Bouffe/1.0 (application familiale de planification de repas)';

    public const TIMEOUT = 6;

    /** Un code-barres EAN-8, EAN-13, UPC… : uniquement des chiffres. */
    public function isValidBarcode(string $barcode): bool
    {
        $barcode = $this->clean($barcode);

        return $barcode !== '' && preg_match('/^\d{6,20}$/', $barcode) === 1;
    }

    public function clean(string $barcode): string
    {
        return preg_replace('/\D+/', '', $barcode) ?? '';
    }

    /** Produit déjà connu de la maison, sans réseau. */
    public function known(string $barcode): ?Product
    {
        return Product::query()->where('barcode', $this->clean($barcode))->with(['ingredient', 'unit'])->first();
    }

    /**
     * Cherche un produit : d'abord la maison, puis Open Food Facts si besoin.
     *
     * @return array{product: Product|null, source: 'local'|'off'|'unknown', offline: bool}
     */
    public function find(string $barcode, bool $allowNetwork = true): array
    {
        $barcode = $this->clean($barcode);

        if ($product = $this->known($barcode)) {
            $product->forceFill([
                'times_scanned' => $product->times_scanned + 1,
                'last_seen_at' => now(),
            ])->save();

            return ['product' => $product, 'source' => 'local', 'offline' => false];
        }

        if (! $allowNetwork) {
            return ['product' => null, 'source' => 'unknown', 'offline' => true];
        }

        $data = $this->fetch($barcode);

        if ($data === null) {
            return ['product' => null, 'source' => 'unknown', 'offline' => true];
        }

        if ($data === []) {
            return ['product' => null, 'source' => 'unknown', 'offline' => false];
        }

        return ['product' => $this->remember($barcode, $data), 'source' => 'off', 'offline' => false];
    }

    /**
     * Interroge Open Food Facts.
     *
     * @return array<string, mixed>|null null = pas joignable (réseau), [] = produit inconnu là-bas
     */
    public function fetch(string $barcode): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(self::TIMEOUT)
                ->get(self::ENDPOINT.$this->clean($barcode).'.json', [
                    // Lot 40 (R44) : allergènes et traces, gardés tels qu'Open Food Facts les étiquette.
                    'fields' => 'code,product_name,product_name_fr,brands,quantity,categories_tags,image_front_small_url,allergens_tags,traces_tags',
                ]);
        } catch (\Throwable) {
            return null;    // pas de réseau : ce n'est pas une erreur, le scan reste utilisable
        }

        if ($response->status() === 404) {
            return [];
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();

        if (($body['status'] ?? 0) !== 1 || ! is_array($body['product'] ?? null)) {
            return [];
        }

        return $body['product'];
    }

    /**
     * Mémorise un produit venu d'Open Food Facts, en proposant l'ingrédient le plus probable.
     *
     * @param  array<string, mixed>  $data
     */
    public function remember(string $barcode, array $data): Product
    {
        $label = trim((string) ($data['product_name_fr'] ?? $data['product_name'] ?? ''));
        $label = $label !== '' ? $label : 'Produit '.$this->clean($barcode);
        [$quantity, $unit] = $this->parseQuantity((string) ($data['quantity'] ?? ''));

        return Product::updateOrCreate(
            ['barcode' => $this->clean($barcode)],
            [
                'label' => mb_substr($label, 0, 200),
                'brand' => ($brand = trim(Str::before((string) ($data['brands'] ?? ''), ','))) !== '' ? mb_substr($brand, 0, 100) : null,
                'quantity' => $quantity,
                'unit_id' => $unit?->id,
                'image_url' => mb_substr((string) ($data['image_front_small_url'] ?? ''), 0, 300) ?: null,
                'payload' => ['categories' => array_slice((array) ($data['categories_tags'] ?? []), 0, 8)],
                'source' => Product::OPEN_FOOD_FACTS,
                'ingredient_id' => $this->guessIngredient($label),
                'times_scanned' => 1,
                'last_seen_at' => now(),
                ...$this->allergenValues($data),
            ],
        )->load(['ingredient', 'unit']);
    }

    /**
     * Allergènes et traces d'une réponse d'Open Food Facts (R44). Sans le champ, rien n'est connu :
     * « allergènes inconnus », jamais « sans allergène ».
     *
     * @param  array<string, mixed>  $data
     * @return array{allergens: list<string>|null, traces: list<string>|null, allergens_checked_at: \Illuminate\Support\Carbon}
     */
    public function allergenValues(array $data): array
    {
        return [
            'allergens' => array_key_exists('allergens_tags', $data) ? \App\Support\AllergenGroups::clean((array) $data['allergens_tags']) : null,
            'traces' => array_key_exists('traces_tags', $data) ? \App\Support\AllergenGroups::clean((array) $data['traces_tags']) : null,
            'allergens_checked_at' => now(),
        ];
    }

    /** Relit les allergènes d'un produit connu (scanné avant le lot 40). false : pas joignable. */
    public function refreshAllergens(Product $product): bool
    {
        if ($product->source !== Product::OPEN_FOOD_FACTS) {
            return false;
        }

        $data = $this->fetch($product->barcode);

        if ($data === null) {
            return false;
        }

        $product->forceFill($data === [] ? ['allergens_checked_at' => now()] : $this->allergenValues($data))->save();

        return true;
    }

    /**
     * Tâche planifiée : quelques produits du stock dont les allergènes n'ont jamais été lus
     * (Open Food Facts limite à 15 lectures par minute).
     */
    public function refreshPendingAllergens(int $limit = 5): int
    {
        $products = Product::query()->where('source', Product::OPEN_FOOD_FACTS)->whereNull('allergens_checked_at')
            ->whereIn('id', \Illuminate\Support\Facades\DB::table('stock_items')->whereNotNull('product_id')->whereNull('finished_at')->select('product_id'))
            ->orderByDesc('last_seen_at')->limit($limit)->get();
        $done = 0;

        foreach ($products as $product) {
            if (! $this->refreshAllergens($product)) {
                break;   // pas de réseau : on réessaiera au passage suivant
            }

            $done++;
        }

        return $done;
    }

    /** Produit décrit à la main, quand Open Food Facts ne le connaît pas. */
    public function createManually(string $barcode, string $label, ?int $ingredientId, ?float $quantity, ?int $unitId): Product
    {
        return Product::updateOrCreate(
            ['barcode' => $this->clean($barcode)],
            [
                'label' => mb_substr(trim($label), 0, 200),
                'ingredient_id' => $ingredientId,
                'quantity' => $quantity,
                'unit_id' => $unitId,
                'source' => Product::MANUAL,
                'times_scanned' => 1,
                'last_seen_at' => now(),
            ],
        )->load(['ingredient', 'unit']);
    }

    /** Associe le code-barres à un ingrédient de la maison : c'est demandé une seule fois. */
    public function attach(Product $product, ?int $ingredientId): Product
    {
        $product->forceFill(['ingredient_id' => $ingredientId])->save();

        return $product->fresh(['ingredient', 'unit']);
    }

    /**
     * Ingrédient probable d'après le nom du produit : « Lait demi-écrémé UHT » → « Lait demi-écrémé ».
     *
     * Rien n'est forcé : la proposition est montrée et peut être changée. Une correspondance
     * exacte du nom (ou d'un alias) suffit ; on ne cherche pas à être malin au-delà.
     */
    public function guessIngredient(string $label): ?int
    {
        $normalized = NameNormalizer::normalize($label);

        if ($normalized === '') {
            return null;
        }

        if ($exact = Ingredient::findByName($label)) {
            return $exact->id;
        }

        // Le nom d'un ingrédient connu apparaît-il entièrement dans le nom du produit ?
        return Ingredient::query()
            ->select(['id', 'name', 'search_name'])
            ->get()
            ->filter(fn (Ingredient $ingredient) => $ingredient->search_name !== ''
                && str_contains($normalized, $ingredient->search_name))
            ->sortByDesc(fn (Ingredient $ingredient) => mb_strlen($ingredient->search_name))
            ->first()?->id;
    }

    /**
     * « 1 L », « 500 g », « 6 x 125 g » → quantité et unité.
     *
     * @return array{0: float|null, 1: Unit|null}
     */
    public function parseQuantity(string $text): array
    {
        $text = trim(mb_strtolower(str_replace(',', '.', $text)));

        if ($text === '') {
            return [null, null];
        }

        // « 6 x 125 g » : on retient le contenu total.
        if (preg_match('/^(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)\s*([a-z]+)/u', $text, $m)) {
            return [(float) $m[1] * (float) $m[2], $this->unitFromCode($m[3])];
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*([a-z]+)/u', $text, $m)) {
            return [(float) $m[1], $this->unitFromCode($m[2])];
        }

        return [null, null];
    }

    private function unitFromCode(string $code): ?Unit
    {
        $code = match ($code) {
            'l', 'litre', 'litres' => 'l',
            'cl' => 'cl',
            'ml' => 'ml',
            'kg' => 'kg',
            'g', 'gr', 'grammes', 'gramme' => 'g',
            default => null,
        };

        return $code ? Unit::firstWhere('code', $code) : null;
    }

    /** Date de péremption proposée pour un produit scanné (celle de l'ingrédient associé). */
    public function suggestedExpiry(Product $product, ?Carbon $today = null): ?string
    {
        $days = $product->ingredient?->shelf_life_days;

        return $days ? ($today ?? Carbon::today())->copy()->addDays($days)->toDateString() : null;
    }
}
