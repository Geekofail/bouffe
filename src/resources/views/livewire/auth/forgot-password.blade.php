<div class="card p-6 sm:p-8">
    <h1 class="mb-2 text-xl font-semibold text-stone-900">Mot de passe oublié</h1>

    @if ($sent)
        <div class="flex gap-3 rounded-lg bg-emerald-50 p-4 text-sm text-emerald-800" role="status">
            <x-icon name="envelope" class="size-5 shrink-0" />
            <div class="space-y-2">
                <p>Si un compte existe pour <strong class="break-all">{{ $email }}</strong>, un e-mail vient de partir avec un lien valable {{ config('auth.passwords.users.expire') }} minutes.</p>
                <p>Rien reçu dans dix minutes ? Regardez dans les indésirables, ou demandez de l'aide à la personne qui vous a invité.</p>
            </div>
        </div>
    @else
        <p class="mb-6 text-sm text-stone-600">Indiquez l'adresse de votre compte : vous recevrez un lien pour choisir un nouveau mot de passe.</p>

        <form wire:submit="send" class="space-y-5" novalidate>
            <div>
                <label for="email" class="form-label">Adresse e-mail</label>
                <input wire:model="email" id="email" type="email" autocomplete="username" autofocus required
                       @class(['form-input', 'form-input-error' => $errors->has('email')])>
                @error('email') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="send">Envoyer le lien</span>
                <span wire:loading wire:target="send">Envoi…</span>
            </button>
        </form>
    @endif

    <p class="mt-6 border-t border-stone-100 pt-4 text-sm">
        <a href="{{ route('login') }}" class="font-medium text-brand-700 hover:underline">← Retour à la connexion</a>
    </p>
</div>
