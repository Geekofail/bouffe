<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\Security\LoginJournal;
use App\Services\Security\TwoFactor;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts::guest')]
#[Title('Connexion')]
class Login extends Component
{
    /** Nombre d'essais autorisés avant blocage temporaire (par adresse e-mail et IP). */
    public const MAX_ATTEMPTS = 5;

    /** Essais par adresse IP, toutes adresses e-mail confondues (lot 25, 27.5). */
    public const MAX_ATTEMPTS_PER_IP = 20;

    /** Durée pendant laquelle l'étape « code de vérification » attend (secondes). */
    public const CHALLENGE_TTL = 600;

    #[Validate('required|string|email', as: 'adresse e-mail')]
    public string $email = '';

    #[Validate('required|string', as: 'mot de passe')]
    public string $password = '';

    public bool $remember = true;

    public function login(TwoFactor $twoFactor, LoginJournal $journal): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        $user = User::query()->where('email', $this->email)->first();

        if (! $user || ! Auth::validate(['email' => $this->email, 'password' => $this->password])) {
            RateLimiter::hit($this->throttleKey(), 60 * max(1, (int) config('bouffe.security.lockout_minutes', 1)));
            RateLimiter::hit($this->ipKey(), 600);

            if ($user) {
                $journal->record($user, 'failed');
            }

            $this->reset('password');

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        // Double authentification (27.4) : le mot de passe est bon, reste le code — sauf sur un appareil de confiance.
        if ($user->hasTwoFactor() && ! $twoFactor->isTrusted($user, request())) {
            session()->put('login.two_factor', ['id' => $user->id, 'remember' => $this->remember, 'at' => now()->getTimestamp()]);
            $this->redirectRoute('two-factor.challenge', navigate: false);

            return;
        }

        Auth::login($user, $this->remember);
        session()->regenerate();
        $journal->loggedIn($user);

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: false);
    }

    protected function ensureIsNotRateLimited(): void
    {
        $key = RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS) ? $this->throttleKey()
            : (RateLimiter::tooManyAttempts($this->ipKey(), self::MAX_ATTEMPTS_PER_IP) ? $this->ipKey() : null);

        if (! $key) {
            return;
        }

        event(new Lockout(request()));

        // Noté une seule fois par blocage dans le journal du compte visé.
        if ($key === $this->throttleKey() && ($user = User::query()->where('email', $this->email)->first())
            && RateLimiter::attempts($this->throttleKey()) === self::MAX_ATTEMPTS) {
            RateLimiter::hit($this->throttleKey());
            app(LoginJournal::class)->record($user, 'locked');
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    protected function ipKey(): string
    {
        return 'login-ip|'.request()->ip();
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
