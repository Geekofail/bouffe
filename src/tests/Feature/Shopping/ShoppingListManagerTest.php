<?php

use App\Enums\ItemOrigin;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\RecurringItem;
use App\Models\ShoppingListItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\Planning\WeekPlanner;
use App\Services\Shopping\ShoppingItemPresenter;
use App\Services\Shopping\ShoppingListManager;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    $this->actingAs($this->user = User::factory()->create());
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class]);
    $this->planner = app(WeekPlanner::class);
    $this->manager = app(ShoppingListManager::class);
    $this->presenter = app(ShoppingItemPresenter::class);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner']);

    $this->tart = recipeWith('Tarte', 4, [[250, 'g', 'Farine', false], [3, 'piece', 'Œuf', false], [2, 'piece', 'Tomate', false], [null, null, 'Sel', false]]);
    $this->meal = $this->planner->addRecipe('2026-09-17', $this->dinner, $this->tart, 4);
});

function texts($list): array
{
    $presenter = app(ShoppingItemPresenter::class);

    return $list->items()->with('ingredient')->get()->mapWithKeys(fn ($i) => [$i->label => $presenter->text($i)])->sortKeys()->all();
}

test('création : articles générés, produits de base, articles récurrents et nom par défaut', function () {
    RecurringItem::create(['label' => 'Café', 'aisle_id' => Aisle::firstWhere('name', 'Boissons')->id]);
    RecurringItem::create(['label' => 'Œufs frais', 'ingredient_id' => Ingredient::firstWhere('name', 'Œuf')->id]); // déjà présent
    RecurringItem::create(['label' => 'Inactif', 'is_active' => false]);

    $list = $this->manager->create(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'));

    expect($list->name)->toBe('Courses du 14 sept. au 20 sept.')
        ->and($list->generated_at)->not->toBeNull()
        ->and(texts($list))->toBe([
            'Café' => 'Café',
            'Farine' => '250 g de farine',
            'Sel' => 'Sel',
            'Tomate' => '2 tomates',
            'Œuf' => '3 œufs',
        ]);

    $items = $list->items()->get()->keyBy('label');
    expect($items->keys()->sort()->values()->all())->toBe(['Café', 'Farine', 'Sel', 'Tomate', 'Œuf'])
        ->and($items['Farine']->origin)->toBe(ItemOrigin::Staple)
        ->and($items['Sel']->origin)->toBe(ItemOrigin::Staple)
        ->and($items['Tomate']->origin)->toBe(ItemOrigin::Generated)
        ->and($items['Café']->origin)->toBe(ItemOrigin::Recurring)
        ->and($this->presenter->text($items['Œuf']))->toBe('3 œufs')
        ->and($items['Tomate']->sources()->first()->recipe_title)->toBe('Tarte');
});

test('regroupement par rayon dans l\'ordre du magasin, cochés en bas, placard à part', function () {
    $list = $this->manager->create(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'));
    $this->manager->addManual($list, 'Lessive');
    $this->manager->addManual($list, '2 baguettes');

    $grouped = $this->manager->grouped($list);

    expect($grouped['aisles']->map(fn ($g) => $g['aisle']?->name)->all())->toBe(['Fruits & légumes', 'Boulangerie', 'Crèmerie & œufs', 'Divers'])
        ->and($grouped['staples']->pluck('label')->all())->toBe(['Farine', 'Sel'])
        ->and($grouped['aisles'][1]['items']->first()->ingredient->name)->toBe('Baguette');
});

test('cocher, modifier la quantité, retirer, restaurer, supprimer', function () {
    $list = $this->manager->create(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'));
    $tomato = $list->items()->where('label', 'Tomate')->sole();

    $this->manager->toggleCheck($tomato);
    expect($tomato->fresh())->is_checked->toBeTrue()->checked_by->toBe($this->user->id);

    $this->manager->updateQuantity($tomato, '5', Unit::firstWhere('code', 'piece')->id);
    expect($this->presenter->text($tomato->fresh('ingredient')))->toBe('5 tomates')
        ->and($tomato->fresh()->quantity_overridden)->toBeTrue();

    $this->manager->setRemoved($tomato, true);
    expect($tomato->fresh())->is_removed->toBeTrue()->is_checked->toBeFalse();

    $this->manager->deleteItem($tomato);   // article du planning : reste « retiré », pas supprimé
    expect(ShoppingListItem::find($tomato->id))->not->toBeNull();

    $manual = $this->manager->addManual($list, 'Papier toilette');
    $this->manager->deleteItem($manual);
    expect(ShoppingListItem::find($manual->id))->toBeNull()
        ->and(fn () => $this->manager->addManual($list, '   '))->toThrow(InvalidArgumentException::class);
});

test('mise à jour depuis le planning : différences, cochés conservés si la quantité ne monte pas', function () {
    $list = $this->manager->create(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'));
    $egg = $list->items()->where('label', 'Œuf')->sole();
    $tomato = $list->items()->where('label', 'Tomate')->sole();
    $flour = $list->items()->where('label', 'Farine')->sole();
    $this->manager->toggleCheck($egg);
    $this->manager->toggleCheck($flour);
    $this->manager->updateQuantity($tomato, '6', Unit::firstWhere('code', 'piece')->id);
    $manual = $this->manager->addManual($list, 'Lessive');

    // Nouveau repas qui ajoute de la farine et un avocat ; les œufs ne changent pas.
    $salad = recipeWith('Salade', 2, [[1, 'piece', 'Avocat', false], [50, 'g', 'Farine', false]]);
    $this->planner->addRecipe('2026-09-18', $this->dinner, $salad);

    $changes = $this->manager->regenerate($list->fresh());

    expect($changes)->toContain('Ajouté : 1 avocat')
        ->toContain('Modifié : 250 g de farine → 300 g de farine')
        ->and($egg->fresh()->is_checked)->toBeTrue()          // quantité inchangée : reste cochée
        ->and($flour->fresh()->is_checked)->toBeFalse()       // quantité augmentée : décochée
        ->and((float) $tomato->fresh()->quantity)->toBe(6.0)  // saisie manuelle conservée
        ->and($tomato->fresh()->sources()->count())->toBe(1)
        ->and(ShoppingListItem::find($manual->id))->not->toBeNull();

    // Suppression du repas : ses articles disparaissent
    $this->planner->delete($this->meal);
    $changes = $this->manager->regenerate($list->fresh());

    expect($changes)->toContain('Retiré : 3 œufs')
        ->and($list->items()->pluck('label')->sort()->values()->all())->toBe(['Avocat', 'Farine', 'Lessive']);
});

test('revenir à la quantité calculée en vidant le champ', function () {
    $list = $this->manager->create(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'));
    $tomato = $list->items()->where('label', 'Tomate')->sole();

    $this->manager->updateQuantity($tomato, '9', null);
    $this->manager->updateQuantity($tomato->fresh(), '', null);

    expect($this->presenter->text($tomato->fresh('ingredient')))->toBe('2 tomates')
        ->and($tomato->fresh()->quantity_overridden)->toBeFalse();
});

test('liste en texte brut sans les articles cochés', function () {
    $list = $this->manager->create(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'), name: 'Courses de la semaine');
    $this->manager->toggleCheck($list->items()->where('label', 'Tomate')->sole());

    expect($this->manager->toText($list))->toBe(implode("\n", [
        'Courses de la semaine',
        '',
        'CRÈMERIE & ŒUFS',
        '- 3 œufs',
        '',
        'À VÉRIFIER DANS LE PLACARD',
        '- 250 g de farine',
        '- Sel',
    ])."\n");
});

test('reconnaissance d\'un ingrédient dans un libellé manuel', function (string $label, ?string $expected) {
    expect($this->manager->matchIngredient($label)?->name)->toBe($expected);
})->with([
    ['Tomates', 'Tomate'],
    ['2 baguettes', 'Baguette'],
    ['1 kg de pommes de terre', 'Pomme de terre'],
    ['Lessive', null],
]);

test('période invalide refusée', function () {
    $this->manager->create(Carbon::parse('2026-09-20'), Carbon::parse('2026-09-14'));
})->throws(InvalidArgumentException::class);
