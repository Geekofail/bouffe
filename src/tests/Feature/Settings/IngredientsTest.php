<?php

use App\Livewire\Settings\Ingredients;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->aisle = Aisle::factory()->create(['name' => 'Fruits & légumes']);
    $this->piece = Unit::factory()->piece()->create();
});

test('la page liste les ingrédients', function () {
    Ingredient::factory()->create(['name' => 'Carotte', 'aisle_id' => $this->aisle->id]);

    $this->get(route('settings.ingredients'))
        ->assertOk()
        ->assertSeeLivewire(Ingredients::class)
        ->assertSee('Carotte')
        ->assertSee('Fruits &amp; légumes', false);
});

test('la recherche ignore accents, casse et pluriel', function () {
    Ingredient::factory()->create(['name' => 'Échalote', 'aisle_id' => $this->aisle->id]);
    Ingredient::factory()->create(['name' => 'Carotte', 'aisle_id' => $this->aisle->id]);

    Livewire::test(Ingredients::class)
        ->set('search', 'echalotes')
        ->assertSee('Échalote')
        ->assertDontSee('Carotte');
});

test('filtre par rayon et produits de base', function () {
    $other = Aisle::factory()->create();
    Ingredient::factory()->create(['name' => 'Carotte', 'aisle_id' => $this->aisle->id]);
    Ingredient::factory()->staple()->create(['name' => 'Sel', 'aisle_id' => $other->id]);

    Livewire::test(Ingredients::class)
        ->set('aisleFilter', $this->aisle->id)
        ->assertSee('Carotte')->assertDontSee('Sel')
        ->set('aisleFilter', null)
        ->set('staplesOnly', true)
        ->assertSee('Sel')->assertDontSee('Carotte');
});

test('création d\'un ingrédient avec poids saisi à la française', function () {
    Livewire::test(Ingredients::class)
        ->call('create')
        ->assertSet('showForm', true)
        ->set('form.name', 'Oignon')
        ->set('form.name_plural', 'oignons')
        ->set('form.aisle_id', $this->aisle->id)
        ->set('form.default_unit_id', $this->piece->id)
        ->set('form.piece_weight_g', '150,5')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertDispatched('notify');

    $onion = Ingredient::firstWhere('name', 'Oignon');
    expect($onion)->not->toBeNull()
        ->and((float) $onion->piece_weight_g)->toBe(150.5)
        ->and($onion->search_name)->toBe('oignon');
});

test('validation : nom et rayon obligatoires, poids numérique', function () {
    Livewire::test(Ingredients::class)
        ->call('create')
        ->set('form.name', '')
        ->set('form.aisle_id', null)
        ->set('form.piece_weight_g', 'beaucoup')
        ->call('save')
        ->assertHasErrors(['form.name', 'form.aisle_id', 'form.piece_weight_g']);
});

test('un doublon (pluriel, accents) est refusé', function () {
    Ingredient::factory()->create(['name' => 'Tomate', 'aisle_id' => $this->aisle->id]);

    Livewire::test(Ingredients::class)
        ->call('create')
        ->set('form.name', 'TOMATES')
        ->set('form.aisle_id', $this->aisle->id)
        ->call('save')
        ->assertHasErrors('form.name')
        ->assertSee('Cet ingrédient existe déjà');

    expect(Ingredient::count())->toBe(1);
});

test('un ingrédient proche est signalé pendant la saisie', function () {
    Ingredient::factory()->create(['name' => 'Tomate', 'aisle_id' => $this->aisle->id]);

    Livewire::test(Ingredients::class)
        ->call('create')
        ->set('form.name', 'Tomate cerise')
        ->assertSee('Ingrédient proche déjà enregistré');
});

test('modification d\'un ingrédient sans faux doublon avec lui-même', function () {
    $ingredient = Ingredient::factory()->create(['name' => 'Poireau', 'aisle_id' => $this->aisle->id]);

    Livewire::test(Ingredients::class)
        ->call('edit', $ingredient->id)
        ->assertSet('form.name', 'Poireau')
        ->set('form.name_plural', 'poireaux')
        ->set('form.is_staple', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($ingredient->fresh())
        ->name_plural->toBe('poireaux')
        ->is_staple->toBeTrue();
});

test('bascule produit de base et suppression', function () {
    $ingredient = Ingredient::factory()->create(['aisle_id' => $this->aisle->id]);

    Livewire::test(Ingredients::class)
        ->call('toggleStaple', $ingredient->id)
        ->tap(fn () => expect($ingredient->fresh()->is_staple)->toBeTrue())
        ->call('delete', $ingredient->id);

    expect(Ingredient::count())->toBe(0);
});
