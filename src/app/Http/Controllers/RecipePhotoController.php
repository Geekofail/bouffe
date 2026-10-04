<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Services\RecipePhotoService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RecipePhotoController extends Controller
{
    public function __invoke(Recipe $recipe, string $size, RecipePhotoService $photos): BinaryFileResponse
    {
        abort_unless($recipe->photo_path && in_array($size, RecipePhotoService::SIZES, true), 404);

        $path = $photos->path($recipe->photo_path, $size);

        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/jpeg',
            // L'URL contient ?v=<date de modification> : on peut mettre en cache longtemps.
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    /** Photo d'étape ou « notre version » (31.2) : lisible avec la recette (la nôtre, ou celle d'un proche). */
    public function extra(Recipe $recipe, int $photo, string $size, RecipePhotoService $photos): BinaryFileResponse
    {
        $row = \App\Models\RecipePhoto::query()->where('recipe_id', $recipe->id)->find($photo);
        abort_unless($row && in_array($size, RecipePhotoService::SIZES, true), 404);

        $path = $photos->path($row->path, $size);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
