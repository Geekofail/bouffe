<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\Security\LoginJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Nouveau mot de passe depuis le lien reçu par e-mail (lot 25, 27.6).
 *
 * Après le changement, toutes les sessions du compte sont fermées et le jeton « rester connecté »
 * renouvelé : si le mot de passe avait fuité, les appareils de l'intrus sont déconnectés.
 * La double authentification, elle, reste exigée à la connexion suivante.
 */
#[Layout('layouts::guest')]
#[Title('Nouveau mot de passe')]
class ResetPassword extends Component
{
    #[Locked]
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function save(LoginJournal $journal): void
    {
        $this->validate([
            'email' => 'required|string|email',
            'password' => 'required|string|min:8|confirmed',
        ], [], ['email' => 'adresse e-mail', 'password' => 'mot de passe']);

        $status = Password::reset(
            ['email' => Str::lower(trim($this->email)), 'password' => $this->password, 'password_confirmation' => $this->password_confirmation, 'token' => $this->token],
            function (User $user, string $password) use ($journal) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                }

                $journal->record($user, 'password_reset');
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->reset('password', 'password_confirmation');

            throw ValidationException::withMessages(['email' => $status === Password::RESET_THROTTLED
                ? __($status)
                : 'Ce lien n\'est plus valable : il a expiré, a déjà servi, ou l\'adresse ne correspond pas. Demandez-en un nouveau.']);
        }

        session()->flash('status', 'Mot de passe changé. Connectez-vous avec le nouveau.');
        $this->redirectRoute('login', navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.reset-password');
    }
}
