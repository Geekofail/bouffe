<div class="card space-y-5 p-6 sm:p-8">
    @if (! $invitation || ! $usable)
        <h1 class="text-xl font-semibold text-stone-900">Invitation expirée</h1>
        <p class="text-sm text-stone-600">
            Ce lien n'est plus valable (déjà utilisé, ou plus de {{ \App\Models\Invitation::VALID_DAYS }} jours).
            Demandez un nouveau lien à la personne qui vous a invité.
        </p>
        <a href="{{ route('login') }}" class="btn btn-secondary w-full">Aller à la connexion</a>
    @else
        <div>
            <p class="text-sm text-stone-500">{{ $invitation->inviter?->name ?? 'Quelqu\'un' }} vous invite à rejoindre</p>
            <h1 class="text-xl font-semibold text-stone-900">{{ $invitation->household->name }}</h1>
            <p class="mt-1 text-sm text-stone-600">Rôle : {{ $invitation->role->label() }} — {{ $invitation->role->help() }}</p>
        </div>

        @error('invitation') <p class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ $message }}</p> @enderror

        @auth
            <p class="text-sm text-stone-600">Connecté en tant que <strong>{{ auth()->user()->name }}</strong> ({{ auth()->user()->email }}).</p>
            <button type="button" wire:click="join" class="btn btn-primary w-full">Rejoindre le foyer</button>
        @else
            @if ($known)
                <p class="text-sm text-stone-600">Vous avez déjà un compte Bouffe avec {{ $invitation->email }} : connectez-vous, vous reviendrez ici pour rejoindre le foyer.</p>
                <button type="button" wire:click="login" class="btn btn-primary w-full">Se connecter</button>
            @else
                <form wire:submit="register" class="space-y-4" novalidate>
                    <x-field label="Prénom" for="inv-name" error="name">
                        <input id="inv-name" type="text" wire:model="name" autocomplete="given-name" required @class(['form-input', 'form-input-error' => $errors->has('name')])>
                    </x-field>
                    <x-field label="Adresse e-mail" for="inv-email" error="email">
                        <input id="inv-email" type="email" wire:model="email" autocomplete="username" required @readonly($invitation->email) @class(['form-input', 'bg-stone-100' => $invitation->email, 'form-input-error' => $errors->has('email')])>
                    </x-field>
                    <x-field label="Mot de passe" for="inv-password" error="password" help="8 caractères au moins.">
                        <input id="inv-password" type="password" wire:model="password" autocomplete="new-password" required @class(['form-input', 'form-input-error' => $errors->has('password')])>
                    </x-field>
                    <x-field label="Mot de passe (confirmation)" for="inv-password2">
                        <input id="inv-password2" type="password" wire:model="password_confirmation" autocomplete="new-password" required class="form-input">
                    </x-field>
                    <button type="submit" class="btn btn-primary w-full">Créer mon compte et rejoindre</button>
                </form>
                <p class="text-center text-sm text-stone-500">Déjà un compte ? <button type="button" wire:click="login" class="text-brand-700 underline">Se connecter</button></p>
            @endif
        @endauth
        <p class="text-xs text-stone-500">Lien valable jusqu'au {{ $invitation->expires_at->locale('fr')->isoFormat('D MMMM à HH:mm') }}, une seule fois.</p>
    @endif
</div>
