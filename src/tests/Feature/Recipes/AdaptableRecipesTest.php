<?php

use App\Enums\RestrictionType;
use App\Livewire\Recipes\Cook;
use App\Livewire\Settings\Equipment as EquipmentScreen;
use App\Livewire\Settings\Substitutions as SubstitutionsScreen;
use App\Models\Ingredient;
use App\Models\IngredientSubstitution;
use App\Models\MealSlot;
use App\Models\Product;
use App\Models\RecipePhoto;
use App\Models\RecipeStepNote;
use App\Models\User;
use App\Services\People\HouseholdPeople;
use App\Services\Planning\GuestCompatibility;
use App\Services\Planning\HouseholdService;
use App\Services\Planning\WeekFiller;
use App\Services\Recipes\KitchenEquipment;
use App\Services\Recipes\StepNotes;
use App\Services\Recipes\Substitutions;
use App\Services\Stock\ProductAllergens;
use App\Services\Stock\ProductLookup;
use App\Services\Stock\RecipeSuggester;
use App\Services\Stock\StockManager;
use App\Support\AllergenGroups;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\SubstitutionSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__.'/../Shopping/helpers.php';

/*
 * Lot 40 — Recettes qui s'adaptent : remplacements (40.1, R43), notes d'étape (40.2), équipement
 * (40.3) et allergènes des produits (40.4, R44).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-11 10:00'));   // mercredi
    $this->pierre = User::factory()->create(['name' => 'Pierre']);
    $this->actingAs($this->pierre);
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class, SubstitutionSeeder::class]);
    $this->stock = app(StockManager::class);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner']);
    $this->substitutions = app(Substitutions::class);
});

function catalogItem(string $name): Ingredient
{
    return Ingredient::firstWhere('name', $name);
}

/* ================================================================ 40.1 Remplacements */

test('la liste commune de départ (Q58) : ingrédients ajoutés au catalogue, ré-exécutable', function () {
    $count = IngredientSubstitution::count();
    (new SubstitutionSeeder)->run();

    expect($count)->toBeGreaterThan(35)
        ->and(IngredientSubstitution::count())->toBe($count)
        ->and(catalogItem('Crème de soja'))->not->toBeNull()
        ->and(IngredientSubstitution::query()->whereNotNull('household_id')->count())->toBe(0)
        ->and($this->substitutions->for(catalogItem('Crème liquide')->id)->pluck('substitute.name')->all())->toBe(['Crème de soja', 'Lait de coco', 'Crème fraîche épaisse']);
});

test('ordre : la recette, puis le foyer, puis la liste commune ; un remplacement commun se masque', function () {
    $recipe = recipeWith('Poulet à la crème', 2, [[20, 'cl', 'Crème liquide', false]]);
    $this->substitutions->add(catalogItem('Crème liquide')->id, catalogItem('Fromage blanc')->id, 1, null, 'notre astuce');
    $this->substitutions->add(catalogItem('Crème liquide')->id, catalogItem('Yaourt nature')->id, '0,5', $recipe->id);

    expect($this->substitutions->for(catalogItem('Crème liquide')->id, $recipe)->pluck('substitute.name')->take(3)->all())->toBe(['Yaourt nature', 'Fromage blanc', 'Crème de soja'])
        ->and($this->substitutions->for(catalogItem('Crème liquide')->id)->pluck('substitute.name')->all())->not->toContain('Yaourt nature')
        ->and(fn () => $this->substitutions->add(catalogItem('Sel')->id, catalogItem('Sel')->id))->toThrow(InvalidArgumentException::class, 'lui-même')
        ->and(fn () => $this->substitutions->add(catalogItem('Sel')->id, catalogItem('Poivre')->id, 50))->toThrow(InvalidArgumentException::class, 'rapport');

    $soja = $this->substitutions->for(catalogItem('Crème liquide')->id)->firstWhere('substitute.name', 'Crème de soja');
    Livewire::test(SubstitutionsScreen::class)
        ->assertSee('Crème liquide')
        ->call('remove', $soja->id)
        ->assertDispatched('notify', message: 'Remplacement masqué pour le foyer.')
        ->assertSee('Remplacements communs masqués (1)');

    expect($this->substitutions->for(catalogItem('Crème liquide')->id)->pluck('substitute.name')->all())->not->toContain('Crème de soja')
        ->and(IngredientSubstitution::query()->whereKey($soja->id)->exists())->toBeTrue();

    Livewire::test(SubstitutionsScreen::class)->call('restore', $soja->id)
        ->set('ingredientId', catalogItem('Beurre')->id)->set('substituteId', catalogItem('Huile d\'olive')->id)->set('ratio', '0,7')->set('note', 'pour la poêle')
        ->call('add')->assertHasNoErrors()
        ->assertDispatched('notify', message: 'Remplacement ajouté : Beurre → huile d\'olive.');

    expect($this->substitutions->for(catalogItem('Crème liquide')->id)->pluck('substitute.name')->all())->toContain('Crème de soja')
        ->and(IngredientSubstitution::query()->where('household_id', \App\Support\CurrentHousehold::id())->where('ingredient_id', catalogItem('Beurre')->id)->value('ratio'))->toBe('0.700');
});

