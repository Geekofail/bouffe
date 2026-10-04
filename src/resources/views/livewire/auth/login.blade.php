<div class="card p-6 sm:p-8">
    <h1 class="mb-6 text-xl font-semibold text-stone-900">Connexion</h1>

    @if (session('status'))
        <p class="mb-5 flex gap-2 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800" role="status">
            <x-icon name="check" class="size-5 shrink-0" /> {{ session('status') }}
        </p>
    @endif

    <form wire:submit="login" class="space-y-5" novalidate>
        <div>
            <label for="email" class="form-label">Adresse e-mail</label>
            <input wire:model="email" id="email" type="email" autocomplete="username" autofocus required
                   @class(['form-input', 'form-input-error' => $errors->has('email')])>
            @error('email') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <div class="flex items-baseline justify-between gap-2">
                <label for="password" class="form-label">Mot de passe</label>
                <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand-700 hover:underline">Mot de passe oublié ?</a>
            </div>
            <input wire:model="password" id="password" type="password" autocomplete="current-password" required
                   @class(['form-input', 'form-input-error' => $errors->has('password')])>
            @error('password') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-stone-600">
            <input wire:model="remember" type="checkbox" class="form-checkbox">
            Rester connecté sur cet appareil
        </label>

        <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Se connecter</span>
            <span wire:loading wire:target="login">Connexion…</span>
        </button>
    </form>
</div>
