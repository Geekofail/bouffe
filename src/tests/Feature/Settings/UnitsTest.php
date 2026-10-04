<?php

use App\Enums\UnitType;
use App\Livewire\Settings\Units;
use App\Models\Ingredient;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

test('création d\'une unité de volume avec son équivalence', function () {
    Livewire::test(Units::class)
        ->call('create')
        ->set('form.label', 'verre')
        ->set('form.label_plural', 'verres')
        ->set('form.code', 'verre')
        ->set('form.type', 'volume')
        ->set('form.factor_to_base', '200')
        ->call('save')
        ->assertHasNoErrors();

    expect(Unit::firstWhere('code', 'verre'))
        ->type->toBe(UnitType::Volume)
        ->and((float) Unit::firstWhere('code', 'verre')->factor_to_base)->toBe(200.0);
});

test('le facteur est obligatoire pour une masse ou un volume, ignoré sinon', function () {
    Livewire::test(Units::class)
        ->call('create')
        ->set('form.label', 'louche')->set('form.code', 'louche')->set('form.type', 'volume')->set('form.factor_to_base', '')
        ->call('save')
        ->assertHasErrors('form.factor_to_base')
        ->set('form.type', 'other')->set('form.factor_to_base', '12')
        ->call('save')
        ->assertHasNoErrors();

    expect(Unit::firstWhere('code', 'louche')->factor_to_base)->toBeNull();
});

test('le code doit être unique et sans caractère spécial', function () {
    Unit::factory()->gram()->create();

    Livewire::test(Units::class)
        ->call('create')
        ->set('form.label', 'Gramme')->set('form.code', 'g')->set('form.type', 'mass')->set('form.factor_to_base', '1')
        ->call('save')->assertHasErrors('form.code')
        ->set('form.code', 'Gros Verre')
        ->call('save')->assertHasErrors('form.code');
});

test('les unités de référence gardent code, famille et facteur', function () {
    $gram = Unit::factory()->gram()->create();

    Livewire::test(Units::class)
        ->call('edit', $gram->id)
        ->set('form.label', 'gramme')
        ->set('form.code', 'gr')
        ->set('form.factor_to_base', '5')
        ->call('save')
        ->assertHasNoErrors();

    expect($gram->fresh())->label->toBe('gramme')->code->toBe('g')
        ->and((float) $gram->fresh()->factor_to_base)->toBe(1.0);
});

test('suppression refusée pour une unité de référence ou utilisée', function () {
    $gram = Unit::factory()->gram()->create();
    $pot = Unit::factory()->create(['code' => 'pot']);
    Ingredient::factory()->create(['default_unit_id' => $pot->id]);
    $free = Unit::factory()->create(['code' => 'libre']);

    Livewire::test(Units::class)
        ->call('delete', $gram->id)->assertDispatched('notify', type: 'warning')
        ->call('delete', $pot->id)
        ->call('delete', $free->id);

    expect(Unit::pluck('code')->sort()->values()->all())->toBe(['g', 'portion', 'pot']);
});
