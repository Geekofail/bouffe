<?php

namespace App\Http\Controllers;

use App\Services\IngredientLineFormatter;
use App\Services\Planning\Appetites;
use App\Services\QuantityScaler;
use App\Services\RecipePhotoService;
use App\Services\Recipes\RecipeShares;
use App\Support\CurrentHousehold;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recette partagée par lien (lot 31, 31.4) : page publique, lecture seule, imprimable.
 * Ni notes de cuisine, ni prix, ni nom du foyer ; les moteurs de recherche sont priés de passer.
 */
class SharedRecipeController extends Controller
{
    public function show(Request $request, string $token, RecipeShares $shares): Response
    {
        $link = $shares->find($token);

        if (! $link) {
            return response()->view('share.expired', [], 404)->header('X-Robots-Tag', 'noindex, nofollow');
        }

        $shares->recordView($link);

        $html = CurrentHousehold::run((int) $link->recipe->household_id, function () use ($request, $link, $token) {
            $recipe = $link->recipe->load(['steps', 'tags']);
            $servings = (float) str_replace(',', '.', (string) $request->query('portions')) > 0
                ? Appetites::clamp($request->query('portions'))
                : (float) $recipe->servings;
            $scaler = app(QuantityScaler::class);
            $formatter = app(IngredientLineFormatter::class);

            // Sous-recettes (13.8) dépliées, comme sur la fiche.
            $groups = app(\App\Services\Recipes\SubRecipes::class)->lines($recipe)->values()
                ->map(fn ($line) => [
                    'group' => $line->via_recipe ? 'Pour : '.$line->via_recipe : (string) $line->group_name,
                    'parts' => $formatter->format($scaler->scale($line->quantity, $recipe->servings, $servings), $line->unit, $line->ingredient),
                    'preparation' => $line->preparation,
                    'optional' => (bool) $line->is_optional,
                ])
                ->groupBy('group');

            return view('share.recipe', [
                'recipe' => $recipe,
                'servings' => $servings,
                'groups' => $groups,
                'token' => $token,
                'expiresAt' => $link->expires_at,
            ])->render();
        });

        return response($html)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'private, no-store');
    }

    public function photo(string $token, string $size, RecipeShares $shares, RecipePhotoService $photos): Response
    {
        $link = $shares->find($token);
        abort_unless($link && $link->recipe->photo_path && in_array($size, RecipePhotoService::SIZES, true), 404);

        $path = $photos->path($link->recipe->photo_path, $size);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
