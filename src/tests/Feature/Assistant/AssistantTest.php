<?php

use App\Livewire\Recipes\AssistantTransform;
use App\Livewire\Recipes\CookQuestion;
use App\Livewire\Recipes\Import;
use App\Livewire\Settings\AssistantSettings;
use App\Livewire\Stock\WhatToMake;
use App\Models\AssistantUsage;
use App\Models\Guest;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeVariant;
use App\Models\StockItem;
use App\Models\Tag;
use App\Models\User;
use App\Services\Assistant\AssistantFailed;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\RecipeAssistant;
use App\Support\Settings;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__.'/../Shopping/helpers.php';

/*
 * Lot 33 — Assistant culinaire : transformer une recette (33.1), que faire avec… (33.2), compléter un
 * import (33.3), une question en cuisine (33.4), réglages et plafond (33.5), confidentialité (R34).
 *
 * Aucun appel réseau : les réponses de Mistral sont SIMULÉES (Http::fake), construites d'après le
 * format documenté de l'API (choices.0.message.content, usage.prompt_tokens / completion_tokens).
 */

/** Réponse simulée de Mistral : $content est encodé en JSON s'il s'agit d'un tableau. */
function mistralReply(array|string $content, int $in = 800, int $out = 400): \GuzzleHttp\Promise\PromiseInterface
{
    return Http::response([
        'id' => 'test', 'object' => 'chat.completion', 'model' => 'mistral-small-2603',
        'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => is_array($content) ? json_encode($content) : $content]]],
        'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out, 'total_tokens' => $in + $out],
    ]);
}

