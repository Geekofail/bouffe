<?php

use App\Livewire\Settings\Ingredients;
use App\Livewire\Settings\Tags;
use App\Livewire\Settings\Units;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

test('un ingrédient utilisé dans une recette ne peut pas être supprimé', function () {
    $ingredient = Ingredient::factory()->create();
    Recipe::factory()->create()->ingredients()->create(['ingredient_id' => $ingredient->id, 'sort_order' => 1]);

    Livewire::test(Ingredients::class)
        ->call('delete', $ingredient->id)
        ->assertDispatched('notify', type: 'warning');

    expect($ingredient->fresh())->not->toBeNull();
});

test('une unité utilisée dans une recette ne peut pas être supprimée', function () {
    $unit = Unit::factory()->create(['code' => 'bol']);
    Recipe::factory()->create()->ingredients()->create(['ingredient_id' => Ingredient::factory()->create()->id, 'unit_id' => $unit->id, 'sort_order' => 1]);

    Livewire::test(Units::class)->call('delete', $unit->id)->assertDispatched('notify', type: 'warning');

    expect($unit->fresh())->not->toBeNull();
});

test('supprimer une catégorie la retire simplement des recettes', function () {
    $tag = Tag::factory()->create();
    $recipe = Recipe::factory()->create();
    $recipe->tags()->attach($tag);

    Livewire::test(Tags::class)->call('delete', $tag->id);

    expect($recipe->fresh()->tags)->toHaveCount(0);
});

test('le seed crée les comptes de Pierre et Monique sans écraser un mot de passe existant', function () {
    config(['bouffe.seed_password' => 'secret-initial']);

    $this->seed(UserSeeder::class);

    $pierre = User::firstWhere('email', 'ptripodi@free.fr');
    expect($pierre->name)->toBe('Pierre')
        ->and(User::firstWhere('email', 'monique.van@hotmail.com')->name)->toBe('Monique')
        ->and(Hash::check('secret-initial', $pierre->password))->toBeTrue();

    $pierre->update(['password' => 'change-par-pierre']);
    $this->seed(UserSeeder::class);

    expect(Hash::check('change-par-pierre', $pierre->fresh()->password))->toBeTrue();
});

test('le seed complet charge des recettes d\'exemple lisibles', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class); // ré-exécutable

    expect(Recipe::count())->toBe(8);

    $bolo = Recipe::firstWhere('title', 'Spaghetti bolognaise');
    expect($bolo->ingredients)->toHaveCount(13)->and($bolo->steps)->toHaveCount(5)->and($bolo->tags)->toHaveCount(3);

    $this->get(route('recipes.show', $bolo))->assertOk()->assertSee('400 g')->assertSee('de spaghetti');
    $this->get(route('recipes.index'))->assertOk()->assertSee('Crêpes');
});
