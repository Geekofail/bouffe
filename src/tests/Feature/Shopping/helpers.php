<?php

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Unit;
use App\Services\Shopping\ShoppingItemPresenter;
use App\Services\Shopping\ShoppingLine;

/** Recette de test : [[qty, code unité|null, nom ingrédient, facultatif]] */
function recipeWith(string $title, int $servings, array $lines): Recipe
{
    $recipe = Recipe::factory()->create(['title' => $title, 'servings' => $servings]);
    $units = Unit::pluck('id', 'code');

    foreach ($lines as $i => [$qty, $unit, $name, $optional]) {
        $recipe->ingredients()->create([
            'ingredient_id' => Ingredient::firstWhere('name', $name)->id,
            'quantity' => $qty,
            'unit_id' => $unit ? $units[$unit] : null,
            'is_optional' => $optional ?? false,
            'sort_order' => $i + 1,
        ]);
    }

    return $recipe;
}

function linesText($lines): array
{
    $presenter = app(ShoppingItemPresenter::class);

    return $lines->mapWithKeys(function (ShoppingLine $line) use ($presenter) {
        $item = new \App\Models\ShoppingListItem([
            'label' => $line->ingredient->name, 'quantity' => $line->quantity(), 'unit_id' => $line->unit()?->id,
            'extra_quantities' => $line->extraQuantities(), 'origin' => $line->isStaple ? 'staple' : 'generated',
        ]);
        $item->setRelation('ingredient', $line->ingredient);

        return [$line->ingredient->name => $presenter->text($item)];
    })->all();
}
