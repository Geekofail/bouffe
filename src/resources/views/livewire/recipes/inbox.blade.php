<div class="max-w-3xl">
    <a href="{{ route('recipes.index') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Recettes
    </a>
    <x-page-header title="À trier" subtitle="Les recettes reçues en passant : à relire quand vous avez le temps." />

    {{-- ============================================================ Ajouter ici --}}
    @if ($canEdit)
        <section class="card mb-6 space-y-3 p-4 sm:p-5">
            <form wire:submit="add" class="space-y-2">
                <x-field label="Une adresse, ou le texte d'une recette" for="inbox-entry" error="entry">
                    <textarea id="inbox-entry" wire:model="entry" rows="2" class="form-input" placeholder="https://… ou « Tarte aux pommes : 4 pommes, 1 pâte… »"></textarea>
                </x-field>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="add"><x-icon name="plus" class="size-4" /> Garder pour plus tard</button>
                    <label class="btn btn-secondary cursor-pointer">
                        <x-icon name="camera" class="size-4" /> Photo d'une page
                        <input type="file" wire:model="photo" accept="image/*,application/pdf" class="sr-only" data-inbox-photo>
                    </label>
                    <span wire:loading wire:target="photo,addPhoto" class="text-sm text-stone-500">Envoi…</span>
                </div>
                @error('photo') <p class="form-error">{{ $message }}</p> @enderror
            </form>
            <p class="text-xs text-stone-500">
                Plus rapide depuis le téléphone : <a href="{{ route('account.shortcuts') }}" wire:navigate class="font-medium text-brand-700 hover:underline">le raccourci « Envoyer à Bouffe »</a>
                dans le menu Partager de Safari (sur Android, Bouffe y est déjà une fois installé).
            </p>
        </section>
    @endif

    {{-- ============================================================ Les recettes reçues --}}
    @if ($items->isEmpty())
        <div class="card p-6">
            <x-empty-state icon="recipes" title="Rien à trier">
                Les recettes envoyées depuis Safari, un raccourci ou ce formulaire arrivent ici.
            </x-empty-state>
        </div>
    @else
        <ul class="space-y-3" data-inbox>
            @foreach ($items as $item)
                <li wire:key="inbox-{{ $item->id }}" class="card flex flex-wrap items-start gap-3 p-4">
                    @if ($item->image_url)
                        {{-- L'image du site n'est pas chargée (politique de contenu) : une vignette neutre. --}}
                        <span class="flex size-14 shrink-0 items-center justify-center rounded-xl bg-stone-100 text-stone-400"><x-icon name="photo" class="size-6" /></span>
                    @else
                        <span class="flex size-14 shrink-0 items-center justify-center rounded-xl bg-stone-100 text-stone-400">
                            <x-icon :name="match ($item->kind) { 'photo' => 'camera', 'text' => 'note', default => 'link' }" class="size-6" />
                        </span>
                    @endif

                    <div class="min-w-0 flex-1 basis-48">
                        <p class="font-medium text-stone-900">{{ $item->label() }}</p>
                        <p class="text-xs text-stone-500">
                            {{ match ($item->via) { 'raccourci' => 'Par un raccourci', 'partage' => 'Partagée', default => 'Ajoutée ici' } }}
                            @if ($item->user) par {{ $item->user->name }} @endif
                            · {{ $item->created_at->locale('fr')->diffForHumans() }}
                            @if ($item->url) · <a href="{{ $item->url }}" target="_blank" rel="noopener noreferrer" class="underline hover:text-stone-700">{{ parse_url($item->url, PHP_URL_HOST) }}</a> @endif
                        </p>
                        @if ($item->status === 'failed')
                            <p class="mt-1 text-sm text-amber-800">{{ $item->error }}</p>
                        @elseif ($item->status === 'pending')
                            <p class="mt-1 text-sm text-stone-500">{{ $item->kind === 'photo' ? 'Photo en attente de lecture (quelques minutes).' : 'En attente de lecture.' }}</p>
                        @elseif ($item->kind === 'text')
                            <p class="mt-1 line-clamp-2 text-sm text-stone-600">{{ $item->text }}</p>
                        @endif
                    </div>

                    @if ($canEdit)
                        <div class="flex w-full flex-wrap justify-end gap-2 sm:w-auto">
                            @if ($item->status === 'ready')
                                <button type="button" wire:click="keep({{ $item->id }})" class="btn btn-primary min-h-11"><x-icon name="check" class="size-4" /> Relire et garder</button>
                            @else
                                <button type="button" wire:click="retry({{ $item->id }})" wire:loading.attr="disabled" wire:target="retry({{ $item->id }})" class="btn btn-secondary min-h-11">
                                    <x-icon name="rotate" class="size-4" /> {{ $item->status === 'pending' ? 'Lire maintenant' : 'Réessayer' }}
                                </button>
                                @if ($item->kind === 'url')
                                    <a href="{{ route('recipes.import', ['onglet' => 'texte']) }}" wire:navigate class="btn btn-ghost min-h-11">Coller le texte</a>
                                @endif
                            @endif
                            <button type="button" wire:click="discard({{ $item->id }})" class="btn btn-ghost min-h-11 text-stone-600" title="Jeter"><x-icon name="delete" class="size-4" /><span class="sr-only sm:not-sr-only">Jeter</span></button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
        <p class="mt-3 text-xs text-stone-500">Une recette gardée entre dans le carnet avec l'étiquette « à tester », jusqu'à ce qu'elle soit cuisinée.</p>
    @endif
</div>
