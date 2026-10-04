<?php

namespace App\Services\Security;

use App\Mail\Notice;
use App\Models\LoginEvent;
use App\Models\User;
use App\Support\DeviceLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Journal des connexions et appareils connectés (lot 25, 27.5).
 *
 *  - chaque connexion, échec, code refusé ou réinitialisation est noté (adresse IP, navigateur) ;
 *  - une connexion depuis un navigateur jamais vu sur ce compte envoie un e-mail d'alerte ;
 *  - les appareils connectés sont les sessions en base (SESSION_DRIVER=database), révocables.
 */
class LoginJournal
{
    public function record(User $user, string $type, ?Request $request = null): LoginEvent
    {
        $request ??= request();
        $agent = $request->userAgent() ? mb_substr($request->userAgent(), 0, 255) : null;

        return LoginEvent::create([
            'user_id' => $user->id,
            'type' => $type,
            'ip_address' => $request->ip(),
            'user_agent' => $agent,
            'device' => DeviceLabel::fingerprint($agent),
            'created_at' => now(),
        ]);
    }

    /** Connexion réussie : notée, et e-mail si l'appareil est nouveau pour ce compte. */
    public function loggedIn(User $user, ?Request $request = null): void
    {
        $request ??= request();
        $device = DeviceLabel::fingerprint($request->userAgent());
        $history = LoginEvent::query()->where('user_id', $user->id)->where('type', 'login');
        $known = (clone $history)->where('device', $device)->exists();
        $first = ! $history->exists();

        $this->record($user, 'login', $request);

        // Premier passage : pas d'alerte (c'est la création du compte ou la mise à jour vers le lot 25).
        if ($known || $first || ! config('bouffe.security.new_device_email')) {
            return;
        }

        try {
            Mail::to($user)->send(Notice::newDevice($user, $request->userAgent(), $request->ip()));
        } catch (\Throwable $e) {
            // Un serveur d'envoi en panne ne doit pas empêcher de se connecter.
            Log::warning('Alerte de nouvelle connexion non envoyée : '.$e->getMessage());
        }
    }

    /** @return \Illuminate\Support\Collection<int, LoginEvent> */
    public function recent(User $user, int $limit = 30)
    {
        return $user->loginEvents()->latest('created_at')->latest('id')->limit($limit)->get();
    }

    /** Efface les entrées plus anciennes que la durée de conservation. */
    public function purge(): int
    {
        $days = (int) config('bouffe.security.journal_days', 180);

        return $days > 0 ? LoginEvent::query()->where('created_at', '<', now()->subDays($days))->delete() : 0;
    }

    /* ================================================================ Appareils connectés */

    public function sessionsAvailable(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * Sessions ouvertes du compte, la session actuelle en premier.
     *
     * @return list<array{id: string, current: bool, label: string, icon: string, ip: string|null, last_active: \Illuminate\Support\Carbon}>
     */
    public function sessions(User $user): array
    {
        if (! $this->sessionsAvailable()) {
            return [];
        }

        $current = session()->getId();

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'current' => $row->id === $current,
                'label' => DeviceLabel::describe($row->user_agent),
                'icon' => DeviceLabel::icon($row->user_agent),
                'ip' => $row->ip_address,
                'last_active' => \Illuminate\Support\Carbon::createFromTimestamp($row->last_activity, config('app.timezone')),
            ])
            ->sortByDesc('current')
            ->values()
            ->all();
    }

    /**
     * Déconnecte un appareil (ou tous les autres si $sessionId est null).
     *
     * Le jeton « rester connecté » est renouvelé : sans cela, l'appareil retiré rouvrirait une
     * session tout seul. L'appareil actuel reçoit le nouveau jeton et reste connecté.
     */
    public function revoke(User $user, ?string $sessionId = null): int
    {
        if (! $this->sessionsAvailable()) {
            return 0;
        }

        $query = DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->where('id', '!=', session()->getId());
        $count = $sessionId ? $query->where('id', $sessionId)->delete() : $query->delete();

        if ($count > 0) {
            $this->cycleRememberToken($user);
            $this->record($user, 'revoked');
        }

        return $count;
    }

    /** Nouveau jeton « rester connecté » ; l'appareil actuel garde sa connexion. */
    public function cycleRememberToken(User $user): void
    {
        $user->setRememberToken(Str::random(60));
        $user->save();

        if (Auth::check() && Auth::id() === $user->id && request()->hasCookie(Auth::guard()->getRecallerName())) {
            Auth::guard()->login($user, true);
        }
    }
}
