<?php

use App\Livewire\Dashboard;
use App\Livewire\Prices\Index as PricesPage;
use App\Livewire\Shopping\Show as ShoppingShow;
use App\Models\Ingredient;
use App\Models\IngredientPrice;
use App\Models\ShoppingList;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Services\Pricing\PersonalInflation;
use App\Services\Pricing\PriceBook;
use App\Services\Pricing\PriceComparison;
use App\Services\Pricing\StoreSplit;
use App\Services\Shopping\ShoppingListManager;
use App\Support\Navigation;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\MealSlotSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Prix et magasins (lot 27) : comparateur entre magasins (C1), liste répartie entre deux
 * magasins, inflation personnelle et hausses marquées (C2).
 *
 * L'essentiel : on ne compare que ce qui se compare (même unité, même magasin pour l'évolution),
 * et on ne donne pas de chiffre quand il y a trop peu de relevés.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    $this->user = User::factory()->create(['name' => 'Pierre']);
    $this->actingAs($this->user);
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class, MealSlotSeeder::class]);

    $this->book = app(PriceBook::class);
    $this->cactus = Store::create(['name' => 'Cactus', 'is_default' => true]);
    $this->lidl = Store::create(['name' => 'Lidl', 'color' => 'blue']);
    $this->u = Unit::pluck('id', 'code');
});

/** Relève un prix : « 2,49 € pour 500 g chez Cactus le 1er septembre ». */
function priceAt(string $ingredient, Store $store, float $price, ?float $quantity, ?string $unit, string $on): IngredientPrice
{
    return app(PriceBook::class)->record(
        Ingredient::firstWhere('name', $ingredient),
        $price,
        $quantity,
        $unit ? Unit::firstWhere('code', $unit) : null,
        $store,
        Carbon::parse($on),
        IngredientPrice::RECEIPT,
    );
}

/* ================================================================ C1 — comparateur */

test('le comparateur garde le dernier prix de chaque magasin, ramené au kilo, du moins cher au plus cher', function () {
    priceAt('Beurre', $this->cactus, 2.00, 250, 'g', '2026-08-01');
    priceAt('Beurre', $this->cactus, 2.36, 250, 'g', '2026-09-10');   // dernier prix chez Cactus : 9,44 €/kg
    priceAt('Beurre', $this->lidl, 4.00, 500, 'g', '2026-09-05');     // 8,00 €/kg

    $product = app(PriceComparison::class)->products()->sole();

    expect($product['ingredient']->name)->toBe('Beurre')
        ->and($product['prices']->pluck('store.name')->all())->toBe(['Lidl', 'Cactus'])
        ->and($product['prices'][0]['label'])->toContain('8,00')->toContain('kg')
        ->and($product['prices'][1]['label'])->toContain('9,44')
        ->and($product['gap'])->toBe(0.18);                            // 18 % plus cher chez Cactus
});

test('un prix de plus de 6 mois est ignoré, un prix de plus de 3 mois est signalé ancien', function () {
    priceAt('Beurre', $this->cactus, 2.00, 250, 'g', '2026-02-01');   // plus de 180 jours
    priceAt('Beurre', $this->lidl, 2.00, 250, 'g', '2026-05-20');     // 119 jours : ancien

    $product = app(PriceComparison::class)->products()->sole();

    expect($product['prices'])->toHaveCount(1)
        ->and($product['prices'][0]['store']->name)->toBe('Lidl')
        ->and($product['prices'][0]['stale'])->toBeTrue()
        ->and($product['gap'])->toBeNull();
});

test('un prix au kilo ne se compare pas à un prix à la boîte', function () {
    priceAt('Beurre', $this->cactus, 2.00, 250, 'g', '2026-09-01');
    priceAt('Beurre', $this->lidl, 1.50, 1, 'boite', '2026-09-01');

    $products = app(PriceComparison::class)->products();

    expect($products)->toHaveCount(2)
        ->and($products->every(fn ($p) => $p['prices']->count() === 1))->toBeTrue();
});

