<?php

namespace App\Http\Controllers;

use App\Models\KitchenTimer;
use App\Services\Kitchen\KitchenTimers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Minuteurs partagés (lot 41, 41.1) : lus et lancés par les pages ouvertes, en JSON.
 *
 *   GET  /minuteurs                     les minuteurs du foyer et l'heure du serveur
 *   POST /minuteurs                     lancer (label, seconds, source)
 *   POST /minuteurs/{timer}/arreter     arrêter, ou « OK » une fois fini
 *   POST /minuteurs/{timer}/sonne       une page visible l'a fait sonner (pas de notification)
 *
 * Le foyer actif filtre les minuteurs (R29) : celui d'un autre foyer donne une erreur 404.
 */
class KitchenTimerController extends Controller
{
    public function __construct(private readonly KitchenTimers $timers) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->timers->payload($request->user()))->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'seconds' => ['required', 'integer', 'min:1', 'max:'.KitchenTimers::MAX_SECONDS],
            'source' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $this->timers->start((string) ($data['label'] ?? ''), (int) $data['seconds'], $data['source'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->timers->payload($request->user()), 201);
    }

    public function stop(Request $request, KitchenTimer $timer): JsonResponse
    {
        $this->timers->stop($timer);

        return response()->json($this->timers->payload($request->user()));
    }

    public function rang(Request $request, KitchenTimer $timer): JsonResponse
    {
        $this->timers->rang($timer);

        return response()->json(['ok' => true]);
    }
}
