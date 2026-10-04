<?php

use App\Models\Recipe;
use App\Models\Tag;
use App\Services\Recipes\RecipeImporter;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class]);
    $this->importer = app(RecipeImporter::class);
});

function jsonLdPage(array $recipe): string
{
    return '<!doctype html><html><head><title>x</title><script type="application/ld+json">'
        .json_encode($recipe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        .'</script></head><body>Contenu</body></html>';
}

function schemaRecipe(array $overrides = []): array
{
    return array_merge([
        '@context' => 'https://schema.org',
        '@type' => 'Recipe',
        'name' => 'Gratin dauphinois',
        'description' => 'Un grand classique.',
        'recipeYield' => '6 personnes',
        'prepTime' => 'PT20M',
        'cookTime' => 'PT1H15M',
        'totalTime' => 'PT1H45M',
        'recipeCategory' => 'Plat principal',
        'keywords' => 'gratin, pommes de terre',
        'image' => '/images/gratin.jpg',
        'recipeIngredient' => [
            '1,5 kg de pommes de terre',
            '50 cl de crème liquide',
            '2 gousses d\'ail',
            'Poivre du moulin',
        ],
        'recipeInstructions' => [
            ['@type' => 'HowToStep', 'text' => 'Éplucher les pommes de terre.'],
            ['@type' => 'HowToStep', 'text' => 'Frotter le plat avec l\'ail.'],
            ['@type' => 'HowToStep', 'text' => 'Enfourner 1 h 15 à 160 °C.'],
        ],
    ], $overrides);
}

/* ================================================================ 13.1 — Import URL */

test('une page avec du JSON-LD donne une ébauche complète', function () {
    Http::fake(['exemple.test/*' => Http::response(jsonLdPage(schemaRecipe()))]);

    $draft = $this->importer->fromUrl('https://exemple.test/gratin');

    expect($draft['title'])->toBe('Gratin dauphinois')
        ->and($draft['description'])->toBe('Un grand classique.')
        ->and($draft['servings'])->toBe(6)
        ->and($draft['prep_minutes'])->toBe(20)
        ->and($draft['cook_minutes'])->toBe(75)
        ->and($draft['rest_minutes'])->toBe(10)
        ->and($draft['source'])->toBe('https://exemple.test/gratin')
        ->and($draft['image'])->toBe('https://exemple.test/images/gratin.jpg')
        ->and($draft['steps'])->toHaveCount(3)
        ->and($draft['steps'][0]['instruction'])->toBe('Éplucher les pommes de terre.')
        ->and($draft['existing'])->toBeNull();

    expect($draft['ingredients'])->toHaveCount(4);
    expect($draft['ingredients'][0]['quantity'])->toBe(1.5);
    expect($draft['ingredients'][0]['unit']?->code)->toBe('kg');
    expect($draft['ingredients'][0]['ingredient']?->name)->toBe('Pomme de terre');
    expect($draft['ingredients'][3]['ingredient']?->name)->toBe('Poivre');
});

test('les catégories du site retrouvent les catégories existantes', function () {
    $tag = Tag::create(['name' => 'Plat principal']);
    Http::fake(['*' => Http::response(jsonLdPage(schemaRecipe()))]);

    $draft = $this->importer->fromUrl('https://exemple.test/gratin');

    expect($draft['tagIds'])->toBe([$tag->id])
        ->and($draft['tagNames'])->toContain('gratin');
});

test('la recette est trouvée dans un @graph', function () {
    Http::fake(['*' => Http::response(jsonLdPage([
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'WebPage', 'name' => 'Page'],
            schemaRecipe(['name' => 'Tarte aux pommes']),
        ],
    ]))]);

    expect($this->importer->fromUrl('https://exemple.test/tarte')['title'])->toBe('Tarte aux pommes');
});

