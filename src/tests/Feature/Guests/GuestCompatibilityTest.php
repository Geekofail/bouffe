<?php

use App\Enums\RestrictionType;
use App\Models\Aisle;
use App\Models\Guest;
use App\Models\MealSlot;
use App\Models\Tag;
use App\Models\User;
use App\Services\Planning\GuestCompatibility;
use App\Services\Planning\GuestManager;
use App\Services\Planning\OccasionService;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Carbon;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    $this->actingAs(User::factory()->create());
    $this->compat = app(GuestCompatibility::class);
    $this->manager = app(GuestManager::class);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner']);
    Aisle::factory()->create(['name' => 'Divers']);

    $this->julie = $this->manager->save(null, ['name' => 'Julie'], [['type' => 'allergy', 'ingredient' => 'Noix']]);
    $this->paul = $this->manager->save(null, ['name' => 'Paul'], [['type' => 'dislike', 'ingredient' => 'Champignon']]);
    $vege = Tag::factory()->create(['name' => 'Végétarien']);
    $this->lea = $this->manager->save(null, ['name' => 'Léa'], [['type' => 'diet', 'tag_id' => $vege->id]]);
});

test('une allergie est rouge, « n\'aime pas » et régime sont orange', function () {
    $recipe = dish('Salade', ['Noix', 'Champignon', 'Salade verte']);

    $conflicts = $this->compat->conflicts($recipe, collect([$this->paul, $this->julie, $this->lea]));

    expect(array_column($conflicts, 'message'))->toBe([
        'Contient : Noix — allergie de Julie',
        'Pas « Végétarien » — régime de Léa',
        'Contient : Champignon — Paul n\'aime pas',
    ])->and($this->compat->worstLevel($conflicts))->toBe('danger');
});

test('une recette compatible ne donne aucune alerte', function () {
    $recipe = dish('Ratatouille', ['Courgette', 'Aubergine'], ['Végétarien']);

    expect($this->compat->conflicts($recipe, [$this->julie, $this->paul, $this->lea]))->toBe([])
        ->and($this->compat->worstLevel([]))->toBeNull();
});

test('un ingrédient facultatif est signalé comme tel', function () {
    $recipe = dish('Brownie', ['Chocolat', '?Noix'], ['Végétarien']);

    $conflicts = $this->compat->conflicts($recipe, [$this->julie]);

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['optional'])->toBeTrue()
        ->and($conflicts[0]['message'])->toBe('Contient (facultatif) : Noix — allergie de Julie');
});

test('« déjà servi » retrouve la dernière fois qu\'une recette a été servie à un invité', function () {
    $planner = app(WeekPlanner::class);
    $occasions = app(OccasionService::class);
    $lasagnes = dish('Lasagnes', ['Pâtes']);
    $tarte = dish('Tarte', ['Pomme']);

    foreach (['2026-03-12', '2026-06-01'] as $date) {
        $occasions->save($date, $this->dinner, ['guest_ids' => [$this->julie->id]]);
        $planner->addRecipe($date, $this->dinner, $lasagnes);
    }
    $occasions->save('2026-09-10', $this->dinner, ['guest_ids' => [$this->paul->id]]);
    $planner->addRecipe('2026-09-10', $this->dinner, $tarte);
    // Repas futur : pas « déjà servi »
    $occasions->save('2026-09-30', $this->dinner, ['guest_ids' => [$this->julie->id]]);
    $planner->addRecipe('2026-09-30', $this->dinner, $tarte);

    $served = $this->compat->servedBefore([$lasagnes->id, $tarte->id], [$this->julie->id, $this->paul->id], Carbon::parse('2026-09-20'));

    expect($served[$lasagnes->id])->toHaveCount(1)
        ->and($served[$lasagnes->id][0]['guest'])->toBe('Julie')
        ->and($served[$lasagnes->id][0]['date']->toDateString())->toBe('2026-06-01')
        ->and(array_column($served[$tarte->id], 'guest'))->toBe(['Paul']);

    $history = $this->compat->history($this->julie);
    expect($history->map(fn ($row) => $row['occasion']->date->toDateString())->all())->toBe(['2026-09-30', '2026-06-01', '2026-03-12'])
        ->and($history->first()['meals']->first()->recipe->title)->toBe('Tarte');
});

/* ------------------------------------------------------------------ Carnet */

test('les contraintes créent les ingrédients inconnus et évitent les doublons', function () {
    ingredientNamed('Noix de cajou');
    $guest = $this->manager->save(null, ['name' => '  Tom  ', 'group_name' => 'Amis', 'is_child' => true], [
        ['type' => 'allergy', 'ingredient' => 'arachides'],
        ['type' => 'dislike', 'ingredient' => 'Arachide'],     // l'allergie prime
        ['type' => 'allergy', 'ingredient' => 'noix de cajou'],
        ['type' => 'dislike', 'ingredient' => 'Céleri', 'note' => 'même cuit'],
    ]);

    expect($guest->name)->toBe('Tom')
        ->and($guest->is_child)->toBeTrue()
        ->and($guest->restrictions)->toHaveCount(3)
        ->and($guest->restrictions->where('type', RestrictionType::Allergy)->map->subject()->sort()->values()->all())->toBe(['Arachides', 'Noix de cajou'])
        ->and($this->manager->createdIngredients)->toBe(['Arachides', 'Céleri'])
        ->and($this->manager->groupNames())->toBe(['Amis']);
});

test('une contrainte incomplète est refusée sans rien enregistrer', function () {
    expect(fn () => $this->manager->save(null, ['name' => 'Zoé'], [['type' => 'diet', 'tag_id' => null]]))
        ->toThrow(InvalidArgumentException::class, 'choisissez la catégorie');

    expect(Guest::where('name', 'Zoé')->exists())->toBeFalse();
});

test('un invité déjà reçu est archivé, pas supprimé', function () {
    app(OccasionService::class)->save('2026-09-01', $this->dinner, ['guest_ids' => [$this->julie->id]]);

    expect(fn () => $this->manager->delete($this->julie))->toThrow(InvalidArgumentException::class, 'archivez');

    $this->manager->setArchived($this->julie, true);
    expect($this->julie->fresh()->isArchived())->toBeTrue();

    $this->manager->delete($this->paul);
    expect(Guest::find($this->paul->id))->toBeNull();
});
