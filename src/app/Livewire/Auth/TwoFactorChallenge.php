<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\Security\LoginJournal;
use App\Services\Security\TwoFactor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Deuxième étape de la connexion (lot 25, 27.4) : le code de l'application, ou un code de secours.
 * Le mot de passe a déjà été vérifié ; la personne n'est connectée qu'après ce code.
 */
#[Layout('layouts::guest')]
#[Title('Code de vérification')]
class TwoFactorChallenge extends Component
{
    public const MAX_ATTEMPTS = 5;

    public string $code = '';

    public bool $recovery = false;

    public bool $trust = false;

    public function mount(): void
    {
        if (! $this->pending()) {
            $this->redirectRoute('login', navigate: false);
        }
    }

    public function toggleRecovery(): void
    {
        $this->recovery = ! $this->recovery;
        $this->reset('code');
        $this->resetErrorBag();
    }

    public function verify(TwoFactor $twoFactor, LoginJournal $journal): void
    {
        $pending = $this->pending();
        $user = $pending ? User::find($pending['id']) : null;

        if (! $user || ! $user->hasTwoFactor()) {
            session()->forget('login.two_factor');
            $this->redirectRoute('login', navigate: false);

            return;
        }

        $key = '2fa|'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['code' => 'Trop d\'essais. Réessayez dans '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).']);
        }

        $this->validate(['code' => 'required|string|max:20'], [], ['code' => 'code']);

        $ok = $this->recovery ? $twoFactor->useRecoveryCode($user, $this->code) : $twoFactor->verifyCode($user, $this->code);

        if (! $ok) {
            RateLimiter::hit($key, 300);
            $journal->record($user, 'two_factor_failed');
            $this->reset('code');

            throw ValidationException::withMessages(['code' => $this->recovery ? 'Ce code de secours n\'est pas valable (ou a déjà servi).' : 'Code incorrect. Vérifiez l\'heure du téléphone et réessayez avec le code suivant.']);
        }

        RateLimiter::clear($key);
        session()->forget('login.two_factor');

        Auth::login($user, (bool) $pending['remember']);
        session()->regenerate();

        if ($this->recovery) {
            $journal->record($user, 'recovery_code');
        }

        $journal->loggedIn($user);

        if ($this->trust) {
            $twoFactor->trustDevice($user);
        }

        if ($this->recovery && $twoFactor->remainingRecoveryCodes($user) <= 2) {
            session()->flash('status', 'Il vous reste '.$twoFactor->remainingRecoveryCodes($user).' code(s) de secours : générez-en de nouveaux dans « Mon compte ».');
        }

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: false);
    }

    /** @return array{id: int, remember: bool, at: int}|null */
    private function pending(): ?array
    {
        $pending = session('login.two_factor');

        return is_array($pending) && now()->getTimestamp() - (int) ($pending['at'] ?? 0) < Login::CHALLENGE_TTL ? $pending : null;
    }

    public function render()
    {
        return view('livewire.auth.two-factor-challenge');
    }
}
