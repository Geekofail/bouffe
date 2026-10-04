<?php

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Tag;

/**
 * Recette de test : ingrédients par nom (créés au besoin), « ?nom » = facultatif.
 *
 * @param  list<string>  $ingredients
 * @param  list<string>  $tags
 */
function dish(string $title, array $ingredients, array $tags = [], int $servings = 2): Recipe
{
    $recipe = Recipe::factory()->create(['title' => $title, 'servings' => $servings]);

    foreach ($ingredients as $i => $name) {
        $optional = str_starts_with($name, '?');
        $name = ltrim($name, '?');
        $ingredient = Ingredient::firstWhere('name', $name) ?? Ingredient::factory()->create(['name' => $name]);

        $recipe->ingredients()->create(['ingredient_id' => $ingredient->id, 'quantity' => 100, 'is_optional' => $optional, 'sort_order' => $i + 1]);
    }

    $recipe->tags()->sync(collect($tags)->map(fn (string $t) => (Tag::firstWhere('name', $t) ?? Tag::factory()->create(['name' => $t]))->id));

    return $recipe;
}

function ingredientNamed(string $name): Ingredient
{
    return Ingredient::firstWhere('name', $name) ?? Ingredient::factory()->create(['name' => $name]);
}
