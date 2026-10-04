<!DOCTYPE html>
{{--
    Mode magasin (15.4) et liste hors-ligne (15.1, 20.3 — règle R20).

    Page autonome : la liste est embarquée, les gestes sont gardés sur le téléphone et
    remontent au retour du réseau. Aucun appel au serveur n'est nécessaire pour cocher.
--}}
<html lang="fr" @class(['h-full', 'text-large' => auth()->user()?->preference('text_size') === 'grand'])>
<head>
    @include('partials.head', ['title' => $list->name.' · Mode magasin'])
</head>
<body class="h-full bg-stone-50 text-stone-900">
<div x-data="storeMode(@js($snapshot), '{{ route('shopping.sync', $list) }}', '{{ route('shopping.state', $list) }}', '{{ route('shopping.aisles', $list) }}')" x-cloak class="mx-auto flex min-h-full max-w-2xl flex-col">

    {{-- ============================================================ En-tête --}}
    <header class="sticky top-0 z-20 border-b border-stone-200 bg-white/95 backdrop-blur">
        <div class="flex items-center gap-2 px-3 py-2">
            <a href="{{ $back }}" class="btn btn-ghost px-2" title="Quitter le mode magasin">
                <x-icon name="chevron-left" class="size-5" />
            </a>
            <div class="min-w-0 flex-1">
                <h1 class="truncate text-base font-semibold">{{ $list->name }}</h1>
                <p class="text-xs text-stone-500">
                    <span x-text="remaining"></span> à prendre · <span x-text="checkedCount"></span> dans le panier
                </p>
            </div>

            {{-- Lot 42 (42.3) : courses à deux. --}}
            <button type="button" x-on:click="splitOpen = !splitOpen" x-show="split.people.length > 1"
                    :class="splitActive ? 'btn btn-secondary px-2' : 'btn btn-ghost px-2'" title="On se partage les rayons ?" data-split-toggle>
                <x-icon name="users" class="size-5" /><span class="sr-only">On se partage ?</span>
            </button>
            <button type="button" x-on:click="wake()" x-show="!awake" class="btn btn-ghost px-2" title="Garder l'écran allumé">
                <x-icon name="sun" class="size-5" />
            </button>
            <button type="button" x-on:click="hideChecked = !hideChecked"
                    :class="hideChecked ? 'btn btn-secondary px-2' : 'btn btn-ghost px-2'" title="Masquer ce qui est pris">
                <x-icon name="filter" class="size-5" />
            </button>
        </div>

        {{-- Barre d'état réseau (R20) --}}
        <div x-show="!online || pending.length > 0" class="flex items-center gap-2 px-3 py-1.5 text-xs font-medium"
             :class="online ? 'bg-amber-50 text-amber-900' : 'bg-stone-800 text-white'">
            <span x-show="!online">Hors ligne</span>
            <span x-show="online && pending.length > 0">Envoi en cours…</span>
            <span x-show="pending.length > 0">· <span x-text="pending.length"></span> modification(s) en attente</span>
            <button type="button" x-show="online && pending.length > 0" x-on:click="push()" class="ml-auto underline">Réessayer</button>
        </div>

        {{-- « On se partage ? » : chacun prend des rayons (42.3). --}}
        <div x-show="splitOpen" x-transition class="max-h-[60vh] overflow-y-auto border-t border-stone-200 bg-white px-3 py-3" data-split-panel>
            <p class="mb-2 text-sm text-stone-600">On se partage ? Chacun prend des rayons : les vôtres passent en premier, et vous voyez en direct ce que l'autre coche.</p>
            <ul class="divide-y divide-stone-100">
                <template x-for="aisle in list.aisles" :key="'split-' + aisle.id">
                    <li class="flex flex-wrap items-center gap-2 py-2">
                        <span class="min-w-0 flex-1 text-sm font-medium text-stone-800" x-text="aisle.name"></span>
                        <span class="flex flex-wrap gap-1">
                            <template x-for="person in splitPeople" :key="'p-' + aisle.id + '-' + person.id">
                                <button type="button" x-on:click="assign(aisle.id, ownerOf(aisle.id) === person.id ? null : person.id)"
                                        :aria-pressed="ownerOf(aisle.id) === person.id ? 'true' : 'false'"
                                        :class="ownerOf(aisle.id) === person.id ? 'bg-brand-600 text-white ring-brand-600' : 'bg-white text-stone-700 ring-stone-300'"
                                        class="min-h-10 rounded-full px-3 text-sm font-medium ring-1" x-text="person.id === split.me ? 'Moi' : person.name"></button>
                            </template>
                        </span>
                    </li>
                </template>
            </ul>
            <div class="mt-3 flex gap-2">
                <button type="button" x-on:click="splitOpen = false" class="btn btn-primary">C'est parti</button>
                <button type="button" x-on:click="resetSplit()" x-show="splitActive" class="btn btn-ghost">Arrêter le partage</button>
            </div>
        </div>

        <div class="h-1 bg-stone-100">
            <div class="h-1 bg-herb-500 transition-all" :style="`width: ${progress}%`"></div>
        </div>
    </header>

    {{-- ============================================================ Liste --}}
    <main class="flex-1 px-3 pt-3 pb-28">
        <template x-for="(aisle, index) in visibleAisles" :key="aisle.id">
            <div>
            <h2 x-show="sectionTitle(aisle, index) !== ''" x-text="sectionTitle(aisle, index)" class="mt-4 mb-2 px-1 text-xs font-bold tracking-wide text-stone-500 uppercase first:mt-0"></h2>
            <section class="mb-3 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-stone-200" :class="splitActive && ownerOf(aisle.id) !== null && !isMine(aisle.id) ? 'opacity-90' : ''">
                <button type="button" x-on:click="toggleAisle(aisle.id)"
                        class="flex w-full items-center gap-2 border-b border-stone-100 bg-stone-50 px-4 py-2.5 text-left">
                    <span class="flex-1 text-sm font-bold tracking-wide text-stone-700 uppercase" x-text="aisle.name"></span>
                    <span x-show="splitActive && ownerOf(aisle.id) !== null && !isMine(aisle.id)" class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-900" x-text="ownerName(aisle.id)"></span>
                    <span class="text-xs text-stone-500">
                        <span x-text="aisleRemaining(aisle.id)"></span> / <span x-text="aisleTotal(aisle.id)"></span>
                    </span>
                    <x-icon name="chevron-down" class="size-4 text-stone-400" x-show="!isCollapsed(aisle.id)" />
                    <x-icon name="chevron-right" class="size-4 text-stone-400" x-show="isCollapsed(aisle.id)" />
                </button>

                <div x-show="!isCollapsed(aisle.id)">
                    <ul class="divide-y divide-stone-100">
                        <template x-for="item in itemsOf(aisle.id)" :key="item.id">
                            <li>
                                <button type="button" x-on:click="toggle(item)"
                                        class="flex w-full items-center gap-3 px-4 py-3.5 text-left active:bg-stone-50">
                                    <span class="flex size-7 shrink-0 items-center justify-center rounded-md border-2 transition"
                                          :class="item.checked ? 'border-herb-600 bg-herb-600 text-white' : 'border-stone-300'">
                                        <svg x-show="item.checked" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                        </svg>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-lg leading-snug"
                                              :class="item.checked ? 'text-stone-500 line-through' : 'font-medium text-stone-900'"
                                              x-text="item.text"></span>
                                        <span x-show="item.note" class="block text-xs text-stone-500" x-text="item.note"></span>
                                        <span x-show="item.checked && item.checked_name && item.checked_by !== split.me" class="block text-xs text-sky-800" x-text="'pris par ' + item.checked_name"></span>
                                        <span x-show="item.optional" class="block text-xs text-stone-500">facultatif</span>
                                    </span>
                                </button>
                            </li>
                        </template>
                    </ul>

                    <button type="button" x-on:click="checkAisle(aisle.id)" x-show="aisleRemaining(aisle.id) > 0"
                            class="w-full border-t border-stone-100 px-4 py-2.5 text-sm font-medium text-brand-700 active:bg-brand-50">
                        Tout ce rayon est fait
                    </button>
                </div>
            </section>
            </div>
        </template>

        <p x-show="visibleAisles.length === 0" class="rounded-xl bg-white p-8 text-center text-stone-500 shadow-sm ring-1 ring-stone-200">
            <span x-show="hideChecked && items.length > 0">Tout est dans le panier.</span>
            <span x-show="items.length === 0">Cette liste est vide.</span>
        </p>

        {{-- Messages du serveur après synchronisation (R20) --}}
        <template x-for="(message, i) in messages" :key="i">
            <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" x-text="message"></p>
        </template>
    </main>

    {{-- ============================================================ Ajout rapide --}}
    <footer class="fixed inset-x-0 bottom-0 border-t border-stone-200 bg-white p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
        <form x-on:submit.prevent="add()" class="mx-auto flex max-w-2xl gap-2">
            <input type="text" x-model="newItem" placeholder="Ajouter un article…" aria-label="Ajouter un article"
                   class="form-input text-base" autocomplete="off">
            <button type="submit" class="btn btn-primary px-4">
                <x-icon name="plus" class="size-5" /><span class="sr-only">Ajouter</span>
            </button>
        </form>
    </footer>
</div>

@livewireScripts   {{-- Alpine.js : la page est hors Livewire, mais Alpine vient avec --}}
</body>
</html>
