<?php

namespace App\Http\Controllers;

use App\Models\MealOccasion;
use App\Services\Receptions\ReceptionPlanner;
use App\Services\Receptions\Receptions;
use App\Services\RecipePhotoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Réceptions (module 21) : la carte de menu à imprimer (21.3) et la photo souvenir (21.4).
 */
class ReceptionController extends Controller
{
    public function menu(Request $request, MealOccasion $occasion, ReceptionPlanner $planner, Receptions $receptions): View
    {
        $occasion->load('guests', 'slot');

        return view('print.reception-menu', [
            'occasion' => $occasion,
            'name' => $receptions->name($occasion),
            'courses' => $planner->courses($occasion),
            'style' => in_array($request->query('style'), ['classique', 'moderne'], true) ? $request->query('style') : 'classique',
            'withGuests' => $request->boolean('invites'),
        ]);
    }

    public function photo(MealOccasion $occasion, string $size, RecipePhotoService $photos): BinaryFileResponse
    {
        abort_unless($occasion->memory_photo && in_array($size, RecipePhotoService::SIZES, true), 404);

        $path = $photos->path($occasion->memory_photo, $size);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
