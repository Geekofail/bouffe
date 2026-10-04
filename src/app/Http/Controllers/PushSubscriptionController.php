<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\Notifications\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Abonnement d'un téléphone ou d'un navigateur aux notifications (19.2).
 *
 * Le navigateur demande la clé publique de Bouffe, s'abonne auprès de son service de
 * notification, puis envoie ici l'adresse et les clés de l'abonnement.
 */
class PushSubscriptionController extends Controller
{
    public function key(WebPush $push): JsonResponse
    {
        if (! $push->isSupported()) {
            return response()->json(['supported' => false, 'message' => 'Le serveur ne peut pas chiffrer les notifications (extension PHP openssl).'], 503);
        }

        try {
            return response()->json(['supported' => true, 'publicKey' => $push->publicKey()]);
        } catch (\RuntimeException $e) {
            return response()->json(['supported' => false, 'message' => $e->getMessage()], 503);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'device' => ['nullable', 'string', 'max:120'],
        ]);

        if (strlen(WebPush::unb64($data['keys']['p256dh'])) !== 65 || strlen(WebPush::unb64($data['keys']['auth'])) !== 16) {
            return response()->json(['message' => 'Clés d\'abonnement invalides.'], 422);
        }

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashOf($data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'device' => $data['device'] ?? null,
                'failures' => 0,
            ],
        );

        return response()->json(['subscribed' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint');

        PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashOf($endpoint))
            ->delete();

        return response()->json(['subscribed' => false]);
    }
}
