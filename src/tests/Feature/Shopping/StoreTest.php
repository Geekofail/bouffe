<?php

use App\Livewire\Settings\Stores as StoresPage;
use App\Livewire\Shopping\Index as ShoppingIndex;
use App\Livewire\Shopping\Show as ShoppingShow;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\StandingItem;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Services\Pricing\Budget;
use App\Services\Pricing\PriceBook;
use App\Services\Shopping\ShoppingListManager;
use App\Services\Shopping\StoreLayout;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Magasins (15.3), articles fréquents (15.6), prix payé (15.7),
 * liste « quand je passe » (15.8) et budget (17.3).
 */

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    $this->actingAs($this->user = User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class]);

    $this->manager = app(ShoppingListManager::class);
    $this->layout = app(StoreLayout::class);

    $this->list = ShoppingList::create(['name' => 'Semaine du 16', 'period_start' => '2026-09-16', 'period_end' => '2026-09-20']);
});

/* ================================================================ Magasins (15.3) */

test('un nouveau magasin reprend l\'ordre général des rayons', function () {
    $store = Store::create(['name' => 'Cactus']);
    $this->layout->sync($store);

    expect($store->storeAisles()->count())->toBe(Aisle::count())
        ->and(array_keys($this->layout->order($store)))->toBe(Aisle::query()->ordered()->pluck('id')->all());
});

test('l\'ordre des rayons d\'un magasin est propre à ce magasin', function () {
    $cactus = Store::create(['name' => 'Cactus']);
    $marche = Store::create(['name' => 'Marché']);

    $last = Aisle::query()->ordered()->get()->last();
    $this->layout->move($cactus, $last->id, 0);   // ce rayon est le premier chez Cactus

    expect(array_key_first($this->layout->order($cactus)))->toBe($last->id)
        ->and(array_key_first($this->layout->order($marche)))->not->toBe($last->id);
});

test('la liste est affichée dans l\'ordre du magasin choisi', function () {
    $store = Store::create(['name' => 'Cactus']);
    $this->list->update(['store_id' => $store->id]);

    $beurre = $this->manager->addManual($this->list, 'Beurre');
    $pommes = $this->manager->addManual($this->list, 'Pommes');

    $before = collect($this->manager->grouped($this->list)['aisles'])->pluck('aisle.id')->all();

    expect($beurre->aisle_id)->not->toBe($pommes->aisle_id);

    // On met en tête du parcours le rayon qui n'y était pas.
    $last = $before[1];
    $this->layout->move($store, $last, 0);

    $after = collect($this->manager->grouped($this->list->fresh())['aisles'])->pluck('aisle.id')->all();

    expect($after[0])->toBe($last)
        ->and($after)->toBe(array_reverse($before));
});

test('un article réservé à un autre magasin est montré à part, pas caché', function () {
    $cactus = Store::create(['name' => 'Cactus']);
    $marche = Store::create(['name' => 'Marché']);
    $this->list->update(['store_id' => $cactus->id]);

    $this->manager->addManual($this->list, 'Beurre');
    $tomates = $this->manager->addManual($this->list, 'Tomate');
    $tomates->update(['store_id' => $marche->id]);

    $grouped = $this->manager->grouped($this->list->fresh());

    expect($grouped['elsewhere']->pluck('label'))->toContain('Tomate')
        ->and($grouped['aisles']->flatMap(fn ($g) => $g['items'])->pluck('label'))->not->toContain('Tomate')
        ->and($grouped['aisles']->flatMap(fn ($g) => $g['items'])->pluck('label'))->toContain('Beurre');
});

test('un rayon masqué dans ce magasin envoie ses articles dans « Ailleurs »', function () {
    $store = Store::create(['name' => 'Cactus']);
    $this->list->update(['store_id' => $store->id]);

    $beurre = $this->manager->addManual($this->list, 'Beurre');
    $this->layout->toggleHidden($store, $beurre->aisle_id);

    $grouped = $this->manager->grouped($this->list->fresh());

    expect($grouped['elsewhere']->pluck('label'))->toContain('Beurre');
});

test('l\'écran des magasins crée, réordonne et masque', function () {
    $page = Livewire::test(StoresPage::class)->set('newName', 'Cactus')->call('add');

    $store = Store::firstWhere('name', 'Cactus');

    expect($store)->not->toBeNull()
        ->and($store->is_default)->toBeTrue();      // premier magasin → proposé par défaut

    $aisle = Aisle::query()->ordered()->get()->last();

    $page->call('sort', $aisle->id, 0)->call('toggleHidden', $aisle->id);

    expect(array_key_first($this->layout->order($store)))->toBe($aisle->id)
        ->and($this->layout->hidden($store))->toContain($aisle->id);
});

test('une nouvelle liste prend le magasin habituel', function () {
    Store::create(['name' => 'Delhaize']);
    $cactus = Store::create(['name' => 'Cactus', 'is_default' => true]);

    $list = $this->manager->create(Carbon::parse('2026-09-21'), Carbon::parse('2026-09-27'));

    expect($list->store_id)->toBe($cactus->id);
});

/* ================================================ Quand je passe (15.8) */

test('la liste « quand je passe » se vide dans la prochaine liste créée', function () {
    Livewire::test(ShoppingIndex::class)->set('standingLabel', 'Piles AAA')->call('addStanding');

    expect(StandingItem::waiting()->count())->toBe(1);

    $list = $this->manager->create(Carbon::parse('2026-09-21'), Carbon::parse('2026-09-27'));

    expect($list->items()->where('label', 'Piles AAA')->exists())->toBeTrue()
        ->and(StandingItem::waiting()->count())->toBe(0)
        ->and(StandingItem::first()->added_to_list_id)->toBe($list->id);
});

