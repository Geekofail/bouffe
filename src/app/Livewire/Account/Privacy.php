<?php

namespace App\Livewire\Account;

use App\Models\User;
use App\Services\Receipts\OcrService;
use App\Support\Settings;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * « Vos données » (lot 25, 27.12) : ce que Bouffe garde, où, qui le voit, quels services extérieurs
 * sont appelés et comment tout exporter ou supprimer. Lisible sans compte, pour qu'une personne
 * invitée sache à quoi s'en tenir avant de rejoindre un foyer.
 *
 * Les réponses sont calculées d'après la configuration réelle (service de lecture des tickets,
 * serveur d'e-mail, durées de conservation) : la page ne promet rien que l'installation ne fait pas.
 */
#[Title('Vos données')]
class Privacy extends Component
{
    public function render(OcrService $ocr)
    {
        $reader = $ocr->reader();
        $mailer = (string) config('mail.default');

        $view = view('livewire.account.privacy', [
            'guest' => ! Auth::check(),
            'hosting' => (string) config('bouffe.hosting'),
            // Page publique : les prénoms ne s'affichent qu'une fois connecté.
            'admins' => Auth::check() ? User::query()->where('is_admin', true)->orderBy('id')->pluck('name') : collect(),
            'ocrLabel' => $reader?->configured() ? $reader->label() : null,
            'ocrProvider' => $reader?->configured() ? $reader->provider() : null,
            'receiptMonths' => Settings::int('receipts.keep_months', 12),
            'mailHost' => $mailer === 'log' || $mailer === 'array' ? null : (config("mail.mailers.{$mailer}.host") ?: $mailer),
            'journalDays' => (int) config('bouffe.security.journal_days', 180),
            'backupDays' => max(1, (int) config('bouffe.backups.auto_days', 7)) * max(1, (int) config('bouffe.backups.keep', 10)),
            'owner' => Auth::user()?->managesHousehold() ?? false,
        ]);

        return Auth::check() ? $view : $view->layout('layouts::guest', ['wide' => true]);
    }
}
