<?php

use App\Enums\Course;
use App\Enums\UserRole;
use App\Livewire\Planner\CookMeal;
use App\Livewire\Planner\Week;
use App\Livewire\Recipes\Collections as CollectionsPage;
use App\Livewire\Recipes\CollectionShow;
use App\Livewire\Recipes\Cook;
use App\Livewire\Recipes\Edit;
use App\Livewire\Recipes\History;
use App\Livewire\Recipes\Photos;
use App\Livewire\Recipes\RecipeCollections;
use App\Livewire\Recipes\ShareLinks;
use App\Livewire\Recipes\Show as RecipeShow;
use App\Models\MealSlot;
use App\Models\Recipe;
use App\Models\RecipeCollection;
use App\Models\RecipePhoto;
use App\Models\RecipeRevision;
use App\Models\RecipeShareLink;
use App\Models\User;
use App\Services\Households\HouseholdManager;
use App\Services\Linked\HouseholdLinks;
use App\Services\Planning\MealCookPlan;
use App\Services\Planning\WeekPlanner;
use App\Services\Recipes\Collections;
use App\Services\Recipes\RecipePhotos;
use App\Services\Recipes\RecipeRevisions;
use App\Services\Recipes\RecipeShares;
use App\Support\CurrentHousehold;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Shopping/helpers.php';

/*
 * Lot 31 — Recettes enrichies : collections (31.1), plusieurs photos (31.2), cuisiner un repas
 * complet (31.3), partager par lien (31.4), historique (31.5).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 16:00'));   // jeudi
    Storage::fake('local');
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    $this->actingAs($this->pierre = User::factory()->create(['name' => 'Pierre']));
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);

    $this->gratin = recipeWith('Gratin dauphinois', 4, [[1000, 'g', 'Pomme de terre', false], [50, 'cl', 'Crème liquide', false]]);
    $this->gratin->update(['prep_minutes' => 20, 'cook_minutes' => 60]);
    $this->gratin->steps()->createMany([
        ['position' => 1, 'instruction' => 'Éplucher et couper les pommes de terre en rondelles fines.'],
        ['position' => 2, 'instruction' => 'Disposer dans un plat, napper de crème.'],
        ['position' => 3, 'instruction' => 'Cuire au four 1 h à 160 °C.'],
    ]);
    $this->tart = Recipe::factory()->create(['title' => 'Tarte aux pommes', 'servings' => 6, 'prep_minutes' => 20, 'cook_minutes' => 35]);
    $this->tart->steps()->createMany([
        ['position' => 1, 'instruction' => 'Étaler la pâte et disposer les pommes.'],
        ['position' => 2, 'instruction' => 'Cuire 35 min au four.'],
    ]);
});

/* ================================================================ 31.1 Collections */

test('créer une collection, y ranger des recettes, les ordonner et les retirer', function () {
    Livewire::test(CollectionsPage::class)
        ->call('create')
        ->set('name', 'Recettes de mamie')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('recipes.collections.show', RecipeCollection::sole()->id));

    $collection = RecipeCollection::sole();

    Livewire::test(CollectionShow::class, ['collection' => $collection])
        ->set('search', 'gratin')
        ->assertSee('Gratin dauphinois')
        ->call('add', $this->gratin->id)
        ->call('add', $this->tart->id)
        ->assertSeeInOrder(['Gratin dauphinois', 'Tarte aux pommes'])
        ->call('move', $this->tart->id, -1)
        ->assertSeeInOrder(['Tarte aux pommes', 'Gratin dauphinois'])
        ->call('remove', $this->gratin->id)
        ->set('search', '')
        ->assertDontSee('Gratin dauphinois');

    // Une recette dans plusieurs collections ; le nom est unique.
    $noel = app(Collections::class)->create('Noël');
    app(Collections::class)->toggle($noel, $this->tart);
    expect($this->tart->collections()->pluck('name')->all())->toBe(['Noël', 'Recettes de mamie'])
        ->and(fn () => app(Collections::class)->create('noël'))->toThrow(InvalidArgumentException::class, 'Une collection porte déjà ce nom.');

    Livewire::test(CollectionsPage::class)->assertSee('Recettes de mamie')->assertSee('1 recette');
    $this->get(route('recipes.show', $this->tart))->assertSee('Dans :')->assertSee('Noël');
});

