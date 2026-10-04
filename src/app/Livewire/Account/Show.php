<?php

namespace App\Livewire\Account;

use App\Services\Security\AccountDeletion;
use App\Services\Security\LoginJournal;
use App\Services\Security\TwoFactor;
use App\Support\Totp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Mon compte (lot 25) : profil, mot de passe, double authentification (27.4), appareils connectés
 * et journal des connexions (27.5), suppression du compte (27.12).
 */
#[Title('Mon compte')]
class Show extends Component
{
    public string $name = '';

    public string $email = '';

    public string $profilePassword = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public string $setupCode = '';

    /** Codes de secours affichés une seule fois, juste après leur création. @var list<string> */
    public array $recoveryCodes = [];

    /** Mot de passe demandé pour les actions sensibles (couper la double authentification, nouveaux codes, supprimer le compte). */
    public string $confirmPassword = '';

    public string $deletePassword = '';

    public bool $confirmDeletion = false;

    public function mount(): void
    {
        $this->name = (string) Auth::user()->name;
        $this->email = (string) Auth::user()->email;
    }

    /* ================================================================ Profil */

    public function saveProfile(): void
    {
        $user = Auth::user();
        $emailChanged = mb_strtolower(trim($this->email)) !== mb_strtolower($user->email);

        $this->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'string', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'profilePassword' => $emailChanged ? 'required|string' : 'nullable',
        ], [], ['name' => 'prénom', 'email' => 'adresse e-mail', 'profilePassword' => 'mot de passe actuel']);

        if ($emailChanged && ! Hash::check($this->profilePassword, $user->password)) {
            throw ValidationException::withMessages(['profilePassword' => 'Mot de passe incorrect.']);
        }

        $user->forceFill(['name' => trim($this->name), 'email' => mb_strtolower(trim($this->email))])->save();
        $this->reset('profilePassword');
        $this->dispatch('notify', message: 'Profil enregistré.');
    }

    /* ================================================================ Mot de passe */

    public function changePassword(LoginJournal $journal): void
    {
        $user = Auth::user();

        $this->validate([
            'currentPassword' => 'required|string',
            'newPassword' => 'required|string|min:8|confirmed',
        ], [], ['currentPassword' => 'mot de passe actuel', 'newPassword' => 'nouveau mot de passe']);

        if (! Hash::check($this->currentPassword, $user->password)) {
            throw ValidationException::withMessages(['currentPassword' => 'Mot de passe incorrect.']);
        }

        $user->forceFill(['password' => $this->newPassword])->save();
        $journal->record($user, 'password_changed');

        // Les autres appareils doivent se reconnecter avec le nouveau mot de passe.
        $others = $journal->revoke($user);
        $journal->cycleRememberToken($user);

        $this->reset('currentPassword', 'newPassword', 'newPassword_confirmation');
        $this->dispatch('notify', message: 'Mot de passe changé.'.($others ? " {$others} autre(s) appareil(s) déconnecté(s)." : ''));
    }

    /* ================================================================ Double authentification */

    public function startTwoFactor(TwoFactor $twoFactor): void
    {
        abort_if(Auth::user()->hasTwoFactor(), 403);

        $twoFactor->begin(Auth::user());
        $this->reset('setupCode', 'recoveryCodes');
    }

    public function confirmTwoFactor(TwoFactor $twoFactor, LoginJournal $journal): void
    {
        $this->validate(['setupCode' => 'required|string|max:10'], [], ['setupCode' => 'code']);

        $codes = $twoFactor->confirm(Auth::user(), $this->setupCode);

        if ($codes === null) {
            $this->reset('setupCode');

            throw ValidationException::withMessages(['setupCode' => 'Code incorrect. Vérifiez que l\'heure du téléphone est automatique, puis saisissez le code suivant.']);
        }

        $journal->record(Auth::user(), 'two_factor_enabled');
        $this->recoveryCodes = $codes;
        $this->reset('setupCode');
        $this->dispatch('notify', message: 'Double authentification activée.');
    }

    public function cancelTwoFactorSetup(TwoFactor $twoFactor): void
    {
        if (! Auth::user()->hasTwoFactor()) {
            $twoFactor->disable(Auth::user());
        }
    }

    public function disableTwoFactor(TwoFactor $twoFactor, LoginJournal $journal): void
    {
        abort_if($twoFactor->required(Auth::user()), 403);
        $this->checkConfirmPassword();

        $twoFactor->disable(Auth::user());
        $twoFactor->forgetDevice();
        $journal->record(Auth::user(), 'two_factor_disabled');
        $this->reset('confirmPassword', 'recoveryCodes');
        $this->dispatch('notify', message: 'Double authentification désactivée.');
    }

    public function regenerateRecoveryCodes(TwoFactor $twoFactor): void
    {
        abort_unless(Auth::user()->hasTwoFactor(), 403);
        $this->checkConfirmPassword();

        $this->recoveryCodes = $twoFactor->regenerateRecoveryCodes(Auth::user());
        $this->reset('confirmPassword');
    }

    public function hideRecoveryCodes(): void
    {
        $this->reset('recoveryCodes');
    }

    public function forgetThisDevice(TwoFactor $twoFactor): void
    {
        $twoFactor->forgetDevice();
        $this->dispatch('notify', message: 'Le code sera demandé à la prochaine connexion sur cet appareil.');
    }

    /* ================================================================ Entre foyers (lot 26) */

    /** 26.6 : montrer ses contraintes alimentaires aux foyers reliés qui nous reçoivent. */
    public function toggleShareRestrictions(): void
    {
        $user = Auth::user();
        $user->forceFill(['share_restrictions' => ! $user->share_restrictions])->save();
        $this->dispatch('notify', message: $user->share_restrictions
            ? 'Vos contraintes seront montrées aux foyers reliés qui vous invitent.'
            : 'Vos contraintes ne sont plus montrées aux autres foyers.');
    }

    public bool $showCalendar = false;

    /** C4 : nouvelle adresse d'agenda (l'ancienne cesse de fonctionner). */
    public function regenerateCalendar(\App\Services\Linked\CalendarFeed $calendar): void
    {
        $calendar->regenerate(Auth::user());
        $this->showCalendar = true;
        $this->dispatch('notify', message: 'Nouvelle adresse d\'agenda : remplacez l\'ancienne sur vos appareils.');
    }

    /* ================================================================ Appareils */

    public function revokeSession(string $id, LoginJournal $journal): void
    {
        $journal->revoke(Auth::user(), $id);
        unset($this->sessions);
        $this->dispatch('notify', message: 'Appareil déconnecté.');
    }

    public function revokeOtherSessions(LoginJournal $journal): void
    {
        $count = $journal->revoke(Auth::user());
        unset($this->sessions);
        $this->dispatch('notify', message: $count ? "{$count} appareil(s) déconnecté(s)." : 'Aucun autre appareil connecté.');
    }

    /* ================================================================ Suppression */

    public function deleteAccount(AccountDeletion $deletion)
    {
        $this->checkConfirmPassword('deletePassword');

        if (! $this->confirmDeletion) {
            throw ValidationException::withMessages(['confirmDeletion' => 'Cochez la case pour confirmer.']);
        }

        $user = Auth::user();

        if ($blockers = $deletion->blockers($user)) {
            throw ValidationException::withMessages(['confirmDeletion' => $blockers[0]]);
        }

        // Déconnexion d'abord : elle enregistre le compte (jeton « rester connecté »), ce qui le recréerait après coup.
        Auth::guard('web')->logout();
        $deletion->delete($user);

        session()->invalidate();
        session()->regenerateToken();
        session()->flash('status', 'Votre compte a été supprimé.');

        return $this->redirectRoute('login', navigate: false);
    }

    private function checkConfirmPassword(string $field = 'confirmPassword'): void
    {
        $this->resetErrorBag();

        if (! Hash::check($this->{$field}, Auth::user()->password)) {
            $this->reset($field);

            throw ValidationException::withMessages([$field => 'Mot de passe incorrect.']);
        }
    }

    /* ================================================================ Données de l'écran */

    #[Computed]
    public function sessions(): array
    {
        return app(LoginJournal::class)->sessions(Auth::user());
    }

    public function render(TwoFactor $twoFactor, LoginJournal $journal, AccountDeletion $deletion)
    {
        $user = Auth::user();
        $pending = $user->two_factor_secret && ! $user->two_factor_confirmed_at;

        return view('livewire.account.show', [
            'user' => $user,
            'required' => $twoFactor->required($user),
            'pending' => $pending,
            'secret' => $pending ? Totp::format($user->two_factor_secret) : null,
            'otpUri' => $pending ? Totp::uri($user->two_factor_secret, $user->email, config('app.name', 'Bouffe')) : null,
            'remaining' => $twoFactor->remainingRecoveryCodes($user),
            'trusted' => $user->hasTwoFactor() && $twoFactor->isTrusted($user, request()),
            'sessionsAvailable' => $journal->sessionsAvailable(),
            'events' => $journal->recent($user),
            'blockers' => $deletion->blockers($user),
            'calendarUrl' => $this->showCalendar ? app(\App\Services\Linked\CalendarFeed::class)->url($user) : null,
        ]);
    }
}