test('un article « quand je passe » reconnu est rangé dans son rayon', function () {
    Livewire::test(ShoppingIndex::class)->set('standingLabel', 'beurre')->call('addStanding');

    $standing = StandingItem::first();

    expect($standing->ingredient_id)->toBe(Ingredient::firstWhere('name', 'Beurre')->id)
        ->and($standing->aisle_id)->not->toBeNull()
        ->and($standing->label)->toBe('Beurre');
});

/* ================================================ Articles fréquents (15.6) */

test('les articles souvent achetés et absents sont proposés', function () {
    $lait = Ingredient::firstWhere('name', 'Lait demi-écrémé');
    $beurre = Ingredient::firstWhere('name', 'Beurre');

    // Trois listes passées : le lait coché deux fois, le beurre une seule.
    foreach ([['2026-09-01', [$lait, $beurre]], ['2026-09-08', [$lait]]] as [$date, $ingredients]) {
        $past = ShoppingList::create(['name' => 'Anciennes courses', 'period_start' => $date, 'period_end' => $date]);

        foreach ($ingredients as $ingredient) {
            $past->items()->create([
                'ingredient_id' => $ingredient->id, 'label' => $ingredient->name, 'aisle_id' => $ingredient->aisle_id,
                'origin' => 'manual', 'is_checked' => true, 'checked_at' => Carbon::parse($date),
            ]);
        }
    }

    $frequent = $this->manager->frequentItems($this->list);

    expect($frequent->pluck('ingredient.name'))->toContain('Lait demi-écrémé')
        ->and($frequent->pluck('ingredient.name'))->not->toContain('Beurre')   // une seule fois : pas « souvent »
        ->and($frequent->first()['count'])->toBe(2);

    // Déjà dans la liste en cours → plus proposé.
    $this->manager->addManual($this->list, 'Lait demi-écrémé');

    expect($this->manager->frequentItems($this->list->fresh())->pluck('ingredient.name'))->not->toContain('Lait demi-écrémé');
});

/* ================================================ Prix payé et budget (15.7, 17.3) */

test('le prix payé saisi sur un article alimente le prix de référence et le budget', function () {
    $store = Store::create(['name' => 'Cactus']);
    $this->list->update(['store_id' => $store->id]);

    $item = $this->manager->addManual($this->list, 'Beurre');
    $item->update(['quantity' => 500, 'unit_id' => Unit::firstWhere('code', 'g')->id, 'is_checked' => true, 'checked_at' => now()]);

    Livewire::test(ShoppingShow::class, ['shoppingList' => $this->list])
        ->call('edit', $item->id)
        ->set('editPrice', '2,49')
        ->call('saveEdit');

    $beurre = Ingredient::firstWhere('name', 'Beurre')->fresh();

    expect((float) $item->fresh()->paid_price)->toBe(2.49)
        ->and((float) $beurre->reference_price)->toBe(0.00498)
        ->and(app(PriceBook::class)->referenceLabel($beurre))->toContain('4,98')
        ->and(app(Budget::class)->spent(Carbon::parse('2026-09-16')))->toBe(2.49);
});

test('le budget du mois se compare au montant fixé', function () {
    $budget = app(Budget::class);
    $budget->setMonthly(300);

    $item = $this->manager->addManual($this->list, 'Beurre');
    $item->update(['is_checked' => true, 'checked_at' => now(), 'paid_price' => 120]);

    $status = $budget->status();

    expect($status['budget'])->toBe(300.0)
        ->and($status['spent'])->toBe(120.0)
        ->and($status['remaining'])->toBe(180.0)
        ->and($status['over'])->toBeFalse()
        ->and($status['share'])->toBe(40.0);

    $months = collect($budget->months(3));

    expect($months)->toHaveCount(3)
        ->and($months->last()['spent'])->toBe(120.0)
        ->and($months->first()['spent'])->toBe(0.0);
});

test('sans budget fixé, le suivi affiche simplement les dépenses', function () {
    $budget = app(Budget::class);

    expect($budget->monthly())->toBeNull()
        ->and($budget->status()['share'])->toBeNull()
        ->and($budget->status()['over'])->toBeFalse();
});

test('le coût d\'une liste additionne les prix payés et les estimations', function () {
    $beurre = Ingredient::firstWhere('name', 'Beurre');
    app(PriceBook::class)->record($beurre, 5.00, 1, Unit::firstWhere('code', 'kg'));

    $item = $this->manager->addManual($this->list, 'Beurre');
    $item->update(['quantity' => 200, 'unit_id' => Unit::firstWhere('code', 'g')->id]);

    $paye = $this->manager->addManual($this->list, 'Lessive');
    $paye->update(['is_checked' => true, 'checked_at' => now(), 'paid_price' => 8.90]);

    $cost = app(\App\Services\Pricing\CostCalculator::class)->shoppingList($this->list->fresh());

    expect(round($cost->total, 2))->toBe(9.90)      // 1,00 € de beurre estimé + 8,90 € payés
        ->and(round($cost->paid, 2))->toBe(8.90)
        ->and($cost->missing)->toBe(0);
});

test('la page Budget s\'ouvre et enregistre le montant', function () {
    // Depuis le lot 22, le budget du lot 17 est celui du poste « Courses alimentaires ».
    $groceries = \App\Models\BudgetCategory::groceries();

    Livewire::test(\App\Livewire\Settings\BudgetSettings::class)
        ->assertSee('Postes et budget mensuel')
        ->set("rows.{$groceries->id}.budget", '450')
        ->call('saveCategories');

    expect(app(Budget::class)->monthly())->toBe(450.0);
});
