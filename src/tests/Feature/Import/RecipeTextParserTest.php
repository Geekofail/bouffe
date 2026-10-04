<?php

use App\Services\Recipes\RecipeTextParser;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;

beforeEach(function () {
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class]);
    $this->parser = app(RecipeTextParser::class);
});

test('une recette collée avec ses sections est découpée', function () {
    $draft = $this->parser->parse(<<<'TEXT'
    Gratin dauphinois

    Pour 6 personnes
    Préparation : 20 min · Cuisson : 1 h 15

    Ingrédients
    - 1,5 kg de pommes de terre
    - 50 cl de crème liquide
    - 2 gousses d'ail
    - Poivre du moulin

    Préparation
    1. Éplucher les pommes de terre et les couper en rondelles.
    2. Frotter le plat avec l'ail.
    3. Enfourner 1 h 15 à 160 °C.
    TEXT);

    expect($draft['title'])->toBe('Gratin dauphinois')
        ->and($draft['servings'])->toBe(6)
        ->and($draft['prep_minutes'])->toBe(20)
        ->and($draft['cook_minutes'])->toBe(75)
        ->and($draft['ingredients'])->toHaveCount(4)
        ->and($draft['steps'])->toHaveCount(3)
        ->and($draft['steps'][0]['instruction'])->toBe('Éplucher les pommes de terre et les couper en rondelles.');

    expect($draft['ingredients'][0]['quantity'])->toBe(1.5);
    expect($draft['ingredients'][0]['ingredient']?->name)->toBe('Pomme de terre');
});

test('les sous-titres deviennent des groupes d\'ingrédients', function () {
    $draft = $this->parser->parse(<<<'TEXT'
    Tarte aux pommes

    Ingrédients
    Pour la pâte :
    250 g de farine
    125 g de beurre

    Pour la garniture :
    4 pommes
    2 œufs

    Préparation
    Mélanger la farine et le beurre.
    TEXT);

    expect($draft['ingredients'])->toHaveCount(4)
        ->and($draft['ingredients'][0]['group'])->toBe('Pour la pâte')
        ->and($draft['ingredients'][3]['group'])->toBe('Pour la garniture')
        ->and($draft['steps'])->toHaveCount(1);
});

test('sans titre de section les lignes sont réparties à l\'allure', function () {
    $draft = $this->parser->parse(<<<'TEXT'
    Omelette aux herbes

    4 œufs
    20 g de beurre
    Sel

    Battre les œufs avec le sel.
    Faire fondre le beurre puis verser les œufs.
    TEXT);

    expect($draft['title'])->toBe('Omelette aux herbes')
        ->and($draft['ingredients'])->toHaveCount(3)
        ->and($draft['steps'])->toHaveCount(2);
});

test('les temps et portions écrits autrement sont reconnus', function () {
    $draft = $this->parser->parse(<<<'TEXT'
    Soupe de courgettes
    4 parts
    Temps de préparation 10 minutes
    Temps de cuisson 25 minutes
    Repos : 1 h

    Ingrédients
    3 courgettes

    Préparation
    Cuire les courgettes.
    TEXT);

    expect($draft['servings'])->toBe(4)
        ->and($draft['prep_minutes'])->toBe(10)
        ->and($draft['cook_minutes'])->toBe(25)
        ->and($draft['rest_minutes'])->toBe(60);
});

test('un texte sans rien d\'exploitable reste une ébauche vide', function () {
    $draft = $this->parser->parse("   \n  \n");

    expect($draft['title'])->toBe('Recette collée')
        ->and($draft['ingredients'])->toBe([])
        ->and($draft['steps'])->toBe([])
        ->and($draft['servings'])->toBe((int) config('bouffe.default_servings', 2));
});

test('les conseils de fin ne deviennent pas des étapes perdues', function () {
    $draft = $this->parser->parse(<<<'TEXT'
    Riz au lait

    Ingrédients
    1 l de lait
    150 g de riz

    Préparation
    Cuire le riz dans le lait.

    Conseils
    Se déguste tiède.
    TEXT);

    expect($draft['ingredients'])->toHaveCount(2)
        ->and($draft['steps'])->toHaveCount(2);
});
