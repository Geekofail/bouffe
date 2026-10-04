<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Mot de passe oublié (lot 25, 27.6) : lien de réinitialisation par e-mail.
 *
 * La réponse est la même que l'adresse ait un compte ou non : la page ne doit pas permettre de
 * deviner qui utilise Bouffe. Trois demandes par quart d'heure au plus depuis une même adresse IP.
 */
#[Layout('layouts::guest')]
#[Title('Mot de passe oublié')]
class ForgotPassword extends Component
{
    public const MAX_REQUESTS = 3;

    public string $email = '';

    public bool $sent = false;

    public function send(): void
    {
        $this->validate(['email' => 'required|string|email|max:191'], [], ['email' => 'adresse e-mail']);

        $key = 'password-reset|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_REQUESTS)) {
            throw ValidationException::withMessages(['email' => 'Trop de demandes. Réessayez dans '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).']);
        }

        RateLimiter::hit($key, 900);

        try {
            Password::sendResetLink(['email' => Str::lower(trim($this->email))]);
        } catch (\Throwable $e) {
            // Serveur d'envoi mal réglé : noté pour l'administrateur, sans rien révéler ici.
            Log::error('Lien de réinitialisation non envoyé : '.$e->getMessage());
        }

        $this->sent = true;
    }

    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}
