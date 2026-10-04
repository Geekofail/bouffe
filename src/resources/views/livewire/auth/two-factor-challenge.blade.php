<div class="card p-6 sm:p-8">
    <h1 class="mb-2 text-xl font-semibold text-stone-900">Code de vérification</h1>
    <p class="mb-6 text-sm text-stone-600">
        @if ($recovery)
            Saisissez l'un de vos codes de secours (chacun ne sert qu'une fois).
        @else
            Ouvrez votre application d'authentification (Google Authenticator, Aegis…) et saisissez le code à 6 chiffres affiché pour Bouffe.
        @endif
    </p>

    <form wire:submit="verify" class="space-y-5" novalidate>
        <div>
            <label for="code" class="form-label">{{ $recovery ? 'Code de secours' : 'Code à 6 chiffres' }}</label>
            <input wire:model="code" id="code" type="text" autofocus required
                   @if ($recovery) autocomplete="off" placeholder="abcde-fghij" @else inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" placeholder="123456" @endif
                   @class(['form-input text-center text-lg tracking-widest tabular-nums', 'form-input-error' => $errors->has('code')])>
            @error('code') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-start gap-2 text-sm text-stone-600">
            <input wire:model="trust" type="checkbox" class="form-checkbox mt-0.5">
            <span>Ne plus demander de code sur cet appareil pendant 30 jours</span>
        </label>

        <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="verify">Vérifier</span>
            <span wire:loading wire:target="verify">Vérification…</span>
        </button>
    </form>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-2 border-t border-stone-100 pt-4 text-sm">
        <button type="button" wire:click="toggleRecovery" class="font-medium text-brand-700 hover:underline">
            {{ $recovery ? 'Utiliser le code de l\'application' : 'Téléphone perdu ? Code de secours' }}
        </button>
        <a href="{{ route('login') }}" class="text-stone-500 hover:underline">Annuler</a>
    </div>
</div>
