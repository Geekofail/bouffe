<?php

use App\Enums\Course;
use App\Livewire\Receptions\Index as IndexPage;
use App\Livewire\Receptions\Show as ShowPage;
use App\Models\Guest;
use App\Models\MealOccasion;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Planning\PrepReminderPlanner;
use App\Services\Receptions\ReceptionPlanner;
use App\Services\Receptions\Receptions;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Réceptions (lot 20 — module 21) : menu par plat, rétroplanning, carte, souvenirs.
 */

require_once __DIR__.'/../Shopping/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-12 10:00'));      // lundi
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);

    $this->slot = MealSlot::factory()->create(['name' => 'Dîner']);
    $this->receptions = app(Receptions::class);
    $this->planner = app(ReceptionPlanner::class);

    // Samedi 17 octobre, 20 h.
    $this->occasion = $this->receptions->create('2026-10-17', $this->slot->id, 'Anniversaire de Julie', '20:00');

    $this->bourguignon = recipeWith('Bœuf bourguignon', 6, [[1200, 'g', 'Bœuf haché', false]]);
    $this->bourguignon->update(['prep_minutes' => 30, 'cook_minutes' => 180, 'rest_minutes' => 0]);

    $this->fondant = recipeWith('Fondant au chocolat', 6, [[200, 'g', 'Beurre', false]]);
    $this->fondant->update(['prep_minutes' => 20, 'cook_minutes' => 25, 'rest_minutes' => 120]);
});

test('organiser une réception crée la case avec son nom et son heure', function () {
    expect($this->occasion->title)->toBe('Anniversaire de Julie')
        ->and($this->occasion->serve_time)->toBe('20:00')
        ->and($this->occasion->serveAt()->format('Y-m-d H:i'))->toBe('2026-10-17 20:00')
        ->and($this->planner->isReception($this->occasion))->toBeTrue();
});

test('le menu range les plats par place, plusieurs recettes dans une même case', function () {
    $this->receptions->addDish($this->occasion, $this->fondant->id, Course::Dessert, 6);
    $this->receptions->addDish($this->occasion, $this->bourguignon->id, Course::Main, 6);

    $courses = $this->planner->courses($this->occasion->fresh());

    expect($courses->pluck('label')->all())->toBe(['Plat', 'Dessert'])
        ->and(PlannedMeal::whereDate('date', '2026-10-17')->count())->toBe(2);
});

test('le rétroplanning remonte le temps depuis le moment où chaque plat est servi', function () {
    $this->receptions->addDish($this->occasion, $this->bourguignon->id, Course::Main, 6);
    $this->receptions->addDish($this->occasion, $this->fondant->id, Course::Dessert, 6);

    $timeline = $this->planner->timeline($this->occasion->fresh())->keyBy('title');

    // Plat servi à 21 h (20 h + 1 h) : 30 min + 3 h → début à 17 h 30, cuisson à 18 h.
    expect($timeline['Commencer : Bœuf bourguignon']['at']->format('H:i'))->toBe('17:30')
        ->and($timeline['Lancer la cuisson : Bœuf bourguignon']['at']->format('H:i'))->toBe('18:00')
        // Dessert servi à 22 h : 20 + 25 + 120 min → 19 h 15.
        ->and($timeline['Commencer : Fondant au chocolat']['at']->format('H:i'))->toBe('19:15')
        ->and($timeline['Faire les courses de la réception']['bucket'])->toBe('before')
        ->and($timeline['Mettre la table']['bucket'])->toBe('last');
});

test('un repos long et le fromage sont traités à part', function () {
    $this->fondant->update(['rest_minutes' => 720]);     // repos 12 h : la veille, via R21
    $this->receptions->addDish($this->occasion, $this->fondant->id, Course::Dessert, 6);
    $cheese = recipeWith('Plateau de fromages', 6, [[400, 'g', 'Comté', false]]);
    $this->receptions->addDish($this->occasion, $cheese->id, Course::Cheese, 6);

    $timeline = $this->planner->timeline($this->occasion->fresh());

    expect($timeline->firstWhere('kind', 'r21')['title'])->toContain('fondant au chocolat')
        ->and($timeline->firstWhere('title', 'Sortir le fromage du réfrigérateur')['at']->format('H:i'))->toBe('20:45');
});

