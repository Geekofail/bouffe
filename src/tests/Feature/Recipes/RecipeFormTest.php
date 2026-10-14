<?php

use App\Livewire\Recipes\Edit;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->aisle = Aisle::factory()->create(['name' => 'Fruits & légumes']);
    $this->misc = Aisle::factory()->create(['name' => 'Divers']);
    $this->gram = Unit::factory()->gram()->create();
    $this->piece = Unit::factory()->piece()->create();
    $this->onion = Ingredient::factory()->create(['name' => 'Oignon', 'aisle_id' => $this->aisle->id, 'default_unit_id' => $this->piece->id]);
    $this->flour = Ingredient::factory()->create(['name' => 'Farine', 'aisle_id' => $this->misc->id, 'default_unit_id' => $this->gram->id]);
});

function fillRow(array $overrides = []): array
{
    return array_merge(['uid' => str()->random(8), 'name' => '', 'quantity' => '', 'unit_id' => null,
        'preparation' => '', 'group_name' => '', 'is_optional' => false, 'new_aisle_id' => null], $overrides);
}

test('les pages de création et de modification s\'affichent', function () {
    $recipe = Recipe::factory()->create(['title' => 'Tarte fine']);

    $this->get(route('recipes.create'))->assertOk()->assertSee('Nouvelle recette');
    $this->get(route('recipes.edit', $recipe))->assertOk()->assertSee('Tarte fine');
});

test('création complète d\'une recette', function () {
    $tag = Tag::factory()->create(['name' => 'Plat']);

    Livewire::test(Edit::class)
        ->set('form.title', 'Soupe à l\'oignon')
        ->set('form.servings', 4)
        ->set('form.prep_minutes', '15')
        ->set('form.cook_minutes', '')
        ->set('form.difficulty', 'easy')
        ->call('toggleTag', $tag->id)
        ->set('form.ingredients', [
            fillRow(['name' => 'oignons', 'quantity' => '1,5', 'unit_id' => $this->piece->id, 'preparation' => 'émincés']),
            fillRow(['name' => 'Farine', 'quantity' => '1/2', 'unit_id' => $this->gram->id, 'is_optional' => true]),
            fillRow(), // ligne vide ignorée
        ])
        ->set('form.steps', [['uid' => 'a', 'instruction' => 'Émincer.'], ['uid' => 'b', 'instruction' => '  '], ['uid' => 'c', 'instruction' => 'Cuire.']])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('recipes.show', 'soupe-a-l-oignon'));

    $recipe = Recipe::firstWhere('title', 'Soupe à l\'oignon');

    expect($recipe)
        ->slug->toBe('soupe-a-l-oignon')
        ->servings->toBe(4)
        ->prep_minutes->toBe(15)
        ->cook_minutes->toBeNull()
        ->created_by->toBe($this->user->id)
        ->and($recipe->tags->pluck('name')->all())->toBe(['Plat'])
        ->and($recipe->steps->pluck('instruction')->all())->toBe(['Émincer.', 'Cuire.'])
        ->and($recipe->ingredients)->toHaveCount(2)
        ->and($recipe->ingredients[0]->ingredient_id)->toBe($this->onion->id)
        ->and((float) $recipe->ingredients[0]->quantity)->toBe(1.5)
        ->and($recipe->ingredients[0]->preparation)->toBe('émincés')
        ->and((float) $recipe->ingredients[1]->quantity)->toBe(0.5)
        ->and($recipe->ingredients[1]->is_optional)->toBeTrue();
});

test('un ingrédient inconnu est créé dans le rayon choisi', function () {
    Livewire::test(Edit::class)
        ->set('form.title', 'Risotto')
        ->set('form.ingredients.0.name', 'Topinambour')
        ->assertSet('form.ingredients.0.new_aisle_id', $this->misc->id)
        ->set('form.ingredients.0.new_aisle_id', $this->aisle->id)
        ->set('form.ingredients.0.quantity', '300')
        ->set('form.ingredients.0.unit_id', $this->gram->id)
        ->call('save')
        ->assertHasNoErrors();

    $new = Ingredient::firstWhere('name', 'Topinambour');
    expect($new)->not->toBeNull()
        ->and($new->aisle_id)->toBe($this->aisle->id)
        ->and($new->default_unit_id)->toBe($this->gram->id);
});

test('saisir un ingrédient connu propose son unité par défaut', function () {
    Livewire::test(Edit::class)
        ->set('form.ingredients.0.name', 'oignon')
        ->assertSet('form.ingredients.0.unit_id', $this->piece->id)
        ->assertSet('form.ingredients.0.new_aisle_id', null);
});