test('la fiche recette range la recette dans une collection, existante ou nouvelle', function () {
    $noel = app(Collections::class)->create('Noël');

    Livewire::test(RecipeCollections::class, ['recipe' => $this->gratin])
        ->call('open')
        ->assertSee('Noël')
        ->call('toggle', $noel->id)
        ->assertDispatched('notify', message: 'Ajoutée à la collection.')
        ->set('newName', 'Quand Léo vient')
        ->call('createAndAdd')
        ->assertDispatched('notify', message: 'Collection « Quand Léo vient » créée, avec cette recette.')
        ->set('newName', 'noël')
        ->call('createAndAdd')
        ->assertHasErrors('newName');

    expect($this->gratin->collections()->pluck('name')->all())->toBe(['Noël', 'Quand Léo vient']);

    Livewire::test(RecipeShow::class, ['recipe' => $this->gratin])->assertSee('Collections…')->assertSee('Partager un lien…')->assertSee('Historique');
});

test('imprimer une collection en carnet et la supprimer sans toucher aux recettes', function () {
    $collection = app(Collections::class)->create('Noël');
    app(Collections::class)->toggle($collection, $this->gratin);

    expect(app(Collections::class)->printUrl($collection))->toContain('recettes='.$this->gratin->id)->toContain('titre=No%C3%ABl');
    $this->get(app(Collections::class)->printUrl($collection))->assertOk()->assertSee('Gratin dauphinois');

    Livewire::test(CollectionShow::class, ['collection' => $collection])->call('delete')->assertRedirect(route('recipes.collections'));
    expect(RecipeCollection::count())->toBe(0)->and($this->gratin->fresh())->not->toBeNull();
});

test('une collection partagée apparaît chez les foyers reliés, avec les seules recettes qu\'ils peuvent lire', function () {
    $home = CurrentHousehold::get();
    $manager = app(HouseholdManager::class);
    $other = $manager->create('Léo et Clara', $this->pierre);
    $leo = User::factory()->create(['name' => 'Léo']);
    $manager->attach($other, $leo, UserRole::Owner);
    $manager->detach($home, $leo);
    $links = app(HouseholdLinks::class);
    $invite = $links->invite($home, $this->pierre);
    $links->accept($invite['link'], $other, $leo);

    $this->gratin->update(['visibility' => 'linked']);
    $collection = app(Collections::class)->create('Noël');
    app(Collections::class)->toggle($collection, $this->gratin);
    app(Collections::class)->toggle($collection, $this->tart);   // privée

    Livewire::test(CollectionShow::class, ['collection' => $collection])
        ->call('openShare')
        ->assertSee('Tarte aux pommes. Elles n\'apparaîtront pas', false)
        ->call('share', true, false)
        ->assertDispatched('notify', message: 'Collection partagée avec les foyers reliés.');

    $this->actingAs($leo);
    CurrentHousehold::forget();

    Livewire::test(CollectionsPage::class)->assertSee('Partagées par nos proches')->assertSee('Noël');
    $this->get(route('recipes.collections.show', $collection->id))->assertOk()->assertSee('Gratin dauphinois')->assertDontSee('Tarte aux pommes');
    expect(fn () => app(Collections::class)->toggle($collection, $this->gratin))->toThrow(InvalidArgumentException::class);

    // Pierre ouvre aussi les recettes privées de la collection.
    $this->actingAs($this->pierre);
    CurrentHousehold::forget();
    Livewire::test(CollectionShow::class, ['collection' => $collection])->call('share', true, true)
        ->assertDispatched('notify', message: 'Collection partagée avec les foyers reliés ; 1 recette ouverte aussi.');
    expect($this->tart->fresh()->visibility)->toBe('linked');

    // Un foyer non relié ne voit rien.
    $stranger = $manager->create('Les voisins', $this->pierre);
    $this->actingAs($this->pierre);
    CurrentHousehold::run($stranger, fn () => expect(app(Collections::class)->sharedWithMe()->count())->toBe(0));
});

/* ================================================================ 31.2 Photos */

