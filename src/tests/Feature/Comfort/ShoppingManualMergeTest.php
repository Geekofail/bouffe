<?php

use App\Enums\ItemOrigin;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\Unit;
use App\Models\User;
use App\Services\Planning\WeekPlanner;
use App\Services\Shopping\ShoppingListManager;
use App\Services\Stock\StockManager;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Shopping/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    $this->actingAs(User::factory()->create());
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    $this->manager = app(ShoppingListManager::class);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner']);

    $recipe = recipeWith('Gratin', 2, [[250, 'g', 'Beurre', false], [3, 'piece', 'Œuf', false]]);
    app(WeekPlanner::class)->addRecipe('2026-09-18', $this->dinner, $recipe, 2);
    $this->list = $this->manager->create(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'));
});

function manualItem($list, string $ingredient, ?float $quantity, ?string $unit)
{
    $item = app(ShoppingListManager::class)->addManual($list, $ingredient);
    $item->update(['quantity' => $quantity, 'unit_id' => $unit ? Unit::firstWhere('code', $unit)->id : null]);

    return $item;
}

test('à la régénération, un article manuel du même ingrédient est fusionné dans l\'article calculé', function () {
    manualItem($this->list, 'Beurre', 0.2, 'kg');          // 200 g ajoutés à la main
    $this->manager->regenerate($this->list);

    $butter = $this->list->items()->where('ingredient_id', Ingredient::firstWhere('name', 'Beurre')->id)->get();

    expect($butter)->toHaveCount(1)
        ->and($butter->first()->origin)->toBe(ItemOrigin::Generated)
        ->and((float) $butter->first()->quantity)->toBe(450.0)
        ->and((float) $butter->first()->added_quantity)->toBe(200.0)
        ->and($butter->first()->stock_note)->toBe('dont 200 g ajouté à la main');

    // Une nouvelle régénération ne double pas l'ajout
    $this->manager->regenerate($this->list);
    expect((float) $this->list->items()->firstWhere('label', 'Beurre')->quantity)->toBe(450.0);
});

test('unité non convertible, article coché ou article manuel sans quantité', function () {
    $eggs = Ingredient::firstWhere('name', 'Œuf');

    manualItem($this->list, 'Œuf', 1, 'boite');        // boîte d'œufs : pas convertible en pièces → reste séparé
    $this->manager->regenerate($this->list);
    expect($this->list->items()->where('ingredient_id', $eggs->id)->count())->toBe(2);

    $generatedEggs = $this->list->items()->where('ingredient_id', $eggs->id)->where('origin', 'generated')->first();
    $generatedEggs->update(['is_checked' => true]);
    manualItem($this->list, 'Œuf', 2, 'piece');
    $this->manager->regenerate($this->list);
    expect($this->list->items()->where('ingredient_id', $eggs->id)->count())->toBe(3);   // article calculé coché : on ne touche pas

    $butterId = Ingredient::firstWhere('name', 'Beurre')->id;
    $this->manager->addManual($this->list, 'Beurre');   // sans quantité
    $this->manager->regenerate($this->list);
    $butter = $this->list->items()->where('ingredient_id', $butterId)->sole();
    expect((float) $butter->quantity)->toBe(250.0)->and($butter->stock_note)->toBe('aussi ajouté à la main');
});

test('article couvert par le stock : la quantité ajoutée à la main reste à acheter', function () {
    app(StockManager::class)->add(['ingredient_id' => Ingredient::firstWhere('name', 'Beurre')->id, 'quantity' => 500]);
    $this->manager->regenerate($this->list);
    expect($this->list->items()->firstWhere('label', 'Beurre')->stock_status)->toBe('covered');

    manualItem($this->list, 'Beurre', 125, 'g');
    $this->manager->regenerate($this->list);

    $butter = $this->list->items()->firstWhere('label', 'Beurre');
    expect($butter->stock_status)->toBe('partial')
        ->and((float) $butter->quantity)->toBe(125.0)
        ->and($butter->stock_note)->toContain('dont 125 g ajouté à la main');
});
