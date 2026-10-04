<?php

namespace App\Http\Controllers;

use App\Services\Recipes\RecipeInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Cible de partage (lot 38, 38.2) : sur Android, Bouffe installé apparaît dans le menu « Partager ».
 * Le navigateur ouvre /partager?title=…&text=…&url=… ; la recette arrive dans « À trier ».
 */
class ShareTargetController extends Controller
{
    public function __invoke(Request $request, RecipeInbox $inbox): RedirectResponse
    {
        if (! $request->user()->canEdit()) {
            return redirect()->route('recipes.inbox')->with('status', 'Votre compte est en consultation : la recette n\'a pas été gardée.');
        }

        $url = trim((string) $request->query('url', ''));
        $text = trim((string) $request->query('text', ''));
        $title = trim((string) $request->query('title', ''));

        try {
            $item = $url !== '' ? $inbox->receiveUrl($url, 'partage') : $inbox->receiveText($text !== '' ? $text : $title, 'partage', $title ?: null);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('recipes.inbox')->with('status', $e->getMessage());
        }

        return redirect()->route('recipes.inbox')->with('status', '« '.$item->label().' » est dans « À trier ».');
    }
}
