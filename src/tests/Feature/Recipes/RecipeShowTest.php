<?php

use App\Livewire\Recipes\Show;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->pierre = User::factory()->create(['name' => 'Pierre']);
    $this->monique = User::factory()->create(['name' => 'Monique']);
    $this->actingAs($this->pierre);

    $gram = Unit::factory()->gram()->create();
    $piece = Unit::factory()->piece()->create();
    $aisle = Aisle::factory()->create();

    $this->recipe = Recipe::factory()->create(['title' => 'Gratin', 'servings' => 4, 'prep_minutes' => 20, 'cook_minutes' => 55]);
    $this->recipe->ingredients()->create([
        'ingredient_id' => Ingredient::factory()->create(['name' => 'Pomme de terre', 'name_plural' => 'pommes de terre', 'aisle_id' => $aisle->id])->id,
        'quantity' => 1000, 'unit_id' => $gram->id, 'sort_order' => 1,
    ]);
    $this->recipe->ingredients()->create([
        'ingredient_id' => Ingredient::factory()->create(['name' => 'Oignon', 'name_plural' => 'oignons', 'aisle_id' => $aisle->id])->id,
        'quantity' => 2, 'unit_id' => $piece->id, 'preparation' => 'émincés', 'sort_order' => 2,
    ]);
    $this->recipe->ingredients()->create([
        'ingredient_id' => Ingredient::factory()->create(['name' => 'Sel', 'aisle_id' => $aisle->id])->id,
        'sort_order' => 3,
    ]);
    $this->recipe->steps()->create(['position' => 1, 'instruction' => 'Éplucher les pommes de terre.']);
});

test('la fiche affiche ingrédients, étapes et temps', function () {
    $this->get(route('recipes.show', $this->recipe))
        ->assertOk()
        ->assertSee('Gratin')
        ->assertSee('1 kg')->assertSee('de pommes de terre')
        ->assertSee('2')->assertSee('oignons')->assertSee('émincés')
        ->assertSee('sel')
        ->assertSee('Éplucher les pommes de terre.')
        ->assertSee("1\u{00A0}h\u{00A0}15", false);
});

test('l\'ajusteur de portions recalcule les quantités', function () {
    $component = Livewire::test(Show::class, ['recipe' => $this->recipe])
        ->assertSet('servings', 4)
        ->call('decrement')->call('decrement')
        ->assertSet('servings', 2)
        ->assertSee('Quantités recalculées pour 2');

    expect($component->instance()->ingredientGroups->flatten(1)->pluck('parts.text')->all())
        ->toBe(['500 g de pommes de terre', '1 oignon', 'sel']);

    $component->call('resetServings')->assertSee('1 kg');
});

test('chaque personne note la recette séparément', function () {
    $this->recipe->ratings()->create(['user_id' => $this->monique->id, 'rating' => 3, 'comment' => 'Un peu fade']);

    Livewire::test(Show::class, ['recipe' => $this->recipe])
        ->call('rate', 5)
        ->set('myComment', 'Parfait')
        ->call('saveComment')
        ->assertHasNoErrors()
        ->assertSee('Un peu fade');

    expect($this->recipe->ratings()->where('user_id', $this->pierre->id)->first())
        ->rating->toBe(5)->comment->toBe('Parfait')
        ->and($this->recipe->ratings()->avg('rating'))->toEqual(4);

    Livewire::test(Show::class, ['recipe' => $this->recipe])->call('rate', 0);
    expect($this->recipe->ratings()->count())->toBe(1);
});

test('favori, archivage et désarchivage', function () {
    Livewire::test(Show::class, ['recipe' => $this->recipe])
        ->call('toggleFavorite')
        ->call('toggleArchive')
        ->assertSee('Recette archivée');

    expect($this->recipe->fresh())->is_favorite->toBeTrue()->archived_at->not->toBeNull();

    Livewire::test(Show::class, ['recipe' => $this->recipe->fresh()])->call('toggleArchive');
    expect($this->recipe->fresh()->archived_at)->toBeNull();
});

test('duplication d\'une recette', function () {
    $tag = Tag::factory()->create();
    $this->recipe->tags()->attach($tag);
    $this->recipe->ratings()->create(['user_id' => $this->pierre->id, 'rating' => 4]);

    Livewire::test(Show::class, ['recipe' => $this->recipe])
        ->call('duplicate')
        ->assertRedirect(route('recipes.edit', 'gratin-copie'));

    $copy = Recipe::firstWhere('title', 'Gratin (copie)');
    expect($copy->ingredients)->toHaveCount(3)
        ->and($copy->steps)->toHaveCount(1)
        ->and($copy->tags->pluck('id')->all())->toBe([$tag->id])
        ->and($copy->ratings)->toHaveCount(0);

    Livewire::test(Show::class, ['recipe' => $this->recipe])->call('duplicate');
    expect(Recipe::where('title', 'Gratin (copie 2)')->exists())->toBeTrue();
});

test('suppression d\'une recette', function () {
    Livewire::test(Show::class, ['recipe' => $this->recipe])
        ->call('delete')
        ->assertRedirect(route('recipes.index'));

    expect(Recipe::count())->toBe(0);
});

test('une recette introuvable renvoie une 404', function () {
    $this->get('/recettes/n-existe-pas')->assertNotFound();
});
