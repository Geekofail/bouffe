<?php

namespace App\Livewire\Settings;

use App\Models\User;
use App\Services\Backup\BackupManager;
use App\Services\System\Health;
use App\Services\System\TaskRunner;
use App\Services\System\TaskToken;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Paramètres → Mise en ligne (lot 25, module 27), pour l'administrateur : adresse des tâches
 * planifiées, surveillance, sécurité et sauvegardes en ligne. Tout se règle dans .env ; cette
 * page dit ce qui est en place et ce qui manque.
 */
#[Title('Mise en ligne')]
class Online extends Component
{
    public bool $showToken = false;

    public function revealToken(): void
    {
        $this->showToken = true;
    }

    public function regenerateToken(TaskToken $tokens): void
    {
        $tokens->regenerate();
        $this->showToken = true;
        $this->dispatch('notify', message: 'Nouvelle adresse : reportez-la dans le service externe.', type: 'warning');
    }

    public function runNow(TaskRunner $runner): void
    {
        $result = $runner->run('commande');

        $this->dispatch('notify',
            message: ! $result['ran'] ? 'Un passage est déjà en cours.' : ($result['errors'] ? 'Tâches lancées, avec '.count($result['errors']).' erreur(s).' : 'Tâches lancées.'),
            type: $result['ran'] && ! $result['errors'] ? 'success' : 'warning');
    }

    public function render(TaskToken $tokens, TaskRunner $runner, Health $health, BackupManager $backups)
    {
        $url = $tokens->url();
        $last = $runner->lastRun();
        $mailer = (string) config('mail.default');
        $appUrl = (string) config('app.url');

        $withoutTwoFactor = User::query()
            ->whereNull('two_factor_confirmed_at')
            ->where(fn ($q) => $q->where('is_admin', true)->orWhereIn('id', DB::table('household_user')->where('role', 'owner')->select('user_id')))
            ->orderBy('name')->pluck('name');

        $lastEmail = Settings::get('backups.last_email');

        return view('livewire.settings.online', [
            'taskUrl' => $url,
            'maskedUrl' => preg_replace('#/taches/.+$#', '/taches/••••••••', $url),
            'last' => $last,
            'lastMinutes' => $last ? (int) floor($last['at']->diffInMinutes(now(), true)) : null,
            'maxDelay' => (int) config('bouffe.tasks.max_delay', 90),
            'healthUrl' => route('health'),
            'health' => $health->check(),
            'security' => [
                ['label' => 'Adresse du site', 'ok' => str_starts_with($appUrl, 'https://'), 'value' => $appUrl ?: '—', 'help' => 'APP_URL=https://… dans .env : les liens des e-mails en dépendent.'],
                ['label' => 'Environnement', 'ok' => app()->environment('production') && ! config('app.debug'), 'value' => app()->environment().(config('app.debug') ? ', débogage activé' : ''), 'help' => 'En ligne : APP_ENV=production et APP_DEBUG=false.'],
                ['label' => 'Double authentification', 'ok' => config('bouffe.security.two_factor_required') && $withoutTwoFactor->isEmpty(),
                    'value' => (config('bouffe.security.two_factor_required') ? 'Obligatoire' : 'Facultative').($withoutTwoFactor->isNotEmpty() ? ' — pas encore activée : '.$withoutTwoFactor->join(', ') : ''),
                    'help' => 'Obligatoire pour l\'administrateur et les responsables quand BOUFFE_2FA_REQUIRED=true (par défaut en production).'],
                ['label' => 'Politique de contenu (CSP)', 'ok' => config('bouffe.security.csp') === 'enforce', 'value' => ['enforce' => 'Appliquée', 'report' => 'Signalée seulement', 'off' => 'Désactivée'][config('bouffe.security.csp')] ?? (string) config('bouffe.security.csp'), 'help' => 'BOUFFE_CSP=enforce'],
                ['label' => 'Cookies sécurisés', 'ok' => (bool) config('session.secure'), 'value' => config('session.secure') ? 'Oui (https seulement)' : 'Non', 'help' => 'SESSION_SECURE_COOKIE=true une fois le site en https.'],
                ['label' => 'Envoi d\'e-mails', 'ok' => ! in_array($mailer, ['log', 'array'], true), 'value' => in_array($mailer, ['log', 'array'], true) ? 'Non configuré (MAIL_MAILER='.$mailer.')' : (config("mail.mailers.{$mailer}.host") ?: $mailer), 'help' => 'Sans lui : pas de mot de passe oublié, pas d\'alerte de connexion ni d\'e-mail de sauvegarde.'],
                ['label' => 'Alerte sur erreur grave', 'ok' => (bool) config('bouffe.security.error_email'), 'value' => config('bouffe.security.error_email') ? 'E-mail à l\'administrateur, 1 par heure au plus' : 'Désactivée', 'help' => 'BOUFFE_ERROR_EMAIL=true'],
            ],
            'backup' => [
                'latest' => $backups->latest(),
                'autoDays' => (int) config('bouffe.backups.auto_days'),
                'keep' => (int) config('bouffe.backups.keep'),
                'weekly' => (bool) config('bouffe.backups.weekly_email'),
                'weeklyDay' => Carbon::now()->startOfWeek(Carbon::SUNDAY)->addDays((int) config('bouffe.backups.weekly_email_day', 1))->locale('fr')->isoFormat('dddd'),
                'lastEmail' => is_array($lastEmail) && isset($lastEmail['at']) ? Carbon::parse($lastEmail['at']) : null,
            ],
            'phpBinary' => PHP_BINARY,
            'cronPath' => base_path('cron.php'),
        ]);
    }
}