test('cocher une tâche la garde cochée et coche le rappel correspondant', function () {
    $this->receptions->addDish($this->occasion, $this->bourguignon->id, Course::Main, 6);
    app(PrepReminderPlanner::class)->sync(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'));

    $reminder = Reminder::where('key', 'like', 'reception:start:%')->first();
    expect($reminder)->not->toBeNull()
        ->and($reminder->type->value)->toBe('reception');

    $key = substr($reminder->key, strlen('reception:'));
    $this->planner->toggle($this->occasion->fresh(), $key);

    expect($reminder->fresh()->status)->toBe(Reminder::DONE)
        ->and($this->planner->timeline($this->occasion->fresh())->firstWhere('key', $key)['done'])->toBeTrue();
});

test('sans heure saisie, aucun rappel de réception n\'est créé', function () {
    $this->occasion->update(['serve_time' => null]);
    $this->receptions->addDish($this->occasion, $this->bourguignon->id, Course::Main, 6);

    expect(Reminder::where('key', 'like', 'reception:%')->count())->toBe(0);
});

test('la carte de menu s\'imprime et se partage en texte', function () {
    $this->receptions->addDish($this->occasion, $this->bourguignon->id, Course::Main, 6);
    $this->occasion->update(['menu_message' => 'Merci à tous !']);

    $this->get(route('receptions.menu', $this->occasion))
        ->assertOk()
        ->assertSee('Anniversaire de Julie')
        ->assertSee('Bœuf bourguignon')
        ->assertSee('Merci à tous !');

    expect($this->planner->menuText($this->occasion->fresh()))
        ->toContain("Plat\n  · Bœuf bourguignon")
        ->toContain('20 h 00');
});

test('l\'écran de la réception ajoute un plat, change sa place et enregistre l\'heure', function () {
    $page = Livewire::test(ShowPage::class, ['occasion' => $this->occasion])
        ->set('newRecipeId', $this->bourguignon->id)
        ->set('newCourse', 'plat')
        ->set('newServings', 8)
        ->call('addDish')
        ->assertSee('Bœuf bourguignon');

    $meal = PlannedMeal::firstWhere('recipe_id', $this->bourguignon->id);
    expect($meal->servings)->toBe(8.0)->and($meal->course)->toBe(Course::Main);

    $page->call('setCourse', $meal->id, 'entree')
        ->set('serveTime', '19:30')
        ->call('saveTime')
        ->assertHasNoErrors();

    expect($meal->fresh()->course)->toBe(Course::Starter)
        ->and($this->occasion->fresh()->serve_time)->toBe('19:30');

    Livewire::test(ShowPage::class, ['occasion' => $this->occasion->fresh()])
        ->set('serveTime', '25:99')
        ->call('saveTime')
        ->assertHasErrors('serveTime');
});

test('le souvenir apparaît sur la fiche de l\'invité', function () {
    Storage::fake('local');
    $this->travelBack();     // les fichiers téléversés temporaires de Livewire sont datés avec l'heure réelle
    $julie = Guest::factory()->create(['name' => 'Julie']);
    $this->occasion->guests()->attach($julie);

    Livewire::test(ShowPage::class, ['occasion' => $this->occasion])
        ->set('memoryNote', 'Julie a adoré le fondant.')
        ->set('photo', UploadedFile::fake()->image('souper.jpg', 1200, 800))
        ->call('saveMemory')
        ->assertHasNoErrors();

    $occasion = $this->occasion->fresh();
    expect($occasion->memory_photo)->toStartWith('recipes/receptions/');

    $this->get(route('guests.show', $julie))->assertSee('Julie a adoré le fondant.');
    $this->get(route('receptions.photo', ['occasion' => $occasion->id, 'size' => 'thumb']))->assertOk();
});

test('la liste sépare réceptions à venir et souvenirs', function () {
    $old = MealOccasion::create(['date' => '2026-09-20', 'meal_slot_id' => $this->slot->id, 'title' => 'Pendaison de crémaillère', 'memory_note' => 'Superbe soirée']);

    Livewire::test(IndexPage::class)
        ->assertSee('Anniversaire de Julie')
        ->assertSee('Pendaison de crémaillère')
        ->assertSee('Superbe soirée');

    Livewire::test(IndexPage::class)
        ->set('title', 'Noël')
        ->set('date', '2026-12-24')
        ->set('slotId', $this->slot->id)
        ->set('serveTime', '19:00')
        ->call('create')
        ->assertRedirect();

    expect(MealOccasion::where('title', 'Noël')->first()->serve_time)->toBe('19:00');
});

test('la préparation se fait avant l\'arrivée des invités, la cuisson peut attendre', function () {
    $curry = recipeWith('Curry express', 4, [[400, 'g', 'Bœuf haché', false]]);
    $curry->update(['prep_minutes' => 10, 'cook_minutes' => 20, 'rest_minutes' => 0]);
    $this->receptions->addDish($this->occasion, $curry->id, Course::Main, 6);

    $timeline = $this->planner->timeline($this->occasion->fresh())->keyBy('title');

    // Servi à 21 h : on pourrait commencer à 20 h 30, mais les invités sont là depuis 20 h.
    expect($timeline['Commencer : Curry express']['at']->format('H:i'))->toBe('19:50')
        ->and($timeline['Lancer la cuisson : Curry express']['at']->format('H:i'))->toBe('20:40')
        ->and($timeline['Lancer la cuisson : Curry express']['bucket'])->toBe('during');
});