test('les sections de la recette deviennent des groupes d\'étapes', function () {
    Http::fake(['*' => Http::response(jsonLdPage(schemaRecipe(['recipeInstructions' => [
        ['@type' => 'HowToSection', 'name' => 'La pâte', 'itemListElement' => [
            ['@type' => 'HowToStep', 'text' => 'Mélanger la farine et le beurre.'],
        ]],
        ['@type' => 'HowToSection', 'name' => 'La garniture', 'itemListElement' => [
            ['@type' => 'HowToStep', 'text' => 'Éplucher les pommes.'],
            ['@type' => 'HowToStep', 'text' => 'Disposer en rosace.'],
        ]],
    ]])))]);

    $steps = $this->importer->fromUrl('https://exemple.test/tarte')['steps'];

    expect($steps)->toHaveCount(3)
        ->and($steps[0]['group'])->toBe('La pâte')
        ->and($steps[2]['group'])->toBe('La garniture');
});

test('les instructions en un seul bloc de texte sont découpées', function () {
    Http::fake(['*' => Http::response(jsonLdPage(schemaRecipe([
        'recipeInstructions' => "Éplucher les légumes.\nCuire 20 minutes.\nServir chaud.",
    ])))]);

    expect($this->importer->fromUrl('https://exemple.test/x')['steps'])->toHaveCount(3);
});

test('les microdonnées servent de repli sans JSON-LD', function () {
    $html = <<<'HTML'
    <div itemscope itemtype="https://schema.org/Recipe">
        <h1 itemprop="name">Soupe à l'oignon</h1>
        <meta itemprop="prepTime" content="PT15M">
        <meta itemprop="cookTime" content="PT40M">
        <span itemprop="recipeYield">4 parts</span>
        <li itemprop="recipeIngredient">6 oignons</li>
        <li itemprop="recipeIngredient">50 g de beurre</li>
        <p itemprop="recipeInstructions">Émincer les oignons.</p>
    </div>
    HTML;

    Http::fake(['*' => Http::response($html)]);
    $draft = $this->importer->fromUrl('https://exemple.test/soupe');

    expect($draft['title'])->toBe('Soupe à l\'oignon')
        ->and($draft['servings'])->toBe(4)
        ->and($draft['prep_minutes'])->toBe(15)
        ->and($draft['cook_minutes'])->toBe(40)
        ->and($draft['ingredients'])->toHaveCount(2)
        ->and($draft['steps'])->toHaveCount(1);
});

test('une page sans recette est refusée avec un message clair', function () {
    Http::fake(['*' => Http::response('<html><body>Bonjour</body></html>')]);

    expect(fn () => $this->importer->fromUrl('https://exemple.test/rien'))
        ->toThrow(InvalidArgumentException::class, 'Aucune recette reconnue');
});

test('une erreur du site est signalée', function () {
    Http::fake(['*' => Http::response('', 404)]);

    expect(fn () => $this->importer->fromUrl('https://exemple.test/perdu'))
        ->toThrow(InvalidArgumentException::class, '404');
});

test('les adresses locales et privées sont refusées', function (string $url) {
    Http::fake();

    expect(fn () => $this->importer->fromUrl($url))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    'http://localhost/recette',
    'http://127.0.0.1:8000/recette',
    'http://192.168.1.10/recette',
    'file:///etc/passwd',
    'pas une adresse',
]);

test('une recette déjà importée depuis la même adresse est signalée', function () {
    $recipe = Recipe::create(['title' => 'Gratin dauphinois', 'servings' => 4, 'source' => 'https://exemple.test/gratin']);
    Http::fake(['*' => Http::response(jsonLdPage(schemaRecipe()))]);

    expect($this->importer->fromUrl('https://exemple.test/gratin')['existing']?->id)->toBe($recipe->id);
});

test('une recette de même titre est signalée comme doublon', function () {
    $recipe = Recipe::create(['title' => 'Gratin Dauphinois', 'servings' => 4]);
    Http::fake(['*' => Http::response(jsonLdPage(schemaRecipe()))]);

    expect($this->importer->fromUrl('https://exemple.test/autre')['existing']?->id)->toBe($recipe->id);
});

test('les durées sont lues en ISO 8601 comme en texte', function (mixed $value, ?int $minutes) {
    expect($this->importer->minutes($value))->toBe($minutes);
})->with([
    ['PT1H15M', 75],
    ['PT45M', 45],
    ['PT2H', 120],
    ['P1DT2H', 1560],
    ['15 min', 15],
    ['1 h 30', 90],
    ['', null],
    [null, null],
]);
