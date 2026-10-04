<?php

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\Tag;
use App\Models\Unit;
use Database\Seeders\DatabaseSeeder;

test('les données de départ sont chargées', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Unit::count())->toBe(17)
        ->and(Aisle::count())->toBe(15)
        ->and(Tag::count())->toBe(16)
        ->and(MealSlot::count())->toBe(4)
        ->and(MealSlot::active()->pluck('name')->all())->toBe(['Déjeuner', 'Dîner'])
        ->and(Ingredient::count())->toBeGreaterThan(140);

    $onion = Ingredient::firstWhere('name', 'Oignon');
    expect($onion->aisle->name)->toBe('Fruits & légumes')
        ->and($onion->defaultUnit->code)->toBe('piece')
        ->and((float) $onion->piece_weight_g)->toBe(150.0)
        ->and(Ingredient::firstWhere('name', 'Sel')->is_staple)->toBeTrue();
});

test('les seeders sont ré-exécutables sans doublon ni écrasement', function () {
    $this->seed(DatabaseSeeder::class);

    Aisle::firstWhere('name', 'Boulangerie')->update(['color' => 'red', 'sort_order' => 99]);
    Ingredient::firstWhere('name', 'Tomate')->update(['name' => 'Tomates']);
    $counts = [Unit::count(), Aisle::count(), Tag::count(), MealSlot::count(), Ingredient::count()];

    $this->seed(DatabaseSeeder::class);

    expect([Unit::count(), Aisle::count(), Tag::count(), MealSlot::count(), Ingredient::count()])->toBe($counts)
        ->and(Aisle::firstWhere('name', 'Boulangerie')->color)->toBe('red');
});

test('les rayons sont dans l\'ordre générique du magasin', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Aisle::ordered()->limit(3)->pluck('name')->all())
        ->toBe(['Fruits & légumes', 'Boulangerie', 'Boucherie & volaille']);
});
