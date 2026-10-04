<?php

use App\Livewire\Recipes\Index;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs($this->user = User::factory()->create());

    $this->veggie = Tag::factory()->create(['name' => 'Végétarien']);
    $this->quick = Tag::factory()->create(['name' => 'Rapide']);

    $this->soup = Recipe::factory()->create(['title' => 'Soupe de légumes', 'prep_minutes' => 10, 'cook_minutes' => 15, 'rest_minutes' => null, 'difficulty' => 'easy']);
    $this->soup->tags()->attach([$this->veggie->id, $this->quick->id]);

    $this->stew = Recipe::factory()->create(['title' => 'Bœuf bourguignon', 'prep_minutes' => 30, 'cook_minutes' => 180, 'difficulty' => 'medium', 'is_favorite' => true]);

    $this->salad = Recipe::factory()->create(['title' => 'Salade de chèvre', 'prep_minutes' => 15, 'cook_minutes' => null, 'difficulty' => 'easy']);
    $this->salad->tags()->attach($this->veggie->id);
    $this->salad->ingredients()->create([
        'ingredient_id' => Ingredient::factory()->create(['name' => 'Chèvre frais', 'aisle_id' => Aisle::factory()->create()->id])->id,
        'sort_order' => 1,
    ]);

    Recipe::factory()->archived()->create(['title' => 'Vieille recette']);
});

test('la liste affiche les recettes actives', function () {
    $this->get(route('recipes.index'))
        ->assertOk()
        ->assertSee('Soupe de légumes')->assertSee('Bœuf bourguignon')
        ->assertDontSee('Vieille recette');
});

test('recherche par titre ou ingrédient, sans accent', function () {
    Livewire::test(Index::class)
        ->set('search', 'boeuf')->assertSee('Bœuf bourguignon')->assertDontSee('Soupe de légumes')
        ->set('search', 'chevres')->assertSee('Salade de chèvre')->assertDontSee('Bœuf bourguignon');
});

test('filtre par catégories (toutes requises)', function () {
    Livewire::test(Index::class)
        ->call('toggleTag', $this->veggie->id)
        ->assertSee('Soupe de légumes')->assertSee('Salade de chèvre')->assertDontSee('Bœuf bourguignon')
        ->call('toggleTag', $this->quick->id)
        ->assertSee('Soupe de légumes')->assertDontSee('Salade de chèvre');
});

test('filtres temps, difficulté, favoris et archives', function () {
    Livewire::test(Index::class)
        ->set('maxMinutes', 30)->assertSee('Soupe de légumes')->assertSee('Salade de chèvre')->assertDontSee('Bœuf bourguignon')
        ->set('maxMinutes', null)->set('difficulty', 'medium')->assertSee('Bœuf bourguignon')->assertDontSee('Soupe de légumes')
        ->set('difficulty', '')->set('favoritesOnly', true)->assertSee('Bœuf bourguignon')->assertDontSee('Salade de chèvre')
        ->set('favoritesOnly', false)->set('showArchived', true)->assertSee('Vieille recette')->assertDontSee('Soupe de légumes');
});

test('tris alphabétique et par rapidité', function () {
    $alpha = Livewire::test(Index::class)->set('sort', 'alpha')->viewData('recipes')->pluck('title')->all();
    expect($alpha)->toBe(['Bœuf bourguignon', 'Salade de chèvre', 'Soupe de légumes']);

    $quick = Livewire::test(Index::class)->set('sort', 'quick')->viewData('recipes')->pluck('title')->all();
    expect($quick)->toBe(['Salade de chèvre', 'Soupe de légumes', 'Bœuf bourguignon']);
});

test('favori depuis la liste', function () {
    Livewire::test(Index::class)->call('toggleFavorite', $this->soup->id);

    expect($this->soup->fresh()->is_favorite)->toBeTrue();
});
