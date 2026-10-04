<?php

namespace App\Http\Controllers;

use App\Services\Linked\CalendarFeed;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Agenda du planning (C4) : /agenda/{jeton}.ics, lu par l'application Agenda du téléphone.
 * Sans session : le jeton identifie la personne ; un jeton inconnu répond « introuvable ».
 */
class CalendarController extends Controller
{
    public function __invoke(Request $request, string $token, CalendarFeed $feed): Response
    {
        $user = $feed->findUser($token) ?? abort(404);
        $household = $feed->allowedHousehold($user, $request->integer('foyer') ?: null) ?? abort(404);

        return response($feed->render($household), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="bouffe-'.$household->id.'.ics"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }
}