/** Corps de toutes les demandes envoyées, en un seul texte. */
function sentToAssistant(): string
{
    return Http::recorded()->map(fn ($pair) => json_encode($pair[0]->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->implode("\n");
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 16:00'));
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    $this->actingAs($this->pierre = User::factory()->create(['name' => 'Pierre']));
    Settings::set('receipts.mistral_key', 'cle-de-test');

    $this->quiche = recipeWith('Quiche lorraine', 4, [[200, 'g', 'Lardons', false], [20, 'cl', 'Crème liquide', false], [1, null, 'Oignon', true]]);
    $this->quiche->update(['prep_minutes' => 15, 'cook_minutes' => 35]);
    $this->quiche->steps()->createMany([
        ['position' => 1, 'instruction' => 'Faire revenir les lardons et l\'oignon.'],
        ['position' => 2, 'instruction' => 'Battre la crème avec les œufs, verser sur la pâte.'],
        ['position' => 3, 'instruction' => 'Cuire 35 min à 180 °C.'],
    ]);
    $this->quiche->cookNotes()->create(['user_id' => $this->pierre->id, 'note' => 'Note secrète de Pierre']);

    // Ce qui ne doit JAMAIS partir chez l'assistant (R34).
    $guest = Guest::factory()->create(['name' => 'Mamie Jeanne']);
    $guest->restrictions()->create(['type' => 'allergy', 'ingredient_id' => Ingredient::firstWhere('name', 'Beurre')->id, 'note' => 'allergie de Jeanne']);
    StockItem::create(['ingredient_id' => Ingredient::firstWhere('name', 'Chocolat noir')->id, 'label' => 'Chocolat du placard', 'quantity' => 200, 'is_present' => true, 'storage_location_id' => \App\Models\StorageLocation::query()->value('id')]);
});

/* ================================================================ Disponibilité (principe 5) */

test('sans clé, désactivé ou au plafond : aucun bouton, aucune demande', function () {
    Http::fake();
    $service = app(AssistantService::class);

    expect($service->available())->toBeTrue();
    $this->get(route('recipes.show', $this->quiche))->assertSee('Adapter avec l\'assistant', false);

    Settings::set('receipts.mistral_key', '');
    expect($service->status())->toMatchArray(['available' => false])
        ->and($service->status()['reason'])->toContain('Aucune clé Mistral');
    $this->get(route('recipes.show', $this->quiche))->assertDontSee('Adapter avec l\'assistant', false);
    $this->get(route('recipes.cook', ['recipe' => $this->quiche, 'etape' => 1]))->assertDontSee('Une question ?');
    $this->get(route('suggestions'))->assertOk()->assertSee('Que faire avec');

    Settings::set('receipts.mistral_key', 'cle-de-test');
    Settings::set('assistant.enabled', false);
    expect($service->available())->toBeFalse();

    Settings::set('assistant.enabled', true);
    Settings::set('assistant.monthly_cap', 0.5);
    AssistantUsage::create(['kind' => 'ideas', 'tokens_in' => 1, 'tokens_out' => 1, 'cost_estimate' => 0.5, 'succeeded' => true, 'created_at' => now()]);
    expect($service->status()['reason'])->toContain('Plafond de 0,5 € atteint');

    expect(fn () => app(RecipeAssistant::class)->ideas('courgette'))->toThrow(AssistantFailed::class);
    Http::assertNothingSent();

    // Le mois suivant, l'assistant revient.
    $this->travelTo(Carbon::parse('2026-11-02 10:00'));
    expect($service->available())->toBeTrue();
});

test('une demande qui pourrait dépasser le plafond est refusée avant envoi', function () {
    Http::fake();
    Settings::set('assistant.monthly_cap', 0.01);
    AssistantUsage::create(['kind' => 'ideas', 'tokens_in' => 1, 'tokens_out' => 1, 'cost_estimate' => 0.0095, 'succeeded' => true, 'created_at' => now()]);

    // 3 000 jetons de réponse au pire = 0,0016 € : 0,0095 + 0,0016 > 0,01.
    expect(fn () => app(RecipeAssistant::class)->ideas('courgette, feta'))
        ->toThrow(AssistantFailed::class, 'dépasserait le plafond');
    Http::assertNothingSent();
});

test('le plafond vaut pour toute l\'installation, tous foyers confondus', function () {
    Settings::set('assistant.monthly_cap', 1);
    AssistantUsage::withoutGlobalScopes()->insert([
        'household_id' => app(\App\Services\Households\HouseholdManager::class)->create('Léo et Clara')->id,
        'kind' => 'ideas', 'tokens_in' => 1, 'tokens_out' => 1, 'cost_estimate' => 0.4, 'succeeded' => true, 'created_at' => now(),
    ]);
    AssistantUsage::create(['kind' => 'question', 'tokens_in' => 1, 'tokens_out' => 1, 'cost_estimate' => 0.1, 'succeeded' => true, 'created_at' => now()]);

    expect(app(AssistantService::class)->usage())->toMatchArray(['count' => 2, 'cost' => 0.5, 'cap' => 1.0]);
});

/* ================================================================ 33.1 Transformer */

test('version végétarienne : brouillon surligné, enregistré comme variante', function () {
    Http::fake(['api.mistral.ai/*' => mistralReply([
        'name' => 'Version végétarienne',
        'summary' => 'Les lardons sont remplacés par du tofu fumé.',
        'lines' => [
            ['index' => 1, 'action' => 'replace', 'replacement' => '200 g de tofu'],
            ['index' => 2, 'action' => 'keep', 'replacement' => null],
            ['index' => 3, 'action' => 'keep', 'replacement' => null],
        ],
        'added' => [],
        'steps' => [
            ['text' => 'Faire revenir le tofu émietté et l\'oignon.', 'changed' => true],
            ['text' => 'Battre la crème avec les œufs, verser sur la pâte.', 'changed' => false],
            ['text' => 'Cuire 35 min à 180 °C.', 'changed' => false],
        ],
        'warning' => null,
    ], 900, 450)]);

    $component = Livewire::test(AssistantTransform::class, ['recipe' => $this->quiche])
        ->dispatch('open-recipe-assistant')
        ->set('preset', 'vegetarien')
        ->call('propose')
        ->assertHasNoErrors()
        ->assertSee('Proposé par l\'assistant')
        ->assertSee('→ 200 g de tofu')
        ->assertSee('Enregistrer comme variante');

    // Ce qui est parti : la recette et la consigne, rien d'autre (R34).
    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'https://api.mistral.ai/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer cle-de-test')
            && $body['model'] === 'mistral-small-latest'
            && $body['response_format']['type'] === 'json_schema'
            && $body['response_format']['json_schema']['strict'] === true;
    });
    $sent = sentToAssistant();
    expect($sent)->toContain('Quiche lorraine')->toContain('végétarienne')->toContain('200 g de lardons')
        ->not->toContain('Mamie')->not->toContain('Jeanne')->not->toContain('Chocolat')
        ->not->toContain('Note secr')->not->toContain('Pierre');

    $component->call('saveAsVariant');

    $variant = RecipeVariant::sole();
    $swap = $variant->swaps()->sole();
    expect($variant->name)->toBe('Version végétarienne')
        ->and($variant->note)->toContain('Proposée par l\'assistant')
        ->and($swap->recipe_ingredient_id)->toBe($this->quiche->ingredients[0]->id)
        ->and($swap->ingredient->name)->toBe('Tofu')
        ->and((float) $swap->quantity)->toBe(200.0);
    $component->assertRedirect(route('recipes.show', ['recipe' => $this->quiche, 'variante' => $variant->id]));

    $usage = AssistantUsage::sole();
    expect($usage->kind)->toBe('transform')->and($usage->succeeded)->toBeTrue()
        ->and($usage->tokens_in)->toBe(900)
        // (900 × 0,15 + 450 × 0,60) / 1 000 000 × 0,9
        ->and((float) $usage->cost_estimate)->toEqualWithDelta(0.0003645, 0.000001);
});