test('R43 : jamais un remplacement qui contient l\'allergène de quelqu\'un à table', function () {
    $leo = app(HouseholdPeople::class)->save(null, ['name' => 'Léo', 'appetite' => 'moyen']);
    app(HouseholdService::class)->addRestriction($leo, RestrictionType::Allergy, catalogItem('Lait demi-écrémé')->id);
    $people = app(HouseholdService::class)->people();

    expect(AllergenGroups::ofName('Crème fraîche épaisse'))->toBe(['en:milk'])
        ->and(AllergenGroups::ofName('Lait de coco'))->toBe([])
        ->and(AllergenGroups::ofName('Noix de muscade'))->toBe([])
        ->and(AllergenGroups::ofName('Pâte feuilletée'))->toBe(['en:gluten'])
        ->and($this->substitutions->for(catalogItem('Crème liquide')->id, null, $people)->pluck('substitute.name')->all())->toBe(['Crème de soja', 'Lait de coco'])
        ->and($this->substitutions->for(catalogItem('Beurre')->id, null, $people)->pluck('substitute.name')->all())->toBe(['Margarine', 'Huile d\'olive', 'Huile de tournesol']);

    app(HouseholdService::class)->addRestriction($leo, RestrictionType::Allergy, catalogItem('Crème de soja')->id);
    expect($this->substitutions->for(catalogItem('Crème liquide')->id, null, app(HouseholdService::class)->people())->pluck('substitute.name')->all())->toBe(['Lait de coco']);
});

test('mode cuisine : « pas de crème ? », en stock, noté dans la note de cuisine sans changer la recette', function () {
    $recipe = recipeWith('Poulet à la crème', 2, [[2, 'piece', 'Blanc de poulet', false], [20, 'cl', 'Crème liquide', false], [160, 'g', 'Beurre', false]]);
    $this->stock->add(['ingredient_id' => catalogItem('Crème de soja')->id, 'quantity' => 25, 'unit_id' => \App\Models\Unit::firstWhere('code', 'cl')->id]);

    Livewire::test(Cook::class, ['recipe' => $recipe])
        ->assertSee('Pas de crème liquide ?')
        ->assertSee('200 ml de crème de soja', false)
        ->assertSee('en stock')
        ->assertSee('Pas de beurre ?')
        ->assertSee('130 g d', false)
        ->call('useSubstitute', catalogItem('Crème liquide')->id, catalogItem('Crème de soja')->id)
        ->assertSet('note', 'Crème de soja au lieu de crème liquide.')
        ->call('useSubstitute', catalogItem('Crème liquide')->id, catalogItem('Crème de soja')->id)
        ->assertSet('note', 'Crème de soja au lieu de crème liquide.');

    expect($recipe->fresh()->ingredients->pluck('ingredient.name')->all())->toContain('Crème liquide');
});

