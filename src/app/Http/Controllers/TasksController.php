<?php

namespace App\Http\Controllers;

use App\Services\System\TaskRunner;
use App\Services\System\TaskToken;
use Illuminate\Http\JsonResponse;

/**
 * Adresse des tâches planifiées (lot 25, 27.3) : /taches/{jeton}, appelée toutes les 5 minutes par
 * un service externe (cron-job.org…). Jeton faux : « page introuvable », comme n'importe quelle adresse.
 */
class TasksController extends Controller
{
    public function __invoke(string $token, TaskToken $tokens, TaskRunner $runner): JsonResponse
    {
        abort_unless($tokens->matches($token), 404);

        $result = $runner->run('externe');

        return response()->json([
            'ok' => $result['ran'] && $result['errors'] === [],
            'deja_en_cours' => ! $result['ran'],
            'duree' => $result['seconds'],
            'erreurs' => count($result['errors']),
        ], $result['errors'] === [] ? 200 : 500);
    }
}
