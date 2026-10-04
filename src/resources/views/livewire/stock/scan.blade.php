<div class="mx-auto max-w-2xl">
    <a href="{{ route('stock.index') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Stock
    </a>

    <x-page-header title="Scanner un produit" subtitle="La caméra lit le code-barres ; le produit est reconnu une fois, puis connu pour toujours." />

    {{-- ============================================================ Caméra (16.1) --}}
    <div x-data="barcodeScanner()" x-cloak class="card mb-6 overflow-hidden">
        <div class="flex flex-wrap items-center gap-3 border-b border-stone-100 px-4 py-3">
            <h2 class="font-display flex-1 font-semibold text-stone-900">Caméra</h2>

            <template x-if="! running">
                <button type="button" x-on:click="start()" class="btn btn-primary" x-bind:disabled="! supported">
                    <x-icon name="photo" class="size-4" /> Activer la caméra
                </button>
            </template>
            <template x-if="running">
                <button type="button" x-on:click="stop()" class="btn btn-secondary">Arrêter</button>
            </template>
        </div>

        <div class="relative bg-stone-900" x-show="running">
            <video x-ref="video" class="aspect-video w-full object-cover" muted playsinline></video>
            <div class="pointer-events-none absolute inset-x-8 top-1/2 h-0.5 -translate-y-1/2 bg-brand-500/80"></div>
        </div>

        <p x-show="message" x-text="message" class="px-4 py-3 text-sm text-amber-800"></p>

        <div x-show="! running && ! message" class="px-4 py-3 text-sm text-stone-500">
            <p x-show="supported">Visez le code-barres : la lecture se fait sur le téléphone, aucune image n'est envoyée.</p>
            <p x-show="! supported">
                La caméra demande une connexion sécurisée (https://) ou l'adresse localhost.
                Sur le Wi-Fi de la maison en http://, saisissez le code à la main juste en dessous — c'est exactement la même chose.
                <a href="{{ route('settings.diagnostic') }}" wire:navigate class="font-medium text-brand-700">Voir le diagnostic</a>
            </p>
        </div>
    </div>

    {{-- ============================================================ Saisie du code --}}
    <form wire:submit="lookup" class="mb-6 flex gap-2">
        <input type="text" inputmode="numeric" wire:model="barcode" placeholder="Code-barres (ex. 3560070462216)"
               @class(['form-input font-mono', 'form-input-error' => $errors->has('barcode')]) aria-label="Code-barres" autocomplete="off">
        <button type="submit" class="btn btn-primary shrink-0">
            <x-icon name="search" class="size-4" wire:loading.class="animate-spin" wire:target="lookup" /> Chercher
        </button>
    </form>
    @error('barcode') <p class="form-error -mt-4 mb-4">{{ $message }}</p> @enderror

    {{-- ============================================================ Résultat --}}
    @if ($state !== 'unknown')
        <div class="card p-5">
            @if ($state === 'known')
                <p class="mb-4 flex items-start gap-2 rounded-lg bg-herb-50 px-3 py-2 text-sm text-herb-900">
                    <x-icon name="success" class="size-5 shrink-0" />
                    <span>Produit déjà connu de la maison. Vérifiez la quantité et ajoutez-le.</span>
                </p>
            @elseif ($state === 'found')
                <p class="mb-4 flex items-start gap-2 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900">
                    <x-icon name="info" class="size-5 shrink-0" />
                    <span>Trouvé dans la base Open Food Facts. Dites une seule fois à quel ingrédient il correspond : les prochains scans ne poseront plus la question.</span>
                </p>
            @else
                <p class="mb-4 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    <x-icon name="warning" class="size-5 shrink-0" />
                    <span>
                        @if ($offline)
                            Produit inconnu ici et base extérieure injoignable (pas de réseau). Nommez-le : il sera reconnu la prochaine fois.
                        @else
                            Ce code-barres n'est connu nulle part. Nommez le produit : il sera reconnu la prochaine fois.
                        @endif
                    </span>
                </p>
            @endif

            {{-- Lot 40 (40.4, R44) : allergènes, d'après Open Food Facts. --}}
            @if ($info = $this->allergens)
                <div @class(['mb-4 rounded-lg px-3 py-2 text-sm ring-1',
                             'bg-red-50 text-red-900 ring-red-200' => collect($info['problems'])->contains('level', 'danger'),
                             'bg-amber-50 text-amber-900 ring-amber-200' => $info['problems'] !== [] && ! collect($info['problems'])->contains('level', 'danger'),
                             'bg-stone-50 text-stone-700 ring-stone-200' => $info['problems'] === []]) data-product-allergens>
                    <p><span class="font-medium">Allergènes :</span> {{ $info['summary'] ?? 'inconnus' }}
                        <span class="text-xs opacity-80">(d'après Open Food Facts, indicatif : lisez l'étiquette)</span></p>
                    @foreach ($info['problems'] as $problem)
                        <p class="mt-1 flex items-start gap-1 font-medium"><x-icon name="warning" class="mt-0.5 size-4 shrink-0" /> {{ $problem['message'] }}</p>
                    @endforeach
                    @if ($info['canCheck'])
                        <button type="button" wire:click="checkAllergens" class="mt-1 min-h-10 text-sm font-medium underline">Vérifier sur Open Food Facts</button>
                    @endif
                </div>
            @endif

            <form wire:submit="store" class="space-y-4">
                <x-field label="Produit" for="scan-label" error="label">
                    <input id="scan-label" type="text" wire:model="label" placeholder="ex. Lait demi-écrémé UHT"
                           @class(['form-input', 'form-input-error' => $errors->has('label')])>
                </x-field>

                <x-field label="Ingrédient de la maison" for="scan-ingredient" error="ingredientId"
                         help="C'est ce qui permet de compter ce produit dans les recettes et la liste de courses.">
                    <select id="scan-ingredient" wire:model.live="ingredientId" class="form-input">
                        <option value="">— aucun (produit suivi seul) —</option>
                        @foreach ($this->ingredients as $ingredient)
                            <option value="{{ $ingredient->id }}">{{ $ingredient->name }}</option>
                        @endforeach
                    </select>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Quantité" for="scan-quantity" error="quantity" optional>
                        <input id="scan-quantity" type="text" inputmode="decimal" wire:model="quantity" placeholder="ex. 1"
                               @class(['form-input', 'form-input-error' => $errors->has('quantity')])>
                    </x-field>
                    <x-field label="Unité" for="scan-unit" error="unitId" optional>
                        <select id="scan-unit" wire:model="unitId" class="form-input">
                            <option value="">—</option>
                            @foreach ($this->units as $unit) <option value="{{ $unit->id }}">{{ $unit->label }}</option> @endforeach
                        </select>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Emplacement" for="scan-location" error="locationId">
                        <select id="scan-location" wire:model="locationId" class="form-input">
                            @foreach ($this->locations as $location) <option value="{{ $location->id }}">{{ $location->name }}</option> @endforeach
                        </select>
                    </x-field>
                    <x-field label="À consommer avant" for="scan-expires" error="expiresOn" optional>
                        <input id="scan-expires" type="date" wire:model="expiresOn" class="form-input">
                    </x-field>
                </div>

                <x-field label="Note" for="scan-note" error="note" optional>
                    <input id="scan-note" type="text" wire:model="note" placeholder="ex. promo, à finir vite" class="form-input">
                </x-field>

                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Ajouter au stock</button>
                    <button type="button" wire:click="reset_" class="btn btn-secondary">Annuler</button>
                </div>
            </form>
        </div>
    @endif

    {{-- ============================================================ Derniers produits --}}
    @if ($this->recent->isNotEmpty())
        <section class="card mt-6 p-5">
            <h2 class="font-display font-semibold text-stone-900">Produits déjà reconnus</h2>
            <ul class="mt-3 divide-y divide-stone-100 text-sm">
                @foreach ($this->recent as $product)
                    <li wire:key="product-{{ $product->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-stone-800">{{ $product->fullName() }}</span>
                            <span class="block font-mono text-xs text-stone-500">{{ $product->barcode }}</span>
                        </span>
                        @if ($product->ingredient)
                            <x-badge color="green">{{ $product->ingredient->name }}</x-badge>
                        @else
                            <x-badge color="amber">sans ingrédient</x-badge>
                        @endif
                        <button type="button" wire:click="lookup('{{ $product->barcode }}')" class="btn btn-ghost px-2 text-xs">Reprendre</button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