test('deux magasins ne se comparent qu\'avec au moins trois produits en commun', function () {
    priceAt('Beurre', $this->cactus, 2.00, 250, 'g', '2026-09-01');
    priceAt('Beurre', $this->lidl, 1.80, 250, 'g', '2026-09-01');
    priceAt('Farine', $this->cactus, 1.00, 1, 'kg', '2026-09-01');
    priceAt('Farine', $this->lidl, 0.90, 1, 'kg', '2026-09-01');

    $stores = app(PriceComparison::class)->stores();

    expect($stores['reference']->name)->toBe('Cactus')                // magasin par défaut des listes
        ->and($stores['stores']->firstWhere('store.name', 'Lidl')['common'])->toBe(2)
        ->and($stores['stores']->firstWhere('store.name', 'Lidl')['diff'])->toBeNull();

    priceAt('Lait demi-écrémé', $this->cactus, 1.00, 1, 'l', '2026-09-01');
    priceAt('Lait demi-écrémé', $this->lidl, 0.90, 1, 'l', '2026-09-01');

    $lidl = (new PriceComparison(app(PriceBook::class)))->stores()['stores']->firstWhere('store.name', 'Lidl');

    expect($lidl['common'])->toBe(3)
        ->and($lidl['diff'])->toBe(-0.1)                               // 10 % moins cher, sur 3 produits
        ->and($lidl['cheapest'])->toBe(3);
});

/* ================================================================ C1 — liste répartie */

function listAtCactus(Store $cactus): ShoppingList
{
    $list = ShoppingList::create(['name' => 'Semaine du 16', 'period_start' => '2026-09-16', 'period_end' => '2026-09-20', 'store_id' => $cactus->id]);
    $manager = app(ShoppingListManager::class);

    foreach (['Beurre' => [500, 'g'], 'Farine' => [2, 'kg'], 'Lait demi-écrémé' => [null, null]] as $name => [$q, $unit]) {
        $item = $manager->addManual($list, $name);
        $item->update(['quantity' => $q, 'unit_id' => $unit ? Unit::firstWhere('code', $unit)->id : null]);
    }

    return $list;
}

test('la liste propose les articles moins chers dans un autre magasin, avec l\'économie estimée', function () {
    $list = listAtCactus($this->cactus);
    priceAt('Beurre', $this->cactus, 10.00, 1, 'kg', '2026-09-01');
    priceAt('Beurre', $this->lidl, 8.00, 1, 'kg', '2026-09-01');       // −20 % : 500 g → 1,00 € d'économie
    priceAt('Farine', $this->cactus, 1.00, 1, 'kg', '2026-09-01');
    priceAt('Farine', $this->lidl, 0.97, 1, 'kg', '2026-09-01');       // −3 % : pas assez pour déplacer
    priceAt('Lait demi-écrémé', $this->lidl, 0.50, 1, 'l', '2026-09-01');   // prix inconnu chez Cactus : on ne sait pas

    $suggestions = app(StoreSplit::class)->suggestions($list);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['store']->name)->toBe('Lidl')
        ->and($suggestions[0]['items']->pluck('item.label')->all())->toBe(['Beurre'])
        ->and($suggestions[0]['items'][0]['gap'])->toBe(0.2)
        ->and($suggestions[0]['saving'])->toBe(1.0)
        ->and($suggestions[0]['unknown'])->toBe(0);
});

test('un article sans quantité comparable est proposé sans chiffrer l\'économie', function () {
    $list = listAtCactus($this->cactus);
    priceAt('Lait demi-écrémé', $this->cactus, 1.20, 1, 'l', '2026-09-01');
    priceAt('Lait demi-écrémé', $this->lidl, 0.90, 1, 'l', '2026-09-01');

    $group = app(StoreSplit::class)->suggestions($list)->sole();

    expect($group['saving'])->toBe(0.0)
        ->and($group['unknown'])->toBe(1);
});

test('répartir la liste range les articles « Chez Lidl », et on peut tout reprendre', function () {
    $list = listAtCactus($this->cactus);
    priceAt('Beurre', $this->cactus, 10.00, 1, 'kg', '2026-09-01');
    priceAt('Beurre', $this->lidl, 8.00, 1, 'kg', '2026-09-01');

    Livewire::test(ShoppingShow::class, ['shoppingList' => $list])
        ->assertSee('Moins cher ailleurs')
        ->assertSee('Les acheter chez Lidl')
        ->call('splitTo', $this->lidl->id)
        ->assertDontSee('Les acheter chez Lidl')
        ->assertSee('Chez Lidl')
        ->assertSee('Tout reprendre ici');

    $beurre = $list->items()->where('label', 'Beurre')->first();
    expect($beurre->store_id)->toBe($this->lidl->id);

    Livewire::test(ShoppingShow::class, ['shoppingList' => $list])
        ->call('bringBack', $this->lidl->id)
        ->assertDontSee('Tout reprendre ici')
        ->assertSee('Les acheter chez Lidl');

    expect($beurre->fresh()->store_id)->toBeNull();
});

