<div class="mx-auto max-w-xl">
    <x-page-header title="Relier deux foyers" />

    <div class="card space-y-4 p-5">
        @if (! $usable)
            <p class="flex gap-2 text-stone-700"><x-icon name="warning" class="size-5 shrink-0 text-amber-700" /> Ce lien n'est plus valable (déjà utilisé ou expiré). Demandez-en un nouveau.</p>
        @elseif ($from?->id === $household?->id)
            <p class="flex gap-2 text-stone-700"><x-icon name="info" class="size-5 shrink-0 text-stone-400" /> C'est le lien de votre propre foyer : envoyez-le à un responsable de l'autre foyer.</p>
        @else
            <p class="text-stone-700">
                <strong>{{ $from?->name }}</strong> propose de relier son foyer au vôtre, <strong>{{ $household?->name }}</strong>.
            </p>
            <ul class="space-y-1.5 text-sm text-stone-600">
                <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-emerald-700" /> Rien n'est partagé d'office : chaque foyer choisit ensuite ce qu'il ouvre (recettes, planning).</li>
                <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-emerald-700" /> Vous pourrez vous inviter à des repas, vous proposer des surplus, faire une liste de courses commune.</li>
                <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-emerald-700" /> Le lien se défait à tout moment, depuis la page Proches.</li>
            </ul>
            @error('link') <p class="form-error">{{ $message }}</p> @enderror
            @if ($owner)
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="accept" class="btn btn-primary"><x-icon name="link" class="size-4" /> Relier nos foyers</button>
                    <a href="{{ route('dashboard') }}" wire:navigate class="btn btn-ghost">Pas maintenant</a>
                </div>
            @else
                <p class="text-sm text-stone-500">Seul un responsable de « {{ $household?->name }} » peut accepter.</p>
            @endif
        @endif
    </div>
</div>