test('« Que cuisiner ? » : une recette devient faisable avec un remplacement en stock', function () {
    $recipe = recipeWith('Poulet à la crème', 2, [[2, 'piece', 'Blanc de poulet', false], [20, 'cl', 'Crème liquide', false]]);
    $this->stock->add(['ingredient_id' => catalogItem('Blanc de poulet')->id, 'quantity' => 2]);

    $before = app(RecipeSuggester::class)->evaluate($recipe, 2);
    expect($before['missing'])->toHaveCount(1);

    $this->stock->add(['ingredient_id' => catalogItem('Crème de soja')->id, 'quantity' => 25, 'unit_id' => \App\Models\Unit::firstWhere('code', 'cl')->id]);
    $after = app(RecipeSuggester::class)->evaluate($recipe, 2);

    expect($after['missing'])->toBe([])
        ->and($after['available'])->toBe(2.0)
        ->and($after['lines'][catalogItem('Crème liquide')->id]['text'])->toBe('avec crème de soja à la place');

    $this->get(route('suggestions'))->assertOk()->assertSeeInOrder(['Faisable tout de suite', 'Poulet à la crème', 'Avec crème de soja à la place de crème liquide']);
});

test('liste de courses : « ou : crème de soja, en stock »', function () {
    $manager = app(\App\Services\Shopping\ShoppingListManager::class);
    $item = $manager->addManual($manager->currentOrNew(), '20 cl de crème liquide');
    $presenter = app(\App\Services\Shopping\ShoppingItemPresenter::class);

    expect($presenter->alternative($item))->toBeNull();

    $this->stock->add(['ingredient_id' => catalogItem('Crème de soja')->id, 'quantity' => 25, 'unit_id' => \App\Models\Unit::firstWhere('code', 'cl')->id]);
    expect(app(\App\Services\Shopping\ShoppingItemPresenter::class)->alternative($item->fresh()))->toBe('ou : crème de soja, en stock');
});

test('une variante (ou l\'assistant) laisse un remplacement propre à la recette', function () {
    $recipe = recipeWith('Quiche', 4, [[20, 'cl', 'Crème liquide', false], [150, 'g', 'Lardons', false]]);
    $variant = $recipe->variants()->create(['name' => 'Végétarienne']);
    $lardons = $recipe->ingredients()->where('ingredient_id', catalogItem('Lardons')->id)->first();

    app(\App\Services\Recipes\VariantService::class)->setSwap($variant, $lardons->id, catalogItem('Tofu')->id, 120, $lardons->unit_id);
    $substitution = IngredientSubstitution::query()->where('recipe_id', $recipe->id)->sole();

    expect($substitution->substitute_id)->toBe(catalogItem('Tofu')->id)
        ->and($substitution->ratio)->toBe('0.800')
        ->and($substitution->source)->toBe('variant')
        ->and($substitution->note)->toBe('variante « Végétarienne »')
        ->and($this->substitutions->for(catalogItem('Lardons')->id, $recipe)->first()->substitute->name)->toBe('Tofu');
});

/* ================================================================ 40.2 Notes d'étape */

