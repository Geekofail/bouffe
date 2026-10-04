<div>
    <x-page-header title="Nouveau ticket" subtitle="Une photo du ticket, ou le PDF reçu par e-mail.">
        <x-slot:actions>
            <a href="{{ route('receipts.index') }}" wire:navigate class="btn btn-secondary"><x-icon name="receipt" class="size-4" /> Tickets</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mx-auto max-w-2xl space-y-4">
        <section class="card p-4 sm:p-5" x-data="bouffeReceiptPhoto()">
            {{-- Choix du fichier --}}
            <div x-show="! image" class="space-y-3">
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="btn btn-primary cursor-pointer justify-center py-3">
                        <x-icon name="camera" class="size-5" /> Prendre une photo
                        <input type="file" accept="image/*" capture="environment" class="sr-only" x-on:change="pick($event)">
                    </label>
                    <label class="btn btn-secondary cursor-pointer justify-center py-3">
                        <x-icon name="photo" class="size-5" /> Choisir un fichier
                        <input type="file" accept="image/*,application/pdf" class="sr-only" x-on:change="pick($event)">
                    </label>
                </div>
                <p class="text-sm text-stone-500">
                    Ticket long : prenez-le en plusieurs photos, de haut en bas. Posez-le à plat, bien éclairé, sans pli.
                </p>
            </div>

            {{-- Rotation et recadrage --}}
            <div x-show="image" x-cloak class="space-y-3">
                <div class="flex justify-center rounded-lg bg-stone-100 p-2">
                    <canvas x-ref="preview" class="max-h-[60vh] max-w-full"></canvas>
                </div>
                <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    @foreach (['top' => 'Haut', 'bottom' => 'Bas', 'left' => 'Gauche', 'right' => 'Droite'] as $side => $label)
                        <label class="flex items-center gap-2">
                            <span class="w-14 shrink-0 text-stone-600">{{ $label }}</span>
                            <input type="range" min="0" max="40" step="1" x-model.number="crop.{{ $side }}" x-on:input="preview()" class="min-w-0 flex-1 accent-brand-600" aria-label="Recadrer : {{ mb_strtolower($label) }}">
                        </label>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" x-on:click="rotate()" class="btn btn-secondary"><x-icon name="rotate" class="size-4" /> Tourner</button>
                    <button type="button" x-on:click="cancel()" class="btn btn-ghost">Annuler</button>
                    <button type="button" x-on:click="confirm()" class="btn btn-primary ml-auto" x-bind:disabled="uploading"><x-icon name="check" class="size-4" /> Garder cette photo</button>
                </div>
            </div>

            <div x-show="uploading" x-cloak class="mt-3">
                <div class="h-2 overflow-hidden rounded-full bg-stone-100"><div class="h-2 rounded-full bg-brand-500 transition-all" x-bind:style="'width:' + progress + '%'"></div></div>
                <p class="mt-1 text-xs text-stone-500">Envoi…</p>
            </div>
            <p x-show="error" x-text="error" x-cloak class="mt-2 text-sm text-red-700"></p>
            @error('newPhoto') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        </section>

        {{-- Photos gardées --}}
        @if ($photos !== [])
            <section class="card p-4">
                <h2 class="font-display mb-2 font-semibold text-stone-900">{{ count($photos) }} {{ count($photos) > 1 ? 'photos' : 'photo' }} pour ce ticket</h2>
                <ul class="flex flex-wrap gap-3">
                    @foreach ($photos as $i => $photo)
                        <li wire:key="photo-{{ $i }}" class="relative">
                            @if (str_ends_with(strtolower($photo->getClientOriginalName()), '.pdf'))
                                <span class="grid h-28 w-20 place-items-center rounded-lg bg-stone-100 text-xs font-semibold text-stone-600">PDF</span>
                            @else
                                <img src="{{ $photo->temporaryUrl() }}" alt="Photo {{ $i + 1 }}" class="h-28 w-20 rounded-lg object-cover ring-1 ring-stone-200">
                            @endif
                            <button type="button" wire:click="removePhoto({{ $i }})" class="absolute -top-2 -right-2 grid size-6 place-items-center rounded-full bg-white text-stone-600 shadow ring-1 ring-stone-200" title="Retirer">
                                <x-icon name="close" class="size-3.5" /><span class="sr-only">Retirer la photo {{ $i + 1 }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
                @error('photos') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
            </section>
        @endif

        {{-- Lecture --}}
        <section class="card space-y-3 p-4">
            @if ($status['available'])
                <button type="button" wire:click="read" wire:loading.attr="disabled" @disabled($photos === []) class="btn btn-primary w-full py-3 disabled:opacity-50">
                    <span wire:loading.remove wire:target="read"><x-icon name="sparkles" class="inline size-5" /> Lire le ticket</span>
                    <span wire:loading wire:target="read">Lecture en cours… (10 à 30 secondes)</span>
                </button>
                <p class="text-xs text-stone-500">
                    Envoyé à {{ $status['label'] }} : seulement la photo du ticket. {{ $usage['count'] }} lecture{{ $usage['count'] > 1 ? 's' : '' }} ce mois-ci sur {{ $usage['cap'] }}.
                </p>
            @else
                <p class="flex gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900"><x-icon name="info" class="size-5 shrink-0" /> {{ $status['reason'] }}</p>
            @endif
            <button type="button" wire:click="manual" wire:loading.attr="disabled" class="btn btn-secondary w-full">
                <x-icon name="edit" class="size-4" /> Saisir le ticket à la main
            </button>
        </section>
    </div>
</div>
