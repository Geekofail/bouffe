<div>
    <x-modal :show="$show" title="Partager par un lien" close="close">
        <div class="space-y-4 text-sm text-stone-700">
            <p>
                Pour quelqu'un qui n'a pas Bouffe : une page en lecture seule, imprimable, avec la photo, les ingrédients
                et les étapes. <strong>Sans</strong> vos notes de cuisine, les prix ni le nom du foyer.
                Le lien reste valable {{ \App\Services\Recipes\RecipeShares::DAYS }} jours, et vous pouvez le révoquer à tout moment.
            </p>

            @if ($newUrl)
                <div class="space-y-2 rounded-lg bg-herb-50 p-3 ring-1 ring-herb-100" x-data="{ copied: false }">
                    <label for="share-url" class="block font-medium text-herb-900">Lien créé : copiez-le maintenant, il ne sera plus affiché.</label>
                    <div class="flex gap-2">
                        <input id="share-url" type="text" readonly value="{{ $newUrl }}" class="form-input min-w-0 flex-1 font-mono text-xs" x-ref="url" x-on:focus="$el.select()">
                        <button type="button" class="btn btn-primary shrink-0"
                                x-on:click="navigator.clipboard?.writeText(@js($newUrl)); $refs.url.select(); copied = true">
                            <span x-text="copied ? 'Copié ✓' : 'Copier'">Copier</span>
                        </button>
                    </div>
                </div>
            @endif

            @error('share') <p class="form-error">{{ $message }}</p> @enderror

            @if ($links->isNotEmpty())
                <div>
                    <p class="form-label">Liens actifs</p>
                    <ul class="divide-y divide-stone-100">
                        @foreach ($links as $link)
                            <li wire:key="share-link-{{ $link->id }}" class="flex flex-wrap items-center gap-2 py-2">
                                <span class="min-w-0 flex-1">
                                    Créé le {{ $link->created_at->locale('fr')->isoFormat('D MMM') }}{{ $link->author ? ' par '.$link->author->name : '' }}
                                    · jusqu'au {{ $link->expires_at->locale('fr')->isoFormat('D MMM YYYY') }}
                                    · {{ $link->views }} ouverture{{ $link->views > 1 ? 's' : '' }}
                                </span>
                                <button type="button" wire:click="revoke({{ $link->id }})" wire:confirm="Révoquer ce lien ? Il ne fonctionnera plus." class="btn btn-ghost py-1 text-red-700 hover:bg-red-50">Révoquer</button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <x-slot:footer>
            <button type="button" wire:click="close" class="btn btn-ghost">Fermer</button>
            <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="link" class="size-4" /> Créer un lien</button>
        </x-slot:footer>
    </x-modal>
</div>
