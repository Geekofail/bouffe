<div class="card p-6 sm:p-8">
    <h1 class="mb-6 text-xl font-semibold text-stone-900">Nouveau mot de passe</h1>

    <form wire:submit="save" class="space-y-5" novalidate>
        <div>
            <label for="email" class="form-label">Adresse e-mail</label>
            <input wire:model="email" id="email" type="email" autocomplete="username" required
                   @class(['form-input', 'form-input-error' => $errors->has('email')])>
            @error('email') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="form-label">Nouveau mot de passe</label>
            <input wire:model="password" id="password" type="password" autocomplete="new-password" autofocus required
                   @class(['form-input', 'form-input-error' => $errors->has('password')])>
            @error('password') <p class="form-error">{{ $message }}</p> @else <p class="mt-1 text-xs text-stone-500">8 caractères au moins.</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="form-label">Le même, une seconde fois</label>
            <input wire:model="password_confirmation" id="password_confirmation" type="password" autocomplete="new-password" required class="form-input">
        </div>

        <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">Enregistrer</button>
    </form>

    <p class="mt-6 border-t border-stone-100 pt-4 text-sm text-stone-500">Les autres appareils connectés à ce compte seront déconnectés.</p>
</div>
