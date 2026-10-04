<?php

use App\Livewire\Settings\MealSlots;
use App\Livewire\Settings\Tags;
use App\Models\MealSlot;
use App\Models\Tag;
use App\Models\User;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

test('catégories : ajout, modification, suppression', function () {
    Livewire::test(Tags::class)
        ->set('newName', 'Sans gluten')->set('newColor', 'lime')
        ->call('add')->assertHasNoErrors();

    $tag = Tag::firstWhere('name', 'Sans gluten');
    expect($tag->slug)->toBe('sans-gluten');

    Livewire::test(Tags::class)
        ->call('edit', $tag->id)->set('editName', 'Sans lactose')->call('update')->assertHasNoErrors()
        ->call('delete', $tag->id);

    expect(Tag::count())->toBe(0);
});

test('catégories : pas de doublon à l\'accent près', function () {
    Tag::factory()->create(['name' => 'Végétarien']);

    Livewire::test(Tags::class)
        ->set('newName', 'vegetarien')->call('add')
        ->assertHasErrors('newName');
});

test('créneaux : activer, désactiver, réordonner', function () {
    $lunch = MealSlot::factory()->create(['name' => 'Déjeuner']);
    $dinner = MealSlot::factory()->create(['name' => 'Dîner']);
    $snack = MealSlot::factory()->inactive()->create(['name' => 'Goûter']);

    Livewire::test(MealSlots::class)
        ->call('toggle', $snack->id)
        ->call('toggle', $lunch->id)
        ->call('sort', $dinner->id, 0);

    expect(MealSlot::active()->ordered()->pluck('name')->all())->toBe(['Dîner', 'Goûter']);
});

test('créneaux : le dernier créneau actif ne peut être ni désactivé ni supprimé', function () {
    $only = MealSlot::factory()->create(['name' => 'Dîner']);
    MealSlot::factory()->inactive()->create(['name' => 'Goûter']);

    Livewire::test(MealSlots::class)
        ->call('toggle', $only->id)->assertDispatched('notify', type: 'warning')
        ->call('delete', $only->id);

    expect($only->fresh())->not->toBeNull()->is_active->toBeTrue();
});

test('créneaux : ajout et renommage', function () {
    Livewire::test(MealSlots::class)
        ->set('newName', 'Brunch')->call('add')->assertHasNoErrors();

    $brunch = MealSlot::firstWhere('name', 'Brunch');

    Livewire::test(MealSlots::class)
        ->call('edit', $brunch->id)->set('editName', 'Brunch du dimanche')->call('update');

    expect($brunch->fresh()->name)->toBe('Brunch du dimanche');
});

test('toutes les pages de paramètres s\'affichent', function (string $route, string $text) {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);

    $this->get(route($route))->assertOk()->assertSee($text);
})->with([
    ['settings.index', 'Ingrédients'],
    ['settings.ingredients', 'Agneau (épaule)'],
    ['settings.aisles', 'Crèmerie & œufs'],
    ['settings.units', 'c. à soupe'],
    ['settings.tags', 'Batch cooking'],
    ['settings.slots', 'Petit-déjeuner'],
]);
