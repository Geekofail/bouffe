<?php

use App\Livewire\Settings\Aisles;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\User;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

test('ajout d\'un rayon en fin de liste', function () {
    Aisle::factory()->count(2)->create();

    Livewire::test(Aisles::class)
        ->set('newName', 'Produits du monde')
        ->set('newColor', 'violet')
        ->call('add')
        ->assertHasNoErrors()
        ->assertSee('Produits du monde');

    expect(Aisle::ordered()->get()->last())
        ->name->toBe('Produits du monde')
        ->color->toBe('violet')
        ->sort_order->toBe(3);
});

test('le nom est obligatoire et unique', function () {
    Aisle::factory()->create(['name' => 'Boulangerie']);

    Livewire::test(Aisles::class)
        ->set('newName', 'Boulangerie')->call('add')->assertHasErrors('newName')
        ->set('newName', '')->call('add')->assertHasErrors('newName');
});

test('réordonner par glisser-déposer', function () {
    [$a, $b, $c] = [
        Aisle::factory()->create(['name' => 'A']),
        Aisle::factory()->create(['name' => 'B']),
        Aisle::factory()->create(['name' => 'C']),
    ];

    Livewire::test(Aisles::class)->call('sort', $c->id, 0);
    expect(Aisle::ordered()->pluck('name')->all())->toBe(['C', 'A', 'B']);

    Livewire::test(Aisles::class)->call('sort', $c->id, 2);
    expect(Aisle::ordered()->pluck('name')->all())->toBe(['A', 'B', 'C']);
});

test('modification d\'un rayon', function () {
    $aisle = Aisle::factory()->create(['name' => 'Frais', 'color' => 'stone']);

    Livewire::test(Aisles::class)
        ->call('edit', $aisle->id)
        ->set('editName', 'Crèmerie')
        ->set('editColor', 'yellow')
        ->call('update')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);

    expect($aisle->fresh())->name->toBe('Crèmerie')->color->toBe('yellow');
});

test('un rayon utilisé ne peut pas être supprimé', function () {
    $used = Aisle::factory()->create();
    Ingredient::factory()->create(['aisle_id' => $used->id]);
    $empty = Aisle::factory()->create();

    Livewire::test(Aisles::class)
        ->call('delete', $used->id)
        ->assertDispatched('notify', type: 'warning')
        ->call('delete', $empty->id);

    expect(Aisle::pluck('id')->all())->toBe([$used->id]);
});
