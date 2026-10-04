<?php

namespace App\Livewire\Households;

use App\Models\Invitation;
use App\Models\User;
use App\Services\Households\HouseholdManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Accepter une invitation (lot 24, 25.2).
 *
 *  - connecté : « Rejoindre le foyer » ;
 *  - adresse déjà connue : se connecter d'abord, puis revenir ici ;
 *  - sinon : prénom, adresse, mot de passe — le compte est créé et rejoint le foyer.
 */
#[Layout('layouts::guest')]
#[Title('Invitation')]
class AcceptInvitation extends Component
{
    #[Locked]
    public string $token = '';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) $this->invitation()?->email;
    }

    private function invitation(): ?Invitation
    {
        return app(HouseholdManager::class)->findInvitation($this->token);
    }

    /** Déjà connecté : rejoindre le foyer. */
    public function join(HouseholdManager $manager)
    {
        $invitation = $this->invitation();
        abort_unless($invitation && auth()->check(), 404);

        try {
            $household = $manager->accept($invitation, auth()->user());
        } catch (InvalidArgumentException $e) {
            $this->addError('invitation', $e->getMessage());

            return null;
        }

        session()->flash('status', "Bienvenue dans « {$household->name} ».");

        return $this->redirectRoute('dashboard');
    }

    /** Adresse connue : se connecter, puis revenir sur l'invitation. */
    public function login()
    {
        session()->put('url.intended', route('invitation.show', $this->token));

        return $this->redirectRoute('login');
    }

    /** Nouveau compte. */
    public function register(HouseholdManager $manager)
    {
        $invitation = $this->invitation();
        abort_unless($invitation, 404);

        $key = 'invitation:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('email', 'Trop de tentatives : réessayez dans quelques minutes.');

            return null;
        }

        RateLimiter::hit($key, 600);

        $this->email = $invitation->email ?? mb_strtolower(trim($this->email));

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'email.unique' => 'Un compte existe déjà avec cette adresse : connectez-vous pour rejoindre le foyer.',
        ], ['name' => 'prénom', 'email' => 'adresse e-mail', 'password' => 'mot de passe']);

        try {
            $user = DB::transaction(function () use ($invitation, $manager) {
                $user = User::create(['name' => trim($this->name), 'email' => $this->email, 'password' => $this->password]);
                $manager->accept($invitation, $user);

                return $user;
            });
        } catch (InvalidArgumentException $e) {
            $this->addError('invitation', $e->getMessage());

            return null;
        }

        Auth::login($user);
        session()->regenerate();
        session()->flash('status', "Bienvenue dans « {$invitation->household->name} ».");

        return $this->redirectRoute('dashboard');
    }

    public function render()
    {
        $invitation = $this->invitation();

        return view('livewire.households.accept-invitation', [
            'invitation' => $invitation,
            'usable' => $invitation?->isUsable() ?? false,
            'known' => $invitation?->email ? User::query()->where('email', $invitation->email)->exists() : false,
        ]);
    }
}
