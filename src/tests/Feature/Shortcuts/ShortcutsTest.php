<?php

use App\Enums\UserRole;
use App\Livewire\Account\Shortcuts as ShortcutsPage;
use App\Livewire\Recipes\Import;
use App\Livewire\Recipes\Inbox;
use App\Livewire\Recipes\Index as RecipeIndex;
use App\Models\ActivityEvent;
use App\Models\ApiToken;
use App\Models\Household;
use App\Models\InboxItem;
use App\Models\MealSlot;
use App\Models\Recipe;
use App\Models\ShoppingListItem;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Households\HouseholdManager;
use App\Services\Planning\WeekPlanner;
use App\Services\Recipes\RecipeInbox;
use App\Services\Shortcuts\DictationParser;
use App\Services\Shortcuts\ShortcutTokens;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Shopping/helpers.php';

/*
 * Lot 38 — Raccourcis, voix et partage : jeton et adresses des raccourcis (38.1, R39), envoyer une
 * recette (38.2), « À trier » (38.3), mode cuisine mains libres (38.4).
 */

function inboxRecipePage(string $name = 'Tarte tatin'): string
{
    return '<!doctype html><html><head><script type="application/ld+json">'.json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Recipe',
        'name' => $name,
        'recipeYield' => '6',
        'image' => 'https://exemple.test/tatin.jpg',
        'recipeIngredient' => ['6 pommes', '150 g de sucre', '1 pâte brisée'],
        'recipeInstructions' => [['@type' => 'HowToStep', 'text' => 'Caraméliser le sucre.'], ['@type' => 'HowToStep', 'text' => 'Cuire 35 minutes.']],
    ], JSON_UNESCAPED_UNICODE).'</script></head><body></body></html>';
}

/** Appel d'un raccourci, avec le jeton dans l'en-tête. */
function shortcut(string $method, string $route, array $data = [], ?string $token = null)
{
    return test()->call($method, route($route), $data, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.($token ?? test()->token), 'HTTP_ACCEPT' => 'text/plain']);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 17:30'));   // jeudi
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    $this->home = Household::query()->orderBy('id')->first();
    $this->pierre = User::factory()->create(['name' => 'Pierre']);
    $this->actingAs($this->pierre);
    $this->token = app(ShortcutTokens::class)->create($this->pierre);
    auth()->logout();                                         // les raccourcis n'ont pas de session
});

/* ================================================================ 38.1 Dictée et jeton */

test('ce que Siri a compris devient des articles', function () {
    $parser = app(DictationParser::class);

    expect($parser->items('deux baguettes et du beurre'))->toBe(['2 baguettes', 'Beurre'])
        ->and($parser->items('Ajoute du lait, des œufs et un litre de jus d’orange à la liste'))->toBe(['Lait', 'Œufs', '1 litre de jus d\'orange'])
        ->and($parser->items('une douzaine d’œufs'))->toBe(['12 œufs'])
        ->and($parser->items('cinq cents grammes de farine'))->toBe(['500 grammes de farine'])
        ->and($parser->items('il n’y a plus de sel'))->toBe(['Sel'])
        ->and($parser->items('une baguette, une baguette'))->toBe(['Baguette'])
        ->and($parser->items('  '))->toBe([]);
});

test('le jeton : montré une fois, gardé en empreinte, remplacé ou révoqué', function () {
    $record = ApiToken::query()->sole();

    expect($record->token_hash)->toBe(hash('sha256', $this->token))
        ->and($record->hint)->toBe(substr($this->token, -4))
        ->and(str_starts_with($this->token, 'bouffe_'))->toBeTrue();

    shortcut('GET', 'shortcuts.menu')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect($record->fresh()->uses)->toBe(1)->and($record->fresh()->last_used_at)->not->toBeNull();

    // Un nouveau jeton révoque l'ancien.
    $this->actingAs($this->pierre);
    Livewire::test(ShortcutsPage::class)->assertSee('…'.$record->hint)->call('create')->assertSee('Bearer bouffe_')->assertSee('il ne sera plus affiché');
    auth()->logout();
    shortcut('GET', 'shortcuts.menu')->assertUnauthorized()->assertSee('Jeton inconnu ou révoqué');

    $this->actingAs($this->pierre);
    Livewire::test(ShortcutsPage::class)->call('revoke')->assertSee('Créer mon jeton');
    expect(ApiToken::query()->whereNull('revoked_at')->count())->toBe(0);

    auth()->logout();
    shortcut('GET', 'shortcuts.menu', token: 'pas-un-jeton')->assertUnauthorized();
});

