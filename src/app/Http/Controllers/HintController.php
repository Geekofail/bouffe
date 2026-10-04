<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * « Compris » sur une aide contextuelle (lot 30, 30.3) : elle ne s'affiche plus pour cette personne.
 */
class HintController extends Controller
{
    /** Aides connues : une clé inconnue est refusée (la préférence ne grossit pas sans fin). */
    public const KEYS = ['planning', 'stock', 'shopping', 'stock-review', 'prices'];

    public function __invoke(string $key): Response
    {
        abort_unless(in_array($key, self::KEYS, true), 404);

        $user = auth()->user();
        $seen = array_values(array_unique([...(array) $user->preference('hints_seen', []), $key]));
        $user->setPreference('hints_seen', $seen);

        return response()->noContent();
    }
}