test('une proposition qui ajoute des ingrédients devient une nouvelle recette, relue à l\'import', function () {
    Http::fake(['api.mistral.ai/*' => mistralReply([
        'name' => 'Sans lactose',
        'summary' => 'Crème d\'avoine à la place de la crème.',
        'lines' => [
            ['index' => 1, 'action' => 'keep', 'replacement' => null],
            ['index' => 2, 'action' => 'replace', 'replacement' => '20 cl de crème d\'avoine'],
            ['index' => 3, 'action' => 'remove', 'replacement' => null],
        ],
        'added' => ['1 pincée de muscade'],
        'steps' => [
            ['text' => 'Faire revenir les lardons.', 'changed' => true],
            ['text' => 'Mélanger la crème d\'avoine et les œufs, verser sur la pâte.', 'changed' => true],
        ],
        'warning' => 'Vérifiez que la pâte est sans beurre.',
    ])]);

    $component = Livewire::test(AssistantTransform::class, ['recipe' => $this->quiche])
        ->call('open')->set('preset', 'sans-lactose')->call('propose')
        ->assertSee('Vérifiez que la pâte est sans beurre.')
        ->assertSee('+ 1 pincée de muscade')
        ->assertDontSee('Enregistrer comme variante')
        ->call('saveAsVariant')
        ->assertHasErrors('assistant');
    expect(RecipeVariant::count())->toBe(0);

    $component->call('asNewRecipe')->assertRedirect(route('recipes.import'));
    expect(session('assistant.draft'))->toMatchArray(['title' => 'Quiche lorraine — sans lactose']);

    $import = Livewire::test(Import::class)
        ->assertSet('reviewing', true)
        ->assertSee('Brouillon proposé par l\'assistant', false)
        ->assertSet('form.title', 'Quiche lorraine — sans lactose')
        ->assertSet('form.prep_minutes', 15);
    expect(collect($import->get('form.ingredients'))->pluck('name')->filter()->values()->all())
        ->toBe(['Lardons', 'Crème d\'avoine', 'Muscade'])
        ->and(session()->has('assistant.draft'))->toBeFalse();   // repris une seule fois

    $import->call('save')->assertHasNoErrors();
    $new = Recipe::where('title', 'Quiche lorraine — sans lactose')->sole();
    expect($new->source)->toContain('Adaptée par l\'assistant')
        ->and($new->is_to_test)->toBeTrue()
        ->and($new->steps()->count())->toBe(2);
});

