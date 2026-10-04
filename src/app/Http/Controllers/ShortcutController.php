<?php

namespace App\Http\Controllers;

use App\Services\Shortcuts\ShortcutActions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * Adresses appelées par les raccourcis (lot 38, 38.1 et 38.2), avec le jeton personnel (R39).
 *
 *   POST /api/raccourcis/courses     texte=« deux baguettes et du beurre »
 *   POST /api/raccourcis/stock       texte=« six œufs »
 *   GET  /api/raccourcis/menu        ?quand=demain (auto par défaut : la suite de la journée, puis demain)
 *   POST /api/raccourcis/a-trier     url=… ou texte=… ou photo (fichier)
 *
 * Les réponses sont du texte simple, en français, que le raccourci fait lire par Siri.
 */
class ShortcutController extends Controller
{
    public function __construct(private readonly ShortcutActions $actions) {}

    public function shopping(Request $request): Response
    {
        return $this->answer(fn () => $this->actions->addToShopping($this->text($request)));
    }

    public function stock(Request $request): Response
    {
        return $this->answer(fn () => $this->actions->addToStock($this->text($request)), editing: true);
    }

    public function menu(Request $request): Response
    {
        $when = (string) $request->query('quand', 'auto');

        return $this->answer(fn () => $this->actions->menu(in_array($when, ['auto', 'aujourdhui', 'demain'], true) ? $when : 'auto'));
    }

    public function inbox(Request $request): Response
    {
        $url = $request->input('url');

        return $this->answer(fn () => $this->actions->receive(is_string($url) ? $url : null, $this->text($request, false), $request->file('photo')), editing: true);
    }

    /** Le texte dicté : champ « texte » (ou « text »), en formulaire ou en JSON. */
    private function text(Request $request, bool $required = true): string
    {
        $text = $request->input('texte', $request->input('text', ''));
        $text = is_string($text) ? trim(mb_substr($text, 0, 2000)) : '';

        if ($required && $text === '') {
            throw new InvalidArgumentException('Rien reçu : le raccourci doit envoyer le champ « texte ».');
        }

        return $text;
    }

    private function answer(Closure $action, bool $editing = false): Response
    {
        if ($editing && ! auth()->user()->canEdit()) {
            return $this->say('Votre compte est en consultation : seules les courses et le menu sont possibles.', 403);
        }

        try {
            return $this->say($action());
        } catch (InvalidArgumentException $e) {
            return $this->say($e->getMessage(), 422);
        }
    }

    private function say(string $message, int $status = 200): Response
    {
        return response($message, $status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
