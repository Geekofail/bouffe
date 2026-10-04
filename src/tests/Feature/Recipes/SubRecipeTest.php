<?php

use App\Enums\RestrictionType;
use App\Livewire\Recipes\Edit as EditPage;
use App\Livewire\Recipes\Show as ShowPage;
use App\Models\Guest;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\RecipeComponent;
use App\Models\User;
use App\Services\Planning\GuestCompatibility;
use App\Services\Planning\WeekPlanner;
use App\Services\RecipeDuplicator;
use App\Services\Recipes\SubRecipes;
use App\Services\Shopping\ShoppingListGenerator;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Sous-recettes (lot 20 — 13.8).
 *
 * Une quiche utilise « 1 × Pâte brisée » : la farine et le beurre de la pâte doivent se
 * retrouver dans les courses, le coût, le stock et la vérification des allergies — sans
 * jamais être recopiés dans la quiche.
 */

require_once __DIR__.'/../Shopping/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-12 10:00'));
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class]);

    $this->service = app(SubRecipes::class);

    // Pâte brisée pour un moule (6 parts) : 250 g de farine, 125 g de beurre.
    $this->pate = recipeWith('Pâte brisée', 6, [[250, 'g', 'Farine', false], [125, 'g', 'Beurre', false]]);
    // Quiche pour 6 : 3 œufs + la pâte.
    $this->quiche = recipeWith('Quiche aux poireaux', 6, [[3, 'piece', 'Œuf', false], [2, 'piece', 'Poireau', false]]);
    RecipeComponent::create(['recipe_id' => $this->quiche->id, 'component_recipe_id' => $this->pate->id, 'quantity' => 1]);
});

test('les lignes d\'une recette incluent celles de ses sous-recettes', function () {
    $lines = $this->service->lines($this->quiche->fresh());

    expect($lines)->toHaveCount(4)
        ->and($lines->firstWhere('ingredient.name', 'Farine')->via_recipe)->toBe('Pâte brisée')
        ->and((float) $lines->firstWhere('ingredient.name', 'Farine')->quantity)->toBe(250.0)
        // La pâte elle-même n'a pas été touchée.
        ->and($this->pate->fresh()->ingredients)->toHaveCount(2);
});

test('une demi sous-recette donne la moitié des ingrédients', function () {
    RecipeComponent::query()->update(['quantity' => 0.5]);

    $lines = $this->service->lines($this->quiche->fresh());

    expect((float) $lines->firstWhere('ingredient.name', 'Beurre')->quantity)->toBe(62.5);
});

test('la liste de courses compte la pâte, à l\'échelle des portions planifiées', function () {
    $slot = MealSlot::factory()->create(['name' => 'Dîner']);
    // Quiche pour 12 : deux fois la recette, donc deux pâtes.
    app(WeekPlanner::class)->addRecipe('2026-10-14', $slot, $this->quiche, 12);

    $generator = app(ShoppingListGenerator::class);
    $text = linesText($generator->generate($generator->meals(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'))));

    expect($text['Farine'])->toContain('500 g')
        ->and($text['Beurre'])->toContain('250 g');
});

test('les sous-recettes imbriquées sont dépliées', function () {
    $sauce = recipeWith('Béchamel', 4, [[50, 'g', 'Beurre', false], [500, 'ml', 'Lait demi-écrémé', false]]);
    RecipeComponent::create(['recipe_id' => $this->pate->id, 'component_recipe_id' => $sauce->id, 'quantity' => 2]);

    $lines = $this->service->lines($this->quiche->fresh());

    expect($lines->where('ingredient.name', 'Beurre'))->toHaveCount(2)
        ->and((float) $lines->firstWhere('ingredient.name', 'Lait demi-écrémé')->quantity)->toBe(1000.0);
});

test('une recette ne peut pas s\'utiliser elle-même, même indirectement', function () {
    expect($this->service->refusal($this->pate, $this->pate))->toContain('elle-même')
        ->and($this->service->refusal($this->pate, $this->quiche))->toContain('boucle');

    expect(fn () => $this->service->sync($this->pate, [['recipe_id' => $this->quiche->id, 'quantity' => 1]]))
        ->toThrow(InvalidArgumentException::class);
});

test('une sous-recette utilisée ne peut pas être supprimée', function () {
    Livewire::test(ShowPage::class, ['recipe' => $this->pate])
        ->call('delete')
        ->assertDispatched('notify');

    expect($this->pate->fresh())->not->toBeNull();
});

test('l\'allergie d\'un invité est détectée dans la sous-recette', function () {
    $guest = Guest::factory()->create(['name' => 'Julie']);
    $guest->restrictions()->create(['type' => RestrictionType::Allergy->value, 'ingredient_id' => Ingredient::firstWhere('name', 'Beurre')->id]);

    $conflicts = app(GuestCompatibility::class)->conflicts($this->quiche->fresh(), [$guest->fresh()]);

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['subject'])->toBe('Beurre');
});

test('le formulaire enregistre et retire une sous-recette', function () {
    $tarte = recipeWith('Tarte aux pommes', 6, [[4, 'piece', 'Pomme', false]]);

    Livewire::test(EditPage::class, ['recipe' => $tarte])
        ->call('addComponent')
        ->set('form.components.0.recipe_id', (string) $this->pate->id)
        ->set('form.components.0.quantity', '1')
        ->set('form.components.0.note', 'fond de tarte')
        ->call('save')
        ->assertHasNoErrors();

    expect($tarte->components()->first()->component_recipe_id)->toBe($this->pate->id)
        ->and($tarte->components()->first()->note)->toBe('fond de tarte');

    $page = Livewire::test(EditPage::class, ['recipe' => $tarte->fresh()]);
    $page->call('removeComponent', $page->get('form.components.0.uid'))->call('save');

    expect($tarte->components()->count())->toBe(0);

    // Une boucle est refusée par la validation.
    Livewire::test(EditPage::class, ['recipe' => $this->pate])
        ->call('addComponent')
        ->set('form.components.0.recipe_id', (string) $this->quiche->id)
        ->call('save')
        ->assertHasErrors('form.components.0.recipe_id');
});

test('la fiche affiche la sous-recette avec un lien, et la recette dupliquée la garde', function () {
    $this->quiche->steps()->create(['position' => 1, 'instruction' => 'Étaler la pâte brisée dans le moule.']);

    Livewire::test(ShowPage::class, ['recipe' => $this->quiche->fresh()])
        ->assertSee('Sous-recettes')
        ->assertSee('Pâte brisée')
        ->assertSeeHtml('underline">pâte brisée</a>');

    $copy = app(RecipeDuplicator::class)->duplicate($this->quiche->fresh());

    expect($copy->components()->first()->component_recipe_id)->toBe($this->pate->id);
});