test('notes et photos suivent l\'étape quand on en insère une avant', function () {
    $recipe = recipeWith('Gâteau', 6, [[200, 'g', 'Farine', false]]);
    $recipe->steps()->createMany([
        ['position' => 1, 'instruction' => 'Mélanger la farine et le sucre.'],
        ['position' => 2, 'instruction' => 'Cuire 30 minutes.'],
    ]);
    $cuire = $recipe->steps()->where('position', 2)->value('uid');
    expect($cuire)->toHaveLength(10);

    app(StepNotes::class)->add($recipe, 2, 'Notre four chauffe fort : 170 °C');
    RecipePhoto::create(['recipe_id' => $recipe->id, 'kind' => 'step', 'step_number' => 2, 'step_uid' => $cuire, 'path' => 'recipes/photos/x', 'position' => 1]);

    // On insère une étape au début, depuis la fiche.
    $edit = Livewire::test(\App\Livewire\Recipes\Edit::class, ['recipe' => $recipe->fresh()]);
    $steps = $edit->get('form.steps');
    array_unshift($steps, ['uid' => 'nouvelle', 'group_name' => '', 'instruction' => 'Préchauffer le four.', 'step_uid' => '', 'adult_help' => null]);
    $edit->set('form.steps', $steps)->call('save')->assertHasNoErrors();

    $recipe->refresh();
    expect($recipe->steps()->where('position', 3)->value('uid'))->toBe($cuire)
        ->and(app(StepNotes::class)->byStep($recipe)->keys()->all())->toBe([3])
        ->and(RecipePhoto::query()->value('step_number'))->toBe(3);

    Livewire::test(Cook::class, ['recipe' => $recipe])
        ->set('step', 3)->assertSee('Notre four chauffe fort : 170 °C')
        ->set('step', 1)->assertDontSee('Notre four chauffe fort')
        ->set('stepNote', 'Beurrer le moule')->call('addStepNote', 1)->assertHasNoErrors()->assertSee('Beurrer le moule')
        ->set('stepNote', '')->call('addStepNote', 1)->assertHasErrors('stepNote');

    $this->get(route('recipes.show', $recipe))->assertOk()->assertSeeInOrder(['Beurrer le moule', 'Notre four chauffe fort']);

    Livewire::test(Cook::class, ['recipe' => $recipe])->set('step', 1)
        ->call('deleteStepNote', RecipeStepNote::query()->where('note', 'Beurrer le moule')->value('id'));
    expect(RecipeStepNote::count())->toBe(1);
});

/* ================================================================ 40.3 Équipement */

test('l\'équipement : deviné d\'après les étapes, réglé à la maison, écarté des idées', function () {
    $equipment = app(KitchenEquipment::class);
    $gratin = recipeWith('Gratin', 4, [[800, 'g', 'Pomme de terre', false]]);
    $gratin->steps()->create(['position' => 1, 'instruction' => 'Enfourner 45 minutes à 180 °C.']);
    $soupe = recipeWith('Velouté', 4, [[2, 'piece', 'Poireau', false]]);
    $soupe->steps()->create(['position' => 1, 'instruction' => 'Cuire dans une casserole puis mixer.']);
    $salade = recipeWith('Salade', 2, [[1, 'piece', 'Salade verte', false]]);
    $salade->steps()->create(['position' => 1, 'instruction' => 'Écraser à la fourchette et mélanger.']);

    expect($equipment->required($gratin->fresh()))->toBe(['four'])
        ->and($equipment->required($soupe->fresh()))->toBe(['plaques', 'mixeur'])
        ->and($equipment->required($salade->fresh()))->toBe([])
        ->and($equipment->available())->toBe($equipment->keys());

    Livewire::test(EquipmentScreen::class)->set('items', ['plaques', 'micro-ondes'])->call('save')
        ->assertDispatched('notify', message: 'Équipement enregistré.')
        ->assertSee('Pas faisables ici (2)')->assertSee('demande : four');

    $titles = collect(app(WeekFiller::class)->ideasFor('2026-11-12', $this->dinner, 3))->pluck('title')->all();
    expect($titles)->toContain('Salade')->not->toContain('Gratin')->not->toContain('Velouté');

    // La recette se corrige : le velouté se fait au presse-purée.
    Livewire::test(\App\Livewire\Recipes\Edit::class, ['recipe' => $soupe->fresh()])
        ->assertSet('form.equipment', ['plaques', 'mixeur'])->assertSet('form.equipmentSet', false)
        ->set('form.equipment', ['plaques'])->set('form.equipmentSet', true)->call('save')->assertHasNoErrors();
    expect($soupe->fresh()->equipment)->toBe(['plaques'])
        ->and(collect(app(WeekFiller::class)->ideasFor('2026-11-12', $this->dinner, 3))->pluck('title')->all())->toContain('Velouté');

    // Le sélecteur prévient.
    Livewire::test(\App\Livewire\Planner\MealPicker::class)->call('open', '2026-11-12', $this->dinner->id)
        ->set('search', 'Gratin')->assertSee('Demande : four (pas dans notre cuisine)');
});