test('sans magasin choisi pour la liste, rien n\'est proposé', function () {
    $list = listAtCactus($this->cactus);
    $list->update(['store_id' => null]);
    priceAt('Beurre', $this->cactus, 10.00, 1, 'kg', '2026-09-01');
    priceAt('Beurre', $this->lidl, 8.00, 1, 'kg', '2026-09-01');

    expect(app(StoreSplit::class)->suggestions($list->fresh()))->toBeEmpty();
});

/* ================================================================ C2 — inflation personnelle */

test('l\'indice du panier pondère chaque produit par ce qu\'on y a dépensé', function () {
    priceAt('Beurre', $this->cactus, 2.00, 250, 'g', '2025-10-05');
    priceAt('Beurre', $this->cactus, 2.20, 250, 'g', '2026-09-01');   // +10 %, 4,20 € dépensés
    priceAt('Lait demi-écrémé', $this->cactus, 1.00, 1, 'l', '2025-10-10');
    priceAt('Lait demi-écrémé', $this->cactus, 1.00, 1, 'l', '2026-08-20');   // =, 2,00 €
    priceAt('Farine', $this->cactus, 1.25, 1, 'kg', '2025-11-02');
    priceAt('Farine', $this->cactus, 1.20, 1, 'kg', '2026-09-10');   // −4 %, 2,45 €

    $index = app(PersonalInflation::class)->index();

    // (4,20 × 1,10 + 2,00 × 1 + 2,45 × 0,96) ÷ 8,65 = 1,0372 → 103,7
    expect($index['basket'])->toBe(3)
        ->and($index['months'])->toHaveCount(12)
        ->and($index['months'][0]['index'])->toBe(100.0)
        ->and(end($index['months'])['index'])->toBe(103.7)
        ->and($index['change'])->toBe(0.037)
        ->and($index['since']->toDateString())->toBe('2025-10-01');
});

test('changer de magasin n\'est pas de l\'inflation, et trop peu de produits ne font pas un indice', function () {
    priceAt('Beurre', $this->cactus, 2.00, 250, 'g', '2026-06-05');
    priceAt('Beurre', $this->lidl, 3.00, 250, 'g', '2026-09-01');     // autre magasin : autre série
    priceAt('Farine', $this->cactus, 1.00, 1, 'kg', '2026-06-05');
    priceAt('Farine', $this->cactus, 1.10, 1, 'kg', '2026-09-01');

    $inflation = app(PersonalInflation::class);
    $index = $inflation->index();

    expect($index['change'])->toBeNull()
        ->and($index['basket'])->toBe(1)
        ->and($inflation->series()->pluck('ingredient.name')->all())->toBe(['Farine']);
});

test('une hausse marquée récente est signalée, pas une baisse ni une hausse ancienne', function () {
    priceAt('Beurre', $this->cactus, 2.00, 250, 'g', '2026-08-01');
    priceAt('Beurre', $this->cactus, 2.40, 250, 'g', '2026-09-12');   // +20 %
    priceAt('Farine', $this->cactus, 1.00, 1, 'kg', '2026-08-01');
    priceAt('Farine', $this->cactus, 0.80, 1, 'kg', '2026-09-12');    // baisse
    priceAt('Lait demi-écrémé', $this->cactus, 1.00, 1, 'l', '2026-05-01');
    priceAt('Lait demi-écrémé', $this->cactus, 1.30, 1, 'l', '2026-06-01');  // +30 %, mais il y a plus de 60 jours

    $rises = app(PersonalInflation::class)->rises();

    expect($rises)->toHaveCount(1)
        ->and($rises[0]['ingredient']->name)->toBe('Beurre')
        ->and($rises[0]['change'])->toBe(0.2)
        ->and($rises[0]['label_after'])->toContain('9,60');
});