test('photos d\'étapes et « notre version » : ajout, affichage, photo principale, suppression', function () {
    $this->travelBack();     // les fichiers téléversés temporaires de Livewire sont datés avec l'heure réelle
    Livewire::test(Photos::class, ['recipe' => $this->gratin])
        ->call('openForm', 'step', 3)
        ->assertSet('stepNumber', 3)
        ->set('upload', UploadedFile::fake()->image('four.jpg', 800, 600))
        ->set('caption', 'Bien doré')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('recipe-photos-changed')
        ->call('openForm', 'ours')
        ->set('upload', UploadedFile::fake()->image('assiette.jpg', 800, 600))
        ->call('save')
        ->assertSee('Notre version');

    $step = RecipePhoto::firstWhere('kind', 'step');
    expect($step->step_number)->toBe(3)->and($step->caption)->toBe('Bien doré')
        ->and(Storage::disk('local')->exists($step->path.'.jpg'))->toBeTrue()
        ->and(Storage::disk('local')->exists($step->path.'-thumb.jpg'))->toBeTrue();

    $this->get($step->url('thumb'))->assertOk();
    $this->get(route('recipes.show', $this->gratin))->assertSee($step->url('thumb'), false);

    // Le mode cuisine la montre à l'étape 3, pas avant.
    Livewire::test(Cook::class, ['recipe' => $this->gratin])
        ->call('goTo', 2)->assertDontSee('Bien doré')
        ->call('goTo', 3)->assertSee('Bien doré');

    // Mauvaise étape, mauvais fichier.
    expect(fn () => app(RecipePhotos::class)->add($this->gratin, UploadedFile::fake()->image('x.jpg'), 'step', 9))
        ->toThrow(InvalidArgumentException::class, 'Choisissez l\'étape de la photo.');
    Livewire::test(Photos::class, ['recipe' => $this->gratin])->call('openForm', 'ours')
        ->set('upload', UploadedFile::fake()->create('menu.pdf', 50, 'application/pdf'))->call('save')->assertHasErrors('upload');

    // Photo principale : une copie ; puis suppression des fichiers.
    $ours = RecipePhoto::firstWhere('kind', 'ours');
    Livewire::test(Photos::class, ['recipe' => $this->gratin])->call('makeMain', $ours->id);
    expect($this->gratin->fresh()->photo_path)->not->toBeNull()->not->toBe($ours->path);

    Livewire::test(Photos::class, ['recipe' => $this->gratin])->call('delete', $step->id);
    expect(RecipePhoto::find($step->id))->toBeNull()
        ->and(Storage::disk('local')->exists($step->path.'.jpg'))->toBeFalse();

    // Supprimer la recette emporte ses photos.
    Livewire::test(RecipeShow::class, ['recipe' => $this->gratin])->call('delete');
    expect(RecipePhoto::count())->toBe(0)->and(Storage::disk('local')->exists($ours->path.'.jpg'))->toBeFalse();
});

test('« une photo de notre version ? » est proposée sur un repas mangé', function () {
    $meal = app(WeekPlanner::class)->addRecipe('2026-10-15', $this->dinner, $this->gratin, 4);

    Livewire::test(Week::class)->call('selectMeal', $meal->id)->assertDontSee('Une photo de notre version ?');

    $meal->update(['cooked_at' => now()]);
    Livewire::test(Week::class)->call('selectMeal', $meal->id)->assertSee('Une photo de notre version ?');

    $this->travelBack();
    Livewire::test(Photos::class, ['recipe' => $this->gratin, 'mealId' => $meal->id, 'compact' => true])
        ->call('openForm', 'ours')
        ->set('upload', UploadedFile::fake()->image('ce-soir.jpg'))
        ->call('save')
        ->assertHasNoErrors();

    expect(RecipePhoto::sole()->planned_meal_id)->toBe($meal->id);
});

/* ================================================================ 31.3 Repas complet */