test('transformation libre : consigne courte obligatoire ; erreur du service affichée et comptée', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['message' => 'Unauthorized'], 401)]);

    Livewire::test(AssistantTransform::class, ['recipe' => $this->quiche])
        ->call('open')
        ->set('preset', 'libre')->set('free', '')
        ->call('propose')->assertHasErrors('free')
        ->set('free', str_repeat('a', 151))
        ->call('propose')->assertHasErrors('free')
        ->set('free', 'sans four, à la poêle')
        ->call('propose')
        ->assertHasErrors('assistant')
        ->assertSee('La clé Mistral est refusée');

    expect(sentToAssistant())->toContain('sans four, à la poêle');
    $usage = AssistantUsage::sole();
    expect($usage->succeeded)->toBeFalse()->and((float) $usage->cost_estimate)->toBe(0.0);
    expect(app(AssistantService::class)->usage())->toMatchArray(['count' => 0, 'failed' => 1]);
});

test('un compte en consultation ne peut pas lancer de transformation', function () {
    Http::fake();
    $reader = User::factory()->create(['role' => \App\Enums\UserRole::Viewer]);
    $this->actingAs($reader);

    Livewire::test(AssistantTransform::class, ['recipe' => $this->quiche])->call('propose');
    Http::assertNothingSent();
    $this->get(route('recipes.show', $this->quiche))->assertDontSee('Adapter avec l\'assistant', false);
});

/* ================================================================ 33.2 Que faire avec… */

test('que faire avec : le carnet d\'abord, puis trois idées ; seule la liste tapée est envoyée', function () {
    $tian = recipeWith('Tian de courgettes', 4, [[600, 'g', 'Courgette', false], [100, 'g', 'Feta', false]]);
    recipeWith('Salade grecque', 2, [[100, 'g', 'Feta', false]]);

    Http::fake(['api.mistral.ai/*' => mistralReply(['ideas' => [
        ['title' => 'Courgettes farcies à la feta', 'description' => 'Au four.', 'servings' => 4, 'prep_minutes' => 20, 'cook_minutes' => 30,
            'ingredients' => ['4 courgettes', '150 g de feta', '1 oignon'], 'steps' => ['Évider les courgettes.', 'Farcir et cuire 30 min.']],
        ['title' => 'Risotto courgette feta', 'description' => '', 'servings' => 2, 'prep_minutes' => 10, 'cook_minutes' => 25,
            'ingredients' => ['200 g de riz arborio'], 'steps' => ['Cuire le riz.']],
        ['title' => 'Galettes de courgettes', 'description' => '', 'servings' => 3, 'prep_minutes' => 15, 'cook_minutes' => 10,
            'ingredients' => ['2 courgettes'], 'steps' => ['Râper.']],
    ]])]);

    $component = Livewire::test(WhatToMake::class)
        ->set('text', 'courgettes, feta et riz')
        ->call('search')
        ->assertSet('terms', ['courgettes', 'feta', 'riz'])
        ->assertSeeInOrder(['Tian de courgettes', 'Salade grecque'])
        ->assertSee('(2/3)');
    Http::assertNothingSent();   // la recherche dans le carnet reste locale

    $component->call('askIdeas')
        ->assertSee('Idées de l\'assistant')
        ->assertSee('Courgettes farcies à la feta')
        ->assertSee('Importer comme brouillon');

    expect(sentToAssistant())->toContain('courgettes, feta, riz')
        ->not->toContain('Chocolat')->not->toContain('Jeanne')->not->toContain('Tian');

    $component->call('import', 0)->assertRedirect(route('recipes.import'));
    Livewire::test(Import::class)
        ->assertSee('Brouillon proposé par l\'assistant', false)
        ->assertSet('form.title', 'Courgettes farcies à la feta')
        ->assertSet('form.source', 'Idée de l\'assistant');
});

