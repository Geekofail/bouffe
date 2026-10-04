<?php

namespace App\Livewire\Settings;

use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\WebPush;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Paramètres → Notifications (lot 20 : 19.2, 19.3, 19.4).
 *
 * Chacun choisit ce qu'il reçoit sur son téléphone et s'il veut le récapitulatif du dimanche ;
 * les réglages communs (heures, jour du récapitulatif) sont réservés aux comptes « modification ».
 */
#[Title('Notifications')]
class Notifications extends Component
{
    /** @var array<string, bool> */
    public array $types = [];

    public bool $recap = false;

    /** « Ne pas déranger » (lot 30, 30.6), propre à chacun. */
    public bool $quiet = true;

    public int $quietFrom = 22;

    public int $quietUntil = 7;

    public int $dailyHour = 18;

    /** Le rendez-vous du soir (lot 37, 37.1), propre à chacun. */
    public bool $evening = true;

    public string $eveningAt = '20:30';

    public bool $recapEnabled = false;

    public int $recapDay = 0;

    public int $recapHour = 18;

    public function mount(): void
    {
        $user = auth()->user();

        foreach (NotificationDispatcher::TYPES as $type => $definition) {
            $this->types[$type] = NotificationDispatcher::wants($user, $type);
        }

        $this->recap = NotificationDispatcher::wantsRecap($user);
        ['on' => $this->quiet, 'from' => $this->quietFrom, 'until' => $this->quietUntil] = NotificationDispatcher::quietHours($user);
        ['on' => $this->evening, 'time' => $this->eveningAt] = \App\Services\Planning\EveningRendezvous::settings($user);
        $this->dailyHour = Settings::int('notifications.daily_hour', 18);
        $this->recapEnabled = Settings::bool('notifications.recap_enabled', false);
        $this->recapDay = Settings::int('notifications.recap_day', 0);
        $this->recapHour = Settings::int('notifications.recap_hour', 18);
    }

    public function updatedTypes(): void
    {
        foreach (NotificationDispatcher::TYPES as $type => $definition) {
            auth()->user()->setPreference('notify.'.$type, (bool) ($this->types[$type] ?? false));
        }

        $this->dispatch('notify', message: 'Choix enregistrés.');
    }

    public function saveQuiet(): void
    {
        $this->validate([
            'quietFrom' => ['required', 'integer', 'between:0,23'],
            'quietUntil' => ['required', 'integer', 'between:0,23'],
        ]);

        $user = auth()->user();
        $user->setPreference('notify.quiet', $this->quiet);
        $user->setPreference('notify.quiet_from', $this->quietFrom);
        $user->setPreference('notify.quiet_until', $this->quietUntil);

        $this->dispatch('notify', message: $this->quiet
            ? "Rien sur votre téléphone entre {$this->quietFrom} h et {$this->quietUntil} h : ce qui tombe pendant attend le matin."
            : 'Les notifications peuvent arriver à toute heure.');
    }

    public function saveEvening(): void
    {
        $this->validate(['eveningAt' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/']], [], ['eveningAt' => 'heure']);

        $user = auth()->user();
        $user->setPreference('notify.evening', $this->evening);
        $user->setPreference('notify.evening_at', $this->eveningAt);

        $this->dispatch('notify', message: $this->evening
            ? 'Rendez-vous du soir à '.str_replace(':', ' h ', $this->eveningAt).', s\'il y a quelque chose à faire.'
            : 'Rendez-vous du soir désactivé : les rappels arrivent un par un.');
    }

    public function updatedRecap(): void
    {
        auth()->user()->setPreference('notify.recap', $this->recap);
        $this->dispatch('notify', message: $this->recap ? 'Vous recevrez le récapitulatif à '.auth()->user()->email.'.' : 'Récapitulatif désactivé.');
    }

    public function saveShared(): void
    {
        abort_unless(auth()->user()->canEdit(), 403);

        $this->validate([
            'dailyHour' => ['required', 'integer', 'between:6,22'],
            'recapDay' => ['required', 'integer', 'between:0,6'],
            'recapHour' => ['required', 'integer', 'between:6,22'],
        ]);

        Settings::set('notifications.daily_hour', $this->dailyHour);
        Settings::set('notifications.recap_enabled', $this->recapEnabled);
        Settings::set('notifications.recap_day', $this->recapDay);
        Settings::set('notifications.recap_hour', $this->recapHour);

        $this->dispatch('notify', message: 'Réglages communs enregistrés.');
    }

    private function eveningInQuietHours(): bool
    {
        $hour = (int) substr($this->eveningAt, 0, 2);

        return $this->quietFrom > $this->quietUntil
            ? ($hour >= $this->quietFrom || $hour < $this->quietUntil)
            : ($hour >= $this->quietFrom && $hour < $this->quietUntil);
    }

    /** Message d'essai sur tous les appareils abonnés de la personne. */
    public function test(NotificationDispatcher $dispatcher): void
    {
        $result = $dispatcher->test(auth()->user());
        unset($this->devices);

        $this->dispatch('notify', type: $result['failed'] > 0 ? 'warning' : 'success', message: match (true) {
            $result['sent'] + $result['failed'] === 0 => 'Aucun appareil abonné : activez d\'abord les notifications sur ce téléphone.',
            $result['failed'] === 0 => 'Message envoyé à '.$result['sent'].' appareil'.($result['sent'] > 1 ? 's' : '').'.',
            default => $result['sent'].' envoi réussi, '.$result['failed'].' en échec (le service du navigateur a refusé).',
        });
    }

    public function testRecap(NotificationDispatcher $dispatcher): void
    {
        abort_unless(auth()->user()->canEdit(), 403);

        try {
            $dispatcher->sendRecap(now(), auth()->user());
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: 'Envoi impossible : '.$e->getMessage());

            return;
        }

        $this->dispatch('notify', message: config('mail.default') === 'log'
            ? 'Récapitulatif écrit dans storage/logs (MAIL_MAILER=log : aucun e-mail n\'est réellement envoyé).'
            : 'Récapitulatif envoyé à '.auth()->user()->email.'.');
    }

    public function forget(int $subscriptionId): void
    {
        auth()->user()->pushSubscriptions()->whereKey($subscriptionId)->delete();
        unset($this->devices);
    }

    #[On('push-changed')]
    public function refreshDevices(): void
    {
        unset($this->devices);
    }

    #[Computed]
    public function devices()
    {
        return auth()->user()->pushSubscriptions()->latest('updated_at')->get();
    }

    public function render(WebPush $push)
    {
        $lastRun = Settings::get('notifications.last_run');
        $at = is_array($lastRun) && isset($lastRun['at']) ? Carbon::parse($lastRun['at']) : null;

        return view('livewire.settings.notifications', [
            'serverReady' => $push->isSupported(),
            'lastRun' => $at,
            'lastRunLate' => ! $at || $at->lt(now()->subMinutes(30)),
            'mailer' => (string) config('mail.default'),
            'days' => ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'],
            'canEdit' => auth()->user()->canEdit(),
            'eveningQuiet' => $this->quiet && $this->eveningInQuietHours(),
            'windowsCommand' => 'php artisan bouffe:reminders --tache-windows',
        ]);
    }
}
