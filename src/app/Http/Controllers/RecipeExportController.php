<?php

namespace App\Http\Controllers;

use App\Services\Recipes\RecipeArchive;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Téléchargement du carnet de recettes au format JSON (lot 13.10).
 */
class RecipeExportController extends Controller
{
    public function __invoke(RecipeArchive $archive): StreamedResponse
    {
        $json = json_encode($archive->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return response()->streamDownload(fn () => print ($json), $archive->filename(), [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }
}