test('cuisiner un repas complet : étapes entrelacées pour que tout soit prêt à l\'heure', function () {
    $planner = app(WeekPlanner::class);
    $main = $planner->addRecipe('2026-10-15', $this->dinner, $this->gratin, 4);
    $dessert = $planner->addRecipe('2026-10-15', $this->dinner, $this->tart, 4);
    $main->update(['course' => Course::Main]);
    $dessert->update(['course' => Course::Dessert]);

    $plan = app(MealCookPlan::class)->build(app(MealCookPlan::class)->meals('2026-10-15', $this->dinner), Carbon::parse('2026-10-15 19:00'));
    $times = $plan['tasks']->map(fn ($t) => $t['start']->format('H:i').' '.$t['title'].' '.$t['number'])->all();

    // Gratin servi à 19:15 : 80 min, dont 60 annoncées à l'étape 3 → 10 min pour chacune des deux premières.
    // Tarte servie à 19:45 : 55 min, dont 35 annoncées à l'étape 2 → 20 min pour l'étape 1.
    expect($times)->toBe([
        '17:55 Gratin dauphinois 1',
        '18:05 Gratin dauphinois 2',
        '18:15 Gratin dauphinois 3',
        '18:50 Tarte aux pommes 1',
        '19:10 Tarte aux pommes 2',
    ])
        ->and($plan['dishes']->pluck('moment')->map->format('H:i')->all())->toBe(['19:15', '19:45']);

    Livewire::test(CookMeal::class, ['date' => '2026-10-15', 'slot' => $this->dinner->id])
        ->assertSet('time', '19:00')
        ->assertSeeInOrder(['17:55', 'Éplucher', '18:50', 'Étaler la pâte'])
        ->assertSee('Minuteur '.\App\Support\Duration::format(60))
        ->set('time', '20:00')
        ->assertSee('18:55')
        ->call('toggle', $main->id.'-1')
        ->assertSee('Étapes faites : 1 / 5')                         // lot 41 : enregistré, partagé entre appareils
        ->call('markAllCooked')
        ->assertDispatched('notify', message: 'Repas marqué comme mangé (2 plats).');

    expect($main->fresh()->cooked_at)->not->toBeNull()->and($dessert->fresh()->cooked_at)->not->toBeNull();

    // Accès depuis le planning et l'accueil.
    Livewire::test(Week::class)->call('selectMeal', $main->id)->assertSee('Cuisiner le repas complet (2 plats)');
    $this->get(route('planner.cook', ['date' => '2026-10-15', 'slot' => $this->dinner->id]))->assertOk()->assertSee('Cuisiner le repas');
});

test('restes et plats cuisinés à l\'avance : réchauffer 20 minutes avant', function () {
    $planner = app(WeekPlanner::class);
    $source = $planner->addRecipe('2026-10-14', $this->dinner, $this->gratin, 6);
    $planner->addLeftover('2026-10-15', $this->dinner, $source, 2);

    $plan = app(MealCookPlan::class)->build(app(MealCookPlan::class)->meals('2026-10-15', $this->dinner), Carbon::parse('2026-10-15 19:00'));

    expect($plan['tasks'])->toHaveCount(1)
        ->and($plan['tasks'][0]['text'])->toBe('Réchauffer les restes : Gratin dauphinois.')
        ->and($plan['tasks'][0]['start']->format('H:i'))->toBe('18:55');   // plat servi à 19:15
});

/* ================================================================ 31.4 Partage par lien */

test('partager une recette par lien : lecture seule, sans notes ni prix, 30 jours, révocable', function () {
    $this->gratin->update(['notes' => 'Secret : une pointe de muscade']);
    $this->gratin->cookNotes()->create(['user_id' => $this->pierre->id, 'note' => 'Trop salé la dernière fois']);

    $component = Livewire::test(ShareLinks::class, ['recipe' => $this->gratin])
        ->call('open')
        ->call('create')
        ->assertSee('copiez-le maintenant');

    $url = $component->get('newUrl');
    expect($url)->toStartWith(url('/partage/recette/'));

    // Sans être connecté.
    auth()->logout();
    CurrentHousehold::forget();
    $this->get($url.'?portions=2')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('Gratin dauphinois')
        ->assertSee('500 g')->assertSee('de pommes de terre')   // 1 kg pour 4 → 500 g pour 2
        ->assertSee('Éplucher et couper')
        ->assertDontSee('muscade')
        ->assertDontSee('Trop salé')
        ->assertDontSee('€');

    $link = RecipeShareLink::sole();
    expect($link->views)->toBe(1)->and($link->expires_at->toDateString())->toBe('2026-11-14');

    // Révoqué, ou expiré : la page le dit.
    $this->actingAs($this->pierre);
    Livewire::test(ShareLinks::class, ['recipe' => $this->gratin])->call('open')->assertSee('1 ouverture')->call('revoke', $link->id);
    auth()->logout();
    $this->get($url)->assertNotFound()->assertSee('Ce lien n\'est plus valable', false);

    $this->actingAs($this->pierre);
    $fresh = app(RecipeShares::class)->create($this->gratin)['url'];
    $this->travel(31)->days();
    auth()->logout();
    $this->get($fresh)->assertNotFound();
    $this->get('/partage/recette/'.str_repeat('a', 40))->assertNotFound();
});

