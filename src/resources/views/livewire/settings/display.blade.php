<div>
    <x-page-header title="Paramètres" subtitle="Les listes de référence utilisées par les recettes et la liste de courses." />
    <x-settings-nav />

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- ============================================================ Thème --}}
        <section class="card space-y-4 p-5" x-data="{ theme: window.bouffeTheme?.get() ?? 'auto' }">
            <div>
                <h2 class="font-display font-semibold text-stone-900">Thème</h2>
                <p class="text-sm text-stone-500">Enregistré sur cet appareil : le téléphone peut être en sombre et l'ordinateur en clair.</p>
            </div>
            <div class="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Thème">
                @foreach (['auto' => ['desktop', 'Automatique', 'Comme le téléphone ou l\'ordinateur'], 'light' => ['sun', 'Clair', 'Toujours'], 'dark' => ['moon', 'Sombre', 'Toujours']] as $value => [$icon, $label, $help])
                    <button type="button" role="radio" x-bind:aria-checked="theme === '{{ $value }}'"
                            x-on:click="theme = '{{ $value }}'; window.bouffeTheme.set(theme)"
                            class="flex flex-col items-center gap-1 rounded-xl p-3 text-center ring-1 transition"
                            x-bind:class="theme === '{{ $value }}' ? 'bg-brand-50 text-brand-700 ring-brand-300' : 'text-stone-600 ring-stone-200 hover:ring-stone-300'">
                        <x-icon :name="$icon" class="size-6" />
                        <span class="text-sm font-semibold">{{ $label }}</span>
                        <span class="text-xs text-stone-500">{{ $help }}</span>
                    </button>
                @endforeach
            </div>
        </section>

        {{-- ============================================================ Taille du texte (lot 29, 29.8) --}}
        <section class="card space-y-4 p-5">
            <div>
                <h2 class="font-display font-semibold text-stone-900">Taille du texte</h2>
                <p class="text-sm text-stone-600">Pour votre compte, sur tous vos appareils. « Grand » agrandit aussi les cases à cocher, les boutons et le mode cuisine.</p>
            </div>
            <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Taille du texte">
                @foreach (['normal' => ['Normal', 'text-sm'], 'grand' => ['Grand', 'text-lg']] as $value => [$label, $sample])
                    <button type="button" role="radio" aria-checked="{{ $textSize === $value ? 'true' : 'false' }}" wire:click="setTextSize('{{ $value }}')"
                            @class(['flex flex-col items-center gap-1 rounded-xl p-3 text-center ring-1 transition', 'bg-brand-50 text-brand-800 ring-brand-300' => $textSize === $value, 'text-stone-700 ring-stone-200 hover:ring-stone-300' => $textSize !== $value])>
                        <span class="font-display {{ $sample }} font-semibold">Aa</span>
                        <span class="text-sm font-semibold">{{ $label }}</span>
                    </button>
                @endforeach
            </div>
        </section>

        {{-- ============================================================ Aide contextuelle (lot 30, 30.3) --}}
        <section class="card space-y-4 p-5">
            <div>
                <h2 class="font-display font-semibold text-stone-900">Aide « Le saviez-vous ? »</h2>
                <p class="text-sm text-stone-600">
                    Une bulle d'explication la première fois qu'on ouvre le planning, le stock, une liste de courses ou les prix.
                    @if ($hintsSeen > 0) Vous en avez fermé {{ $hintsSeen }} sur {{ $hintsTotal }}. @endif
                </p>
            </div>
            <label class="flex items-center gap-3 text-sm text-stone-700">
                <input type="checkbox" wire:click="toggleHints" @checked(! $hintsOff) class="form-checkbox">
                Afficher les aides
            </label>
            <button type="button" wire:click="resetHints" class="btn btn-secondary" @disabled($hintsSeen === 0 && ! $hintsOff)>
                <x-icon name="rotate" class="size-4" /> Réafficher toutes les aides
            </button>
        </section>

        {{-- ============================================================ Barre du bas --}}
        <form wire:submit="saveBottom" class="card space-y-4 p-5">
            <div>
                <h2 class="font-display font-semibold text-stone-900">Barre du bas du téléphone</h2>
                <p class="text-sm text-stone-600">Quatre raccourcis, de part et d'autre du bouton « + » ; les autres pages sont dans « Plus », en haut à droite. Réglage propre à votre compte.</p>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($bottom as $i => $key)
                    <label wire:key="bottom-{{ $i }}" class="block">
                        <span class="form-label">{{ $i + 1 }}</span>
                        <select wire:model="bottom.{{ $i }}" @class(['form-input', 'form-input-error' => $errors->has('bottom.'.$i)])>
                            @foreach ($sections as $value => [$route, $label]) <option value="{{ $value }}">{{ $label }}</option> @endforeach
                        </select>
                    </label>
                @endforeach
            </div>
            @if ($errors->has('bottom.*') || $errors->has('bottom'))
                <p class="form-error">{{ $errors->first('bottom.*') ?: $errors->first('bottom') }}</p>
            @endif
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="resetBottom" class="btn btn-ghost">Par défaut</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>

        {{-- ============================================================ Accueil --}}
        <section class="card space-y-4 p-5 lg:col-span-2" id="accueil">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-display font-semibold text-stone-900">Blocs de l'accueil</h2>
                    <p class="text-sm text-stone-500">Ordre et blocs affichés sur la page « Aujourd'hui ». Réglage propre à votre compte.</p>
                </div>
                <button type="button" wire:click="resetHome" class="btn btn-ghost">Ordre d'origine</button>
            </div>
            <ul class="divide-y divide-stone-100 rounded-lg ring-1 ring-stone-200">
                @foreach ($home as $i => $row)
                    <li wire:key="home-{{ $row['key'] }}" class="flex items-center gap-3 px-3 py-2">
                        <label class="flex flex-1 items-center gap-3 text-sm">
                            <input type="checkbox" @checked($row['visible']) wire:click="toggleHome({{ $i }})" class="form-checkbox">
                            <span @class(['font-medium', 'text-stone-900' => $row['visible'], 'text-stone-500 line-through' => ! $row['visible']])>{{ $row['label'] }}</span>
                        </label>
                        <button type="button" wire:click="moveHome({{ $i }}, -1)" class="btn btn-ghost px-2 py-1 disabled:opacity-30" @disabled($loop->first) title="Monter"><x-icon name="chevron-up" class="size-4" /><span class="sr-only">Monter</span></button>
                        <button type="button" wire:click="moveHome({{ $i }}, 1)" class="btn btn-ghost px-2 py-1 disabled:opacity-30" @disabled($loop->last) title="Descendre"><x-icon name="chevron-down" class="size-4" /><span class="sr-only">Descendre</span></button>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</div>