test('« ajoute aux courses » : sur la liste en cours, noté au journal', function () {
    shortcut('POST', 'shortcuts.shopping', ['texte' => 'deux baguettes et du beurre'])
        ->assertOk()
        ->assertSee('Ajouté aux courses : 2 baguettes et beurre.', false);

    expect(ShoppingListItem::query()->pluck('label')->all())->toBe(['2 baguettes', 'Beurre'])
        ->and(ShoppingListItem::query()->firstWhere('label', 'Beurre')->ingredient_id)->not->toBeNull()
        ->and(ActivityEvent::query()->where('summary', 'like', '%par un raccourci%')->sole()->user_id)->toBe($this->pierre->id);

    shortcut('POST', 'shortcuts.shopping', ['texte' => ''])->assertStatus(422)->assertSee('champ « texte »', false);
});

test('« ajoute au stock » et les comptes en consultation', function () {
    shortcut('POST', 'shortcuts.stock', ['texte' => 'six œufs'])->assertOk()->assertSee('Ajouté au stock : 6 œufs.', false);
    expect((float) StockItem::query()->sole()->quantity)->toBe(6.0);

    $viewer = User::factory()->create(['role' => UserRole::Viewer]);
    $this->actingAs($viewer);
    $token = app(ShortcutTokens::class)->create($viewer);
    auth()->logout();

    shortcut('POST', 'shortcuts.stock', ['texte' => 'lait'], $token)->assertForbidden()->assertSee('consultation');
    shortcut('POST', 'shortcuts.inbox', ['texte' => 'Tarte'], $token)->assertForbidden();
    shortcut('POST', 'shortcuts.shopping', ['texte' => 'lait'], $token)->assertOk();
});

test('« on mange quoi ? » : la suite de la journée, puis demain', function () {
    $lunch = MealSlot::factory()->create(['name' => 'Déjeuner', 'sort_order' => 1]);
    $dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);
    $this->actingAs($this->pierre);
    $planner = app(WeekPlanner::class);
    $chili = $planner->addRecipe('2026-10-15', $dinner, recipeWith('Chili con carne', 4, [[500, 'g', 'Bœuf haché', false]]), 4);
    $planner->addRecipe('2026-10-15', $lunch, recipeWith('Omelette', 2, [[4, 'piece', 'Œuf', false]]), 2);
    $planner->addLeftover('2026-10-16', $lunch, $chili, 2);
    auth()->logout();

    shortcut('GET', 'shortcuts.menu')->assertOk()
        ->assertSee('Ce soir : chili con carne. Demain. Déjeuner : restes de chili con carne.', false)
        ->assertDontSee('omelette');

    $this->travelTo(Carbon::parse('2026-10-15 09:00'));
    test()->call('GET', route('shortcuts.menu', ['quand' => 'aujourdhui']), [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token])
        ->assertSee('Aujourd\'hui. Déjeuner : omelette. Dîner : chili con carne.', false);
});

test('60 demandes par heure au plus', function () {
    for ($i = 0; $i < 60; $i++) {
        shortcut('GET', 'shortcuts.menu')->assertOk();
    }

    shortcut('GET', 'shortcuts.menu')->assertTooManyRequests()->assertSee('60 par heure');
});

test('le jeton agit dans son foyer, pas dans un autre', function () {
    $other = app(HouseholdManager::class)->create('Les voisins');
    $voisin = User::factory()->create();
    app(HouseholdManager::class)->attach($other, $voisin, UserRole::Owner);
    app(HouseholdManager::class)->detach($this->home, $voisin);
    $this->actingAs($voisin);
    $token = app(ShortcutTokens::class)->create($voisin);
    auth()->logout();

    shortcut('POST', 'shortcuts.shopping', ['texte' => 'lessive'], $token)->assertOk();

    expect(ShoppingListItem::withoutGlobalScopes()->firstWhere('label', 'Lessive')->household_id)->toBe($other->id)
        ->and(ShoppingListItem::withoutGlobalScopes()->where('household_id', $this->home->id)->count())->toBe(0);
});

/* ================================================================ 38.2 · 38.3 À trier */