/* ================================================================ 31.5 Historique */

test('historique : qui a modifié quoi, voir une version, y revenir', function () {
    $this->gratin->update(['created_by' => $this->pierre->id]);
    $monique = User::factory()->create(['name' => 'Monique']);

    // Première modification : l'état d'avant devient la version d'origine.
    $this->actingAs($monique);
    Livewire::test(Edit::class, ['recipe' => $this->gratin])
        ->set('form.title', 'Gratin de mamie')
        ->set('form.ingredients.1.quantity', '60')
        ->set('form.steps.2.instruction', 'Cuire au four 1 h 15 à 150 °C.')
        ->call('save')
        ->assertHasNoErrors();

    $revisions = RecipeRevision::query()->orderBy('id')->get();
    expect($revisions)->toHaveCount(2)
        ->and($revisions[0]->action)->toBe('origin')
        ->and($revisions[0]->snapshot['title'])->toBe('Gratin dauphinois')
        ->and($revisions[1]->user_id)->toBe($monique->id)
        ->and($revisions[1]->summary)->toBe('Titre, 1 modifié, étapes');

    // Rien de changé : pas de nouvelle version.
    Livewire::test(Edit::class, ['recipe' => $this->gratin->fresh()])->call('save');
    expect(RecipeRevision::count())->toBe(2);

    $gratin = $this->gratin->fresh();
    Livewire::test(History::class, ['recipe' => $gratin])
        ->assertSeeInOrder(['Titre, 1 modifié, étapes', 'Monique', 'Version d\'origine', 'Pierre'])
        ->assertSee('Version actuelle')
        ->call('toggle', $revisions[0]->id)
        ->assertSee('Cuire au four 1 h à 160 °C.')
        ->call('restore', $revisions[0]->id)
        ->assertRedirect(route('recipes.show', 'gratin-dauphinois'));

    $gratin = $gratin->fresh(['ingredients', 'steps']);
    expect($gratin->title)->toBe('Gratin dauphinois')
        ->and((float) $gratin->ingredients[1]->quantity)->toBe(50.0)
        ->and($gratin->steps[2]->instruction)->toBe('Cuire au four 1 h à 160 °C.')
        ->and(RecipeRevision::query()->latest('id')->first()->summary)->toStartWith('Retour à la version du 15 oct. 2026');

    $service = app(RecipeRevisions::class);
    expect($service->same(RecipeRevision::query()->latest('id')->first()->snapshot, $service->snapshot($gratin)))->toBeTrue();
});

test('revenir à une version recrée un ingrédient supprimé entre-temps', function () {
    $service = app(RecipeRevisions::class);
    $before = $service->snapshot($this->gratin);
    $service->record($this->gratin, $before);   // rien de changé : seulement la version d'origine
    expect(RecipeRevision::count())->toBe(1);

    $origin = RecipeRevision::sole();
    $snapshot = $origin->snapshot;
    $snapshot['ingredients'][0]['ingredient_id'] = 999999;
    $snapshot['ingredients'][0]['name'] = 'Topinambour';
    $origin->update(['snapshot' => $snapshot]);

    $service->restore($this->gratin, $origin);
    expect($this->gratin->fresh()->ingredients()->with('ingredient')->get()->pluck('ingredient.name')->all())->toContain('Topinambour');
});