test('la hausse apparaît sur l\'accueil, bloc masquable', function () {
    priceAt('Beurre', $this->cactus, 2.00, 250, 'g', '2026-08-01');
    priceAt('Beurre', $this->cactus, 2.40, 250, 'g', '2026-09-12');

    expect(Navigation::HOME_SECTIONS)->toHaveKey('prices');

    Livewire::test(Dashboard::class)->assertSee('Hausses de prix')->assertSee('+20');

    $this->user->setPreference('home', [['key' => 'prices', 'visible' => false]]);

    Livewire::test(Dashboard::class)->assertDontSee('Hausses de prix');
});

/* ================================================================ Relevés */

test('supprimer un relevé faux rend au produit le prix du relevé précédent', function () {
    $beurre = Ingredient::firstWhere('name', 'Beurre');
    priceAt('Beurre', $this->cactus, 2.00, 250, 'g', '2026-09-01');
    $wrong = priceAt('Beurre', $this->cactus, 20.00, 250, 'g', '2026-09-10');   // virgule mal lue

    expect((float) $beurre->fresh()->reference_price)->toBe(0.08);

    $this->book->forget($wrong);

    expect((float) $beurre->fresh()->reference_price)->toBe(0.008)
        ->and($beurre->fresh()->reference_price_on->toDateString())->toBe('2026-09-01');
});

test('un prix noté après coup, plus ancien, ne remplace pas le prix retenu', function () {
    $beurre = Ingredient::firstWhere('name', 'Beurre');
    priceAt('Beurre', $this->cactus, 2.40, 250, 'g', '2026-09-10');
    priceAt('Beurre', $this->lidl, 2.00, 250, 'g', '2026-08-01');

    expect((float) $beurre->fresh()->reference_price)->toBe(0.0096);
});

/* ================================================================ Page « Prix et magasins » */

test('la page compare les magasins et montre l\'évolution', function () {
    priceAt('Beurre', $this->cactus, 2.36, 250, 'g', '2026-09-10');
    priceAt('Beurre', $this->lidl, 4.00, 500, 'g', '2026-09-05');
    priceAt('Farine', $this->cactus, 1.00, 1, 'kg', '2026-09-05');

    $this->get(route('prices.index'))->assertOk()->assertSee('Prix et magasins');

    Livewire::test(PricesPage::class)
        ->assertSee('Les magasins face à Cactus')
        ->assertSee('Beurre')
        ->assertDontSee('Farine')                                      // un seul magasin : masqué par défaut
        ->set('all', true)
        ->assertSee('Farine')
        ->set('search', 'beur')
        ->assertDontSee('Farine')
        ->call('toggle', Ingredient::firstWhere('name', 'Beurre')->id.'|'.$this->u['g'])
        ->assertSee('Derniers relevés')
        ->set('tab', 'evolution')
        ->assertSee('Notre panier')
        ->assertSee('Pas encore assez de recul');
});

test('noter un prix en rayon l\'enregistre pour ce magasin', function () {
    Livewire::test(PricesPage::class)
        ->call('openForm')
        ->assertSet('noteStoreId', $this->cactus->id)
        ->set('noteName', 'Produit inconnu')
        ->set('notePrice', '2,49')
        ->call('savePrice')
        ->assertHasErrors('noteName')
        ->set('noteName', 'Beurre')
        ->set('noteStoreId', $this->lidl->id)
        ->set('noteQuantity', '500')
        ->set('noteUnitId', $this->u['g'])
        ->call('savePrice')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    $price = IngredientPrice::sole();

    expect($price->store_id)->toBe($this->lidl->id)
        ->and($price->source)->toBe(IngredientPrice::MANUAL)
        ->and((float) $price->unit_price)->toBe(0.00498);
});

test('un compte en lecture seule consulte les prix mais n\'en note pas', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $this->actingAs($viewer);
    $price = priceAt('Beurre', $this->cactus, 2.00, 250, 'g', '2026-09-01');

    Livewire::test(PricesPage::class)
        ->set('all', true)
        ->assertSee('Beurre')
        ->assertDontSee('Noter un prix')
        ->call('openForm')
        ->assertForbidden();

    Livewire::test(PricesPage::class)->call('deletePrice', $price->id)->assertForbidden();
    expect(IngredientPrice::count())->toBe(1);
});