test('validation du formulaire', function () {
    Recipe::factory()->create(['title' => 'Existe déjà']);

    Livewire::test(Edit::class)
        ->set('form.title', 'Existe déjà')
        ->set('form.servings', 0)
        ->set('form.ingredients', [fillRow(['name' => '', 'quantity' => '2']), fillRow(['name' => 'Farine', 'quantity' => 'beaucoup'])])
        ->call('save')
        ->assertHasErrors(['form.title', 'form.servings', 'form.ingredients.0.name', 'form.ingredients.1.quantity']);

    expect(Recipe::count())->toBe(1);
});

test('modification : les lignes sont remplacées et l\'ordre respecté', function () {
    $recipe = Recipe::factory()->create(['title' => 'Pâte à crêpes']);
    $recipe->ingredients()->create(['ingredient_id' => $this->flour->id, 'quantity' => 250, 'unit_id' => $this->gram->id, 'sort_order' => 1]);
    $recipe->ingredients()->create(['ingredient_id' => $this->onion->id, 'quantity' => 1, 'unit_id' => $this->piece->id, 'sort_order' => 2]);

    $component = Livewire::test(Edit::class, ['recipe' => $recipe])
        ->assertSet('form.title', 'Pâte à crêpes')
        ->assertSet('form.ingredients.0.quantity', '250');

    $uidOnion = $component->get('form.ingredients.1.uid');

    $component->call('sortIngredients', $uidOnion, 0)
        ->call('removeIngredient', $component->get('form.ingredients.1.uid'))
        ->call('addIngredient')
        ->set('form.ingredients.1.name', 'Farine')
        ->set('form.ingredients.1.quantity', '300')
        ->set('form.title', 'Pâte à crêpes légère')
        ->call('save')
        ->assertHasNoErrors();

    $recipe->refresh();
    expect($recipe->slug)->toBe('pate-a-crepes-legere')
        ->and($recipe->ingredients->map(fn ($l) => [$l->ingredient->name, (float) $l->quantity])->all())
        ->toBe([['Oignon', 1.0], ['Farine', 300.0]]);
});

test('groupes d\'ingrédients', function () {
    Livewire::test(Edit::class)
        ->set('form.title', 'Tarte')
        ->set('form.useGroups', true)
        ->set('form.ingredients', [fillRow(['name' => 'Farine', 'quantity' => '200', 'group_name' => 'Pâte'])])
        ->call('save')
        ->assertHasNoErrors();

    expect(Recipe::firstWhere('title', 'Tarte')->ingredients->first()->group_name)->toBe('Pâte');
});

test('photo : envoi, redimensionnement, affichage et suppression', function () {
    Storage::fake('local');

    Livewire::test(Edit::class)
        ->set('form.title', 'Gâteau')
        ->set('photo', UploadedFile::fake()->image('gateau.jpg', 2400, 1800))
        ->call('save')
        ->assertHasNoErrors();

    $recipe = Recipe::firstWhere('title', 'Gâteau');
    expect($recipe->photo_path)->toStartWith('recipes/');
    Storage::disk('local')->assertExists([$recipe->photo_path.'.jpg', $recipe->photo_path.'-thumb.jpg']);

    [$width] = getimagesizefromstring(Storage::disk('local')->get($recipe->photo_path.'-thumb.jpg'));
    expect($width)->toBe(640);

    $this->get(route('recipes.photo', ['recipe' => $recipe->id, 'size' => 'thumb']))
        ->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    Livewire::test(Edit::class, ['recipe' => $recipe])->call('removePhoto')->call('save');

    Storage::disk('local')->assertMissing($recipe->photo_path.'.jpg');
    expect($recipe->fresh()->photo_path)->toBeNull();
});

