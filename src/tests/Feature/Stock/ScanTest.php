<?php

use App\Livewire\Stock\Scan;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Stock\ProductLookup;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Scan de code-barres (lot 18 — 16.1).
 *
 * Ce qui compte : un code-barres n'est demandé à l'extérieur qu'une seule fois, et le scan
 * continue de fonctionner sans réseau pour tout ce qui est déjà connu de la maison.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-19 10:00'));
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);

    $this->lookup = app(ProductLookup::class);
});

/** Réponse type d'Open Food Facts. */
function offResponse(array $overrides = []): array
{
    return ['status' => 1, 'code' => '3560070462216', 'product' => array_merge([
        'product_name_fr' => 'Lait demi-écrémé UHT',
        'brands' => 'Luxlait, Luxembourg',
        'quantity' => '1 L',
        'categories_tags' => ['en:dairies', 'en:milks'],
    ], $overrides)];
}

test('un code-barres inconnu est demandé une fois à Open Food Facts, puis mémorisé', function () {
    Http::fake([ProductLookup::ENDPOINT.'*' => Http::response(offResponse())]);

    $result = $this->lookup->find('3560070462216');

    expect($result['source'])->toBe('off')
        ->and($result['product']->label)->toBe('Lait demi-écrémé UHT')
        ->and($result['product']->brand)->toBe('Luxlait')
        ->and((float) $result['product']->quantity)->toBe(1.0)
        ->and($result['product']->unit->code)->toBe('l');

    // Deuxième scan : plus aucune requête.
    Http::fake([ProductLookup::ENDPOINT.'*' => Http::response([], 500)]);

    $second = $this->lookup->find('3560070462216');

    expect($second['source'])->toBe('local')
        ->and($second['product']->times_scanned)->toBe(2);
});

test('la requête s\'identifie auprès d\'Open Food Facts', function () {
    Http::fake([ProductLookup::ENDPOINT.'*' => Http::response(offResponse())]);

    $this->lookup->find('3560070462216');

    Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', ProductLookup::USER_AGENT)
        && str_contains($request->url(), '3560070462216'));
});

test('l\'ingrédient de la maison est proposé d\'après le nom du produit', function () {
    Http::fake([ProductLookup::ENDPOINT.'*' => Http::response(offResponse())]);

    $product = $this->lookup->find('3560070462216')['product'];

    expect($product->ingredient?->name)->toBe('Lait demi-écrémé');
});

test('sans réseau, un produit connu passe quand même', function () {
    Product::create(['barcode' => '123456789', 'label' => 'Café moulu', 'ingredient_id' => Ingredient::first()->id]);

    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('pas de réseau'));

    $known = $this->lookup->find('123456789');
    $unknown = $this->lookup->find('987654321');

    expect($known['source'])->toBe('local')
        ->and($known['offline'])->toBeFalse()
        ->and($unknown['product'])->toBeNull()
        ->and($unknown['offline'])->toBeTrue();     // signalé comme « pas joignable », pas comme « inexistant »
});

test('un produit inconnu d\'Open Food Facts n\'est pas confondu avec une panne de réseau', function () {
    Http::fake([ProductLookup::ENDPOINT.'*' => Http::response(['status' => 0], 404)]);

    $result = $this->lookup->find('000000000000');

    expect($result['product'])->toBeNull()
        ->and($result['offline'])->toBeFalse();
});

test('les quantités du paquet sont comprises', function () {
    expect($this->lookup->parseQuantity('500 g')[0])->toBe(500.0)
        ->and($this->lookup->parseQuantity('1 L')[1]->code)->toBe('l')
        ->and($this->lookup->parseQuantity('6 x 125 g')[0])->toBe(750.0)
        ->and($this->lookup->parseQuantity('')[0])->toBeNull();
});

test('un code-barres invalide est refusé', function () {
    expect($this->lookup->isValidBarcode('3560070462216'))->toBeTrue()
        ->and($this->lookup->isValidBarcode('356 007 046 2216'))->toBeTrue()   // les espaces sont tolérés
        ->and($this->lookup->isValidBarcode('abc'))->toBeFalse()
        ->and($this->lookup->isValidBarcode('123'))->toBeFalse();
});

test('l\'écran de scan ajoute le produit au stock et retient l\'association', function () {
    Http::fake([ProductLookup::ENDPOINT.'*' => Http::response(offResponse())]);

    $lait = Ingredient::firstWhere('name', 'Lait demi-écrémé');

    Livewire::test(Scan::class)
        ->set('barcode', '3560070462216')
        ->call('lookup')
        ->assertSet('state', 'found')
        ->assertSet('ingredientId', $lait->id)          // proposition
        ->call('store')
        ->assertHasNoErrors();

    $item = StockItem::latest('id')->first();

    expect($item->ingredient_id)->toBe($lait->id)
        ->and($item->product_id)->not->toBeNull()
        ->and((float) $item->quantity)->toBe(1.0)
        ->and(Product::first()->ingredient_id)->toBe($lait->id);
});

test('un produit déjà associé n\'est plus rien demandé', function () {
    $beurre = Ingredient::firstWhere('name', 'Beurre');
    Product::create(['barcode' => '111222333', 'label' => 'Beurre doux', 'ingredient_id' => $beurre->id]);

    Livewire::test(Scan::class)
        ->call('lookup', '111222333')
        ->assertSet('state', 'known')
        ->assertSet('ingredientId', $beurre->id);
});

test('un produit inconnu partout peut être nommé à la main et devient connu', function () {
    Http::fake([ProductLookup::ENDPOINT.'*' => Http::response(['status' => 0], 404)]);

    $location = StorageLocation::query()->ordered()->first();

    Livewire::test(Scan::class)
        ->call('lookup', '555666777')
        ->assertSet('state', 'missing')
        ->set('label', 'Sirop de sureau du voisin')
        ->set('quantity', '1')
        ->set('unitId', Unit::firstWhere('code', 'l')->id)
        ->set('locationId', $location->id)
        ->call('store')
        ->assertHasNoErrors();

    $product = Product::firstWhere('barcode', '555666777');

    expect($product)->not->toBeNull()
        ->and($product->source)->toBe(Product::MANUAL)
        ->and($product->label)->toBe('Sirop de sureau du voisin')
        ->and(StockItem::latest('id')->first()->label)->toBe('Sirop de sureau du voisin');
});

test('le scan est réservé aux comptes complets', function () {
    $this->actingAs(User::factory()->create(['role' => 'viewer']));

    $this->get(route('stock.scan'))->assertRedirect(route('dashboard'));
});