test('un séjour a son équipement : « pas de four au chalet »', function () {
    $stays = app(\App\Services\Stays\StayService::class);
    $stay = $stays->create(['name' => 'Chalet', 'starts_on' => '2026-12-20', 'ends_on' => '2026-12-24']);
    $gratin = recipeWith('Gratin', 4, [[800, 'g', 'Pomme de terre', false]]);
    $gratin->steps()->create(['position' => 1, 'instruction' => 'Enfourner 45 minutes.']);
    recipeWith('Raclette', 4, [[800, 'g', 'Pomme de terre', false]]);

    Livewire::test(\App\Livewire\Stays\Show::class, ['stay' => $stay])
        ->call('edit')->assertSet('sameEquipment', true)
        ->set('sameEquipment', false)->set('equipment', ['plaques'])->call('save')->assertHasNoErrors();

    expect($stay->fresh()->equipment)->toBe(['plaques'])
        ->and(app(KitchenEquipment::class)->possible($gratin->fresh(), $stay->fresh()))->toBeFalse()
        ->and(app(KitchenEquipment::class)->possible($gratin->fresh()))->toBeTrue();

    $meal = $stays->addMeal($stay->fresh(), '2026-12-21', $this->dinner->id, $gratin->id);
    expect(collect($stays->conflicts($meal, $stay->fresh()))->pluck('message')->all())->toContain('Demande : four — pas sur place');

    $results = Livewire::test(\App\Livewire\Stays\Show::class, ['stay' => $stay->fresh()])
        ->set('tab', 'repas')->call('openCell', '2026-12-22', $this->dinner->id)
        ->instance()->recipeResults->pluck('title')->all();
    expect($results)->toContain('Raclette')->not->toContain('Gratin');
});

/* ================================================================ 40.4 Allergènes des produits */