test('un fichier qui n\'est pas une image est refusé', function () {
    Livewire::test(Edit::class)
        ->set('form.title', 'Test')
        ->set('photo', UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'))
        ->call('save')
        ->assertHasErrors('photo');
});

test('les photos ne sont pas accessibles sans connexion', function () {
    $recipe = Recipe::factory()->create(['photo_path' => 'recipes/x']);
    auth()->logout();

    $this->get(route('recipes.photo', ['recipe' => $recipe->id, 'size' => 'thumb']))->assertRedirect(route('login'));
});

/*
 * Réordonner les lignes (ingrédients, étapes) ne duplique ni n'écrase rien.
 * Le comportement dans le navigateur est vérifié par tests/Browser/recipe-rows.spec.js.
 */

/** Les lignes d'ingrédients du formulaire, en « quantité nom ». */
function ingredientLines($component): array
{
    return array_map(fn ($row) => trim($row['quantity'].' '.$row['name']), $component->get('form.ingredients'));
}

test('réordonner les ingrédients : chaque ligne garde ses valeurs, dans tous les sens', function () {
    $component = Livewire::test(Edit::class)
        ->set('form.title', 'Ordre des lignes')
        ->set('form.ingredients', [
            fillRow(['name' => 'Farine', 'quantity' => '100']),
            fillRow(['name' => 'Sucre', 'quantity' => '50']),
            fillRow(['name' => 'Beurre', 'quantity' => '25']),
            fillRow(['name' => 'Œuf', 'quantity' => '2']),
        ]);
    $uid = fn (int $i) => $component->get("form.ingredients.$i.uid");

    $component->call('sortIngredients', $uid(2), 0);
    expect(ingredientLines($component))->toBe(['25 Beurre', '100 Farine', '50 Sucre', '2 Œuf']);

    $component->call('sortIngredients', $uid(0), 3);
    expect(ingredientLines($component))->toBe(['100 Farine', '50 Sucre', '2 Œuf', '25 Beurre']);

    $component->call('sortIngredients', $uid(1), 2);
    expect(ingredientLines($component))->toBe(['100 Farine', '2 Œuf', '50 Sucre', '25 Beurre']);

    // Même place, position hors limites, ligne inconnue : rien ne se perd, rien ne se double.
    $component->call('sortIngredients', $uid(1), 1)
        ->call('sortIngredients', $uid(0), 99)
        ->call('sortIngredients', $uid(0), -5)
        ->call('sortIngredients', 'inconnue', 0);
    expect(ingredientLines($component))->toBe(['2 Œuf', '50 Sucre', '25 Beurre', '100 Farine'])
        ->and(collect($component->get('form.ingredients'))->pluck('uid')->unique())->toHaveCount(4);
});

test('une saisie envoyée avec le déplacement va à la ligne saisie, pas à sa voisine', function () {
    $component = Livewire::test(Edit::class)
        ->set('form.title', 'Saisie puis déplacement')
        ->set('form.ingredients', [
            fillRow(['name' => 'Farine', 'quantity' => '100']),
            fillRow(['name' => 'Sucre', 'quantity' => '50', 'new_aisle_id' => $this->misc->id]),
            fillRow(['name' => 'Beurre', 'quantity' => '25', 'new_aisle_id' => $this->misc->id]),
        ]);

    // Comme dans le navigateur : la valeur tapée part avec l'appel, à la position d'avant le déplacement.
    $component->update(
        calls: [['method' => 'sortIngredients', 'params' => [$component->get('form.ingredients.2.uid'), 0]]],
        updates: ['form.ingredients.2.quantity' => '30'],
    );
    expect(ingredientLines($component))->toBe(['30 Beurre', '100 Farine', '50 Sucre']);

    $component->set('form.ingredients.2.quantity', '60')->call('save')->assertHasNoErrors();

    $recipe = Recipe::where('title', 'Saisie puis déplacement')->firstOrFail();
    expect($recipe->ingredients->map(fn ($l) => [$l->ingredient->name, (float) $l->quantity])->all())
        ->toBe([['Beurre', 30.0], ['Farine', 100.0], ['Sucre', 60.0]]);
});

test('réordonner puis retirer des étapes : chaque étape garde son texte', function () {
    $component = Livewire::test(Edit::class)
        ->set('form.title', 'Ordre des étapes')
        ->set('form.ingredients', [fillRow(['name' => 'Farine', 'quantity' => '100'])])
        ->set('form.steps', [
            ['uid' => 'a1', 'group_name' => '', 'instruction' => 'Préchauffer.'],
            ['uid' => 'b2', 'group_name' => '', 'instruction' => 'Mélanger.'],
            ['uid' => 'c3', 'group_name' => '', 'instruction' => 'Enfourner.'],
        ]);

    $component->call('sortSteps', 'c3', 0)->call('removeStep', 'a1');
    expect(array_column($component->get('form.steps'), 'instruction'))->toBe(['Enfourner.', 'Mélanger.']);

    $component->call('save')->assertHasNoErrors();
    expect(Recipe::where('title', 'Ordre des étapes')->firstOrFail()->steps()->orderBy('position')->pluck('instruction')->all())
        ->toBe(['Enfourner.', 'Mélanger.']);
});

test('la clé de chaque ligne contient sa position, pour relier ses champs à la bonne ligne après un déplacement', function () {
    // Les champs sont reliés par position (form.ingredients.1.quantity) et Livewire ne relie pas à
    // nouveau un champ qu'il garde : sans la position dans la clé, une ligne déplacée afficherait
    // et modifierait une autre ligne.
    $component = Livewire::test(Edit::class)->set('form.ingredients', [
        fillRow(['uid' => 'premier1', 'name' => 'Farine']),
        fillRow(['uid' => 'second22', 'name' => 'Sucre']),
    ])->set('form.steps', [['uid' => 'etape111', 'group_name' => '', 'instruction' => 'Mélanger.']]);

    $component->assertSeeHtml('wire:key="ing-premier1-0"')->assertSeeHtml('wire:key="ing-second22-1"')
        ->assertSeeHtml('wire:key="step-etape111-0"');

    $component->call('sortIngredients', 'second22', 0)
        ->assertSeeHtml('wire:key="ing-second22-0"')->assertSeeHtml('wire:key="ing-premier1-1"');
});