/* ================================================================ 33.3 Compléter un import */

test('compléter un import : temps, difficulté et catégories connues, à cocher', function () {
    Tag::factory()->create(['name' => 'Plat principal']);
    Tag::factory()->create(['name' => 'Végétarien']);

    Http::fake(['api.mistral.ai/*' => mistralReply([
        'prep_minutes' => 20, 'cook_minutes' => 40, 'rest_minutes' => 0, 'difficulty' => 'medium',
        'tags' => ['plat principal', 'Catégorie inventée'],
    ], 500, 60)]);

    $component = Livewire::test(Import::class)
        ->set('tab', 'texte')
        ->set('text', "Gratin de courgettes\n\nIngrédients\n1 kg de courgettes\n100 g de feta\n\nPréparation\nCouper les courgettes.\nCuire 40 min.")
        ->call('analyse')
        ->assertSee('Compléter avec l\'assistant', false)
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSee('Proposé par l\'assistant')
        ->assertSee('Plat principal')
        ->assertDontSee('Catégorie inventée')
        ->assertSet('accepted', ['prep_minutes', 'cook_minutes', 'difficulty', 'tag:Plat principal']);

    expect(sentToAssistant())->toContain('Gratin de courgettes')->toContain('Végétarien')->not->toContain('Jeanne');

    $component->set('accepted', ['prep_minutes', 'difficulty', 'tag:Plat principal'])
        ->call('applySuggestion')
        ->assertSet('suggestion', null)
        ->assertSet('form.prep_minutes', 20)
        ->assertSet('form.cook_minutes', null)
        ->assertSet('form.difficulty', 'medium')
        ->call('save')->assertHasNoErrors();

    $recipe = Recipe::where('title', 'Gratin de courgettes')->sole();
    expect($recipe->difficulty->value)->toBe('medium')
        ->and($recipe->tags->pluck('name')->all())->toBe(['Plat principal']);
});

/* ================================================================ 33.4 Question en cuisine */

test('une question à une étape : réponse affichée, jamais enregistrée', function () {
    Http::fake(['api.mistral.ai/*' => mistralReply('Du lait entier fera l\'affaire, avec un œuf de plus.', 700, 30)]);
    $before = $this->quiche->steps()->pluck('instruction')->all();

    $this->get(route('recipes.cook', ['recipe' => $this->quiche, 'etape' => 2]))->assertSee('Une question ?');

    Livewire::test(CookQuestion::class, ['recipe' => $this->quiche, 'step' => 2])
        ->set('open', true)
        ->set('question', 'Je n\'ai pas de crème, quoi à la place ?')
        ->call('ask')
        ->assertHasNoErrors()
        ->assertSee('Du lait entier fera l\'affaire')
        ->assertSee('non enregistrée');

    Http::assertSent(fn (Request $r) => ! isset($r->data()['response_format']) && $r->data()['max_tokens'] === 300);
    expect(sentToAssistant())->toContain('étape 2 : Battre la crème')->toContain('pas de crème')
        ->not->toContain('Note secr')->not->toContain('Jeanne');
    expect($this->quiche->steps()->pluck('instruction')->all())->toBe($before);
    expect(AssistantUsage::sole()->kind)->toBe('question');
});

/* ================================================================ 33.5 Réglages */