test('envoyer une adresse : la page est lue tout de suite, relue plus tard dans l\'import', function () {
    Http::fake(['exemple.test/tatin' => Http::response(inboxRecipePage()), 'exemple.test/rien' => Http::response('<html>rien</html>')]);

    shortcut('POST', 'shortcuts.inbox', ['url' => 'https://exemple.test/tatin'])->assertOk()->assertSee('« Tarte tatin » est dans À trier, prête à relire.', false);
    shortcut('POST', 'shortcuts.inbox', ['url' => 'https://exemple.test/rien'])->assertOk()->assertSee('la page n\'a pas pu être lue', false);
    shortcut('POST', 'shortcuts.inbox', ['url' => 'https://exemple.test/tatin'])->assertOk();          // déjà reçue : pas de doublon

    $item = InboxItem::query()->firstWhere('url', 'https://exemple.test/tatin');
    expect(InboxItem::count())->toBe(2)
        ->and($item->status)->toBe('ready')
        ->and($item->via)->toBe('raccourci')
        ->and($item->payload['name'])->toBe('Tarte tatin');

    $this->actingAs($this->pierre);
    Livewire::test(Inbox::class)->assertSee('Tarte tatin')->assertSee('Par un raccourci')->assertSee('Aucune recette reconnue')
        ->call('keep', $item->id)->assertRedirect(route('recipes.import', ['a-trier' => $item->id]));

    // Relecture : l'ébauche est là, sans relire la page ; l'enregistrer la range comme gardée.
    Http::fake(fn () => throw new RuntimeException('la page ne doit pas être relue'));
    $this->withoutExceptionHandling();
    $import = Livewire::withQueryParams(['a-trier' => $item->id])->test(Import::class)
        ->assertSet('reviewing', true)->assertSet('form.title', 'Tarte tatin')->assertSet('inboxId', $item->id);
    $import->set('downloadImage', false)->call('save');

    $recipe = Recipe::query()->firstWhere('title', 'Tarte tatin');
    expect($recipe->is_to_test)->toBeTrue()
        ->and($item->fresh()->status)->toBe('kept')
        ->and($item->fresh()->recipe_id)->toBe($recipe->id);
});

test('un texte, une photo, jeter ; et le rappel quand la boîte déborde', function () {
    Storage::fake('local');
    $this->actingAs($this->pierre);

    Livewire::test(Inbox::class)
        ->set('entry', "Soupe de potiron\n1 potiron\n1 l de bouillon\nCuire 30 minutes, mixer.")
        ->call('add')
        ->assertSee('Soupe de potiron');

    $text = InboxItem::query()->sole();
    expect($text->kind)->toBe('text')->and($text->status)->toBe('ready')
        ->and(app(RecipeInbox::class)->draft($text)['title'])->toBe('Soupe de potiron');

    // Photo : gardée, lue au passage de la tâche planifiée (ici, sans service de lecture réglé).
    $photo = app(RecipeInbox::class)->receivePhoto(UploadedFile::fake()->image('page.jpg'), 'raccourci');
    expect(fn () => app(RecipeInbox::class)->receivePhoto(UploadedFile::fake()->create('virus.exe', 10), 'raccourci'))->toThrow(InvalidArgumentException::class);
    Storage::disk('local')->assertExists($photo->photo_path);

    app(RecipeInbox::class)->processPending();
    expect($photo->fresh()->status)->toBe('failed')->and($photo->fresh()->error)->not->toBeEmpty();

    Livewire::test(Inbox::class)->call('discard', $photo->id);
    expect($photo->fresh()->status)->toBe('discarded')->and($photo->fresh()->photo_path)->toBeNull();
    Storage::disk('local')->assertMissing($photo->getOriginal('photo_path'));

    foreach (range(1, 10) as $i) {
        app(RecipeInbox::class)->receiveText('Recette '.$i.' : de quoi faire un bon plat', 'app');
    }

    Livewire::test(RecipeIndex::class)->assertSee('À trier')->assertSee('11 recettes attendent dans « À trier »', false);
});

test('partager vers Bouffe sur Android : la cible de partage', function () {
    Http::fake(['exemple.test/*' => Http::response(inboxRecipePage('Clafoutis'))]);
    $this->actingAs($this->pierre);

    $this->get(route('share.target', ['title' => 'Clafoutis', 'text' => 'Regarde ça https://exemple.test/clafoutis']))
        ->assertRedirect(route('recipes.inbox'));

    expect(InboxItem::query()->sole()->only(['via', 'url', 'title', 'status']))
        ->toBe(['via' => 'partage', 'url' => 'https://exemple.test/clafoutis', 'title' => 'Clafoutis', 'status' => 'ready']);

    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
    expect($manifest['share_target']['action'])->toBe('/partager');
});

/* ================================================================ 38.4 Mains libres */

test('mode cuisine : chaque étape porte le texte à lire, et le bouton « Lire à voix haute »', function () {
    $this->actingAs($this->pierre);
    $recipe = recipeWith('Pâtes', 2, [[200, 'g', 'Pâtes', false]]);
    $recipe->steps()->create(['position' => 1, 'instruction' => 'Cuire 10 minutes.']);

    $this->get(route('recipes.cook', $recipe))->assertOk()
        ->assertSee('bouffeCookMode()', false)
        ->assertSee('data-hands-free', false)
        ->assertSee('Mise en place. Il vous faut : 200 g de pâtes.', false);

    Livewire::test(\App\Livewire\Recipes\Cook::class, ['recipe' => $recipe])->call('next')
        ->assertSee('data-speak="Étape 1. Cuire 10 minutes."', false)
        ->assertSee('data-step-timer', false);
});
