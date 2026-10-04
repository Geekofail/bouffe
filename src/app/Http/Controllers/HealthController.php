<?php

namespace App\Http\Controllers;

use App\Services\System\Health;
use Illuminate\Http\JsonResponse;

/** /sante (lot 25, 27.11) : 200 si tout va bien, 503 sinon. */
class HealthController extends Controller
{
    public function __invoke(Health $health): JsonResponse
    {
        $state = $health->check();

        return response()->json($state, $state['status'] === 'ok' ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