test('réglages : l\'administrateur active, plafonne et voit la consommation ; les autres lisent', function () {
    AssistantUsage::create(['kind' => 'transform', 'tokens_in' => 900, 'tokens_out' => 450, 'cost_estimate' => 0.000365, 'succeeded' => true, 'created_at' => now()]);
    AssistantUsage::create(['kind' => 'question', 'tokens_in' => 700, 'tokens_out' => 30, 'cost_estimate' => 0.000111, 'succeeded' => true, 'created_at' => now()]);

    $this->get(route('settings.assistant'))->assertOk()
        ->assertSee('Prêt')
        ->assertSee('Transformer une recette')
        ->assertSee('Ce qui est envoyé');
    $this->get(route('settings.index'))->assertSee('Assistant culinaire');

    Livewire::test(AssistantSettings::class)
        ->set('enabled', false)
        ->set('monthlyCap', '2,5')
        ->set('mistralKey', 'nouvelle-cle')
        ->call('save')
        ->assertHasNoErrors();

    expect(Settings::bool('assistant.enabled', true))->toBeFalse()
        ->and(Settings::float('assistant.monthly_cap'))->toBe(2.5)
        ->and(Settings::secret('receipts.mistral_key'))->toBe('nouvelle-cle')
        ->and(app(AssistantService::class)->status()['reason'])->toContain('désactivé');

    Livewire::test(AssistantSettings::class)->set('monthlyCap', '500')->call('save')->assertHasErrors('monthlyCap');

    $member = User::factory()->create();
    $this->actingAs($member);
    $this->get(route('settings.assistant'))->assertOk()->assertDontSee('Activer l\'assistant', false);
    Livewire::test(AssistantSettings::class)->set('enabled', true)->call('save')->assertForbidden();
});

test('les demandes suivent leur foyer et disparaissent avec lui', function () {
    AssistantUsage::create(['kind' => 'ideas', 'tokens_in' => 1, 'tokens_out' => 1, 'cost_estimate' => 0.001, 'succeeded' => true, 'created_at' => now()]);

    expect(in_array('assistant_usages', \App\Services\Households\HouseholdData::DELETION_ORDER, true))->toBeTrue()
        ->and(AssistantUsage::sole()->household_id)->not->toBeNull();
});

/* ================================================================ Outils */

test('le coût s\'affiche en euros lisibles', function () {
    expect(AssistantService::euros(1))->toBe('1 €')
        ->and(AssistantService::euros(0.5))->toBe('0,5 €')
        ->and(AssistantService::euros(0.0366))->toBe('0,037 €')
        ->and(AssistantService::euros(0))->toBe('0 €');
});

test('une réponse incomplète ou fantaisiste est ramenée à un brouillon sûr', function () {
    $assistant = app(RecipeAssistant::class);
    $lines = ['200 g de lardons', '20 cl de crème liquide'];
    $steps = ['Cuire.'];

    $result = $assistant->normalizeTransform([
        'name' => '<b>Version</b> '.str_repeat('x', 100),
        'lines' => [['index' => 1, 'action' => 'replace', 'replacement' => ''], ['index' => 2, 'action' => 'exploser'], 'n\'importe quoi'],
        'added' => ['', ['pas une ligne']],
        'steps' => [],
    ], $lines, $steps, 'Défaut');

    expect(mb_strlen($result['name']))->toBeLessThanOrEqual(60)
        ->and($result['name'])->not->toContain('<b>')
        ->and(array_column($result['lines'], 'action'))->toBe(['keep', 'keep'])   // remplacement vide, action inconnue
        ->and($result['added'])->toBe([])
        ->and($result['steps'])->toBe([['text' => 'Cuire.', 'changed' => false]])   // étapes d'origine gardées
        ->and($assistant->fitsVariant($result))->toBeFalse();

    Http::fake(['api.mistral.ai/*' => mistralReply('pas du JSON')]);
    expect(fn () => $assistant->ideas('courgette'))->toThrow(AssistantFailed::class, 'incomplète');
});