test('au scan, les allergènes et traces d\'Open Food Facts sont gardés ; inconnus, ils le disent', function () {
    Http::fake([
        ProductLookup::ENDPOINT.'3270190006787*' => Http::response(['status' => 1, 'product' => [
            'product_name_fr' => 'Pâte feuilletée', 'brands' => 'Marque d\'essai', 'quantity' => '230 g',
            'allergens_tags' => ['en:gluten', 'en:milk'], 'traces_tags' => ['en:nuts', 'invalide !'],
        ]]),
        ProductLookup::ENDPOINT.'3017620422003*' => Http::response(['status' => 1, 'product' => ['allergens_tags' => ['en:milk', 'en:nuts', 'en:soybeans']]]),
    ]);
    $product = app(ProductLookup::class)->find('3270190006787')['product'];
    $service = app(ProductAllergens::class);

    expect($product->allergens)->toBe(['en:gluten', 'en:milk'])
        ->and($product->traces)->toBe(['en:nuts'])
        ->and($service->summary($product))->toBe('contient : gluten, lait · traces possibles : fruits à coque')
        ->and($service->summary(new Product(['label' => 'x'])))->toBeNull();

    $leo = app(HouseholdPeople::class)->save(null, ['name' => 'Léo', 'appetite' => 'moyen']);
    app(HouseholdService::class)->addRestriction($leo, RestrictionType::Allergy, catalogItem('Poudre d\'amande')->id);
    app(HouseholdService::class)->addRestriction($this->pierre, RestrictionType::Dislike, catalogItem('Beurre')->id);
    $problems = $service->problems($product, app(HouseholdService::class)->people()->merge([app(HouseholdPeople::class)->forUser($this->pierre)->load('restrictions.ingredient')]));

    expect(collect($problems)->pluck('message')->sort()->values()->all())->toBe([
        'Contient : lait — n\'aime pas : Pierre',
        'Peut contenir des traces de fruits à coque — allergie de Léo',
    ])->and(collect($problems)->pluck('level')->unique()->all())->toBe([GuestCompatibility::WARNING]);

    // Le scan les montre ; un produit connu avant le lot 40 se vérifie d'un geste.
    Livewire::test(\App\Livewire\Stock\Scan::class)->call('lookup', '3270190006787')
        ->assertSee('contient : gluten, lait')->assertSee('allergie de Léo');

    $old = Product::create(['barcode' => '3017620422003', 'label' => 'Pâte à tartiner', 'source' => Product::OPEN_FOOD_FACTS]);
    Livewire::test(\App\Livewire\Stock\Scan::class)->call('lookup', '3017620422003')
        ->assertSee('Vérifier sur Open Food Facts')->call('checkAllergens')->assertSee('contient : lait, fruits à coque, soja');
    expect($old->fresh()->allergens_checked_at)->not->toBeNull();
});

test('un produit du stock qui contient l\'allergène de quelqu\'un est signalé : au stock, et en prévoyant le repas', function () {
    $leo = app(HouseholdPeople::class)->save(null, ['name' => 'Léo', 'appetite' => 'moyen']);
    app(HouseholdService::class)->addRestriction($leo, RestrictionType::Allergy, catalogItem('Poudre d\'amande')->id);
    $product = Product::create(['barcode' => '111', 'label' => 'Pâte feuilletée', 'brand' => 'Marque d\'essai', 'ingredient_id' => catalogItem('Pâte feuilletée')->id,
        'source' => Product::OPEN_FOOD_FACTS, 'allergens' => ['en:gluten'], 'traces' => ['en:nuts'], 'allergens_checked_at' => now()]);
    $item = $this->stock->add(['ingredient_id' => catalogItem('Pâte feuilletée')->id, 'quantity' => 1]);
    $item->forceFill(['product_id' => $product->id])->save();
    $tarte = recipeWith('Tarte fine', 4, [[1, 'piece', 'Pâte feuilletée', false]]);

    $conflicts = app(GuestCompatibility::class)->conflicts($tarte, app(HouseholdService::class)->eatersAt('2026-11-12', $this->dinner->id));
    expect(collect($conflicts)->pluck('message')->all())->toBe(['« Marque d\'essai — Pâte feuilletée » en stock : peut contenir des traces de fruits à coque — allergie de Léo (d\'après Open Food Facts)']);

    Livewire::test(\App\Livewire\Stock\Index::class)->assertSeeHtml('data-allergen-alert');

    // Tâche planifiée : les produits jamais vérifiés, pas les autres.
    Http::fake([ProductLookup::ENDPOINT.'*' => Http::response(['status' => 1, 'product' => ['allergens_tags' => []]])]);
    $unchecked = Product::create(['barcode' => '222', 'label' => 'Biscuits', 'ingredient_id' => catalogItem('Farine')->id, 'source' => Product::OPEN_FOOD_FACTS]);
    $this->stock->add(['ingredient_id' => catalogItem('Farine')->id, 'quantity' => 1])->forceFill(['product_id' => $unchecked->id])->save();

    expect(app(ProductLookup::class)->refreshPendingAllergens())->toBe(1)
        ->and($unchecked->fresh()->allergens)->toBe([])
        ->and(app(ProductAllergens::class)->summary($unchecked->fresh()))->toBe('aucun allergène déclaré');
});
