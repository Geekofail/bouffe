<?php

namespace App\Services\Undo;

use App\Models\UndoToken;
use App\Services\Activity\ActivityLog;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Annuler (30.1, règle R32).
 *
 * Un geste qui retire, vide, remplace ou déplace est lancé par run() : on lui dit quelles lignes il
 * va toucher (« les repas de cette semaine »), il est photographié avant et après, et un jeton est
 * rendu. Pendant 10 secondes, l'écran propose « Annuler » ; undo() remet alors **exactement** l'état
 * d'avant — lignes liées comprises — si personne n'a touché à ces lignes entre-temps. Sinon,
 * l'annulation est refusée et le dit.
 *
 *   $token = $undo->run('planning.clear', 'Semaine vidée', function (UndoRecorder $r) use ($ids) {
 *       $r->track('planned_meals', $ids);
 *       … le geste …
 *   });
 */
class UndoManager
{
    /** Le bouton reste 10 secondes ; le serveur accepte un peu plus (réseau lent). */
    public const SECONDS = 10;

    public const GRACE_SECONDS = 120;

    public function __construct(private readonly Snapshotter $snapshots) {}

    /**
     * Fait le geste et le rend annulable.
     *
     * @param  Closure(UndoRecorder): mixed  $work
     * @return array{token: string, result: mixed}
     */
    public function run(string $action, string $label, Closure $work): array
    {
        return DB::transaction(function () use ($action, $label, $work) {
            $recorder = new UndoRecorder($this->snapshots);
            $result = $work($recorder);
            $payload = $recorder->seal();

            // Les jetons périmés d'hier sont oubliés au passage.
            UndoToken::query()->where('expires_at', '<', now()->subDay())->delete();

            $token = UndoToken::create([
                'user_id' => auth()->id(),
                'token' => Str::random(32),
                'action' => $action,
                'label' => mb_substr($label, 0, 150),
                'payload' => $payload,
                'expires_at' => now()->addSeconds(self::GRACE_SECONDS),
            ]);

            return ['token' => $token->token, 'result' => $result];
        });
    }

    /**
     * Annule le geste. Renvoie son libellé.
     *
     * @throws UndoRefused
     */
    public function undo(string $token): UndoToken
    {
        return DB::transaction(function () use ($token) {
            $record = UndoToken::query()->where('token', $token)->lockForUpdate()->first();

            if (! $record || $record->user_id !== auth()->id()) {
                throw new UndoRefused('Cette action ne peut plus être annulée.');
            }

            if ($record->used_at) {
                throw new UndoRefused('Cette action a déjà été annulée.');
            }

            if ($record->expires_at->isPast()) {
                throw new UndoRefused('Trop tard pour annuler : le délai est passé.');
            }

            $payload = $record->payload;
            $current = $this->snapshots->capture($payload['scopes']);

            if (! $this->snapshots->same($current, $payload['after'])) {
                throw new UndoRefused('Impossible d\'annuler : ces éléments ont été modifiés entre-temps.');
            }

            $this->snapshots->restore($payload['before'], $current);
            \App\Models\HouseholdIngredientSetting::flush();
            $record->update(['used_at' => now()]);

            app(ActivityLog::class)->record('undo', 'a annulé : '.mb_strtolower(mb_substr($record->label, 0, 1)).mb_substr($record->label, 1));

            return $record;
        });
    }
}
