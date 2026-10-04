<div>
    @if ($show)
        <div class="fixed inset-0 z-50 flex items-start justify-center p-3 pt-[max(0.75rem,env(safe-area-inset-top))] sm:p-6 sm:pt-[12vh]"
             role="dialog" aria-modal="true" aria-label="Recherche"
             x-data="{
                 move(step) {
                     const items = [...$refs.results.querySelectorAll('[data-result]')];
                     if (!items.length) return;
                     const index = items.indexOf(document.activeElement);
                     const next = index === -1 ? (step > 0 ? 0 : items.length - 1) : index + step;
                     if (next < 0) { $refs.input.focus(); return; }
                     items[Math.min(next, items.length - 1)].focus();
                 },
             }"
             x-on:keydown.escape.window="$wire.close()"
             x-on:keydown.down.prevent="move(1)"
             x-on:keydown.up.prevent="move(-1)">
            <div class="absolute inset-0 bg-stone-900/40" wire:click="close"></div>

            <div class="relative flex max-h-[85vh] w-full max-w-xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-stone-200">
                <div class="flex items-center gap-3 border-b border-stone-200 px-4">
                    <x-icon name="search" class="size-5 shrink-0 text-stone-400" />
                    <input type="search" x-ref="input" x-init="$nextTick(() => $el.focus())"
                           wire:model.live.debounce.200ms="query"
                           x-on:keydown.enter.prevent="$refs.results.querySelector('[data-result]')?.click()"
                           placeholder="Recette, produit en stock, ingrédient, invité, page…"
                           class="h-14 min-w-0 flex-1 border-0 bg-transparent text-base text-stone-900 placeholder:text-stone-400 focus:outline-none" aria-label="Rechercher" autocomplete="off">
                    <kbd class="hidden rounded border border-stone-200 px-1.5 py-0.5 text-xs text-stone-500 sm:inline">Échap</kbd>
                    <button type="button" wire:click="close" class="btn btn-ghost -mr-2 px-2 sm:hidden" title="Fermer"><x-icon name="close" class="size-5" /></button>
                </div>

                <div x-ref="results" class="overflow-y-auto p-2" wire:loading.class="opacity-60" wire:target="query">
                    @forelse ($this->results as $group => $items)
                        <section wire:key="search-group-{{ \Illuminate\Support\Str::slug($group) }}" class="mb-2 last:mb-0">
                            <h3 class="px-2 pt-2 pb-1 text-xs font-semibold tracking-wide text-stone-500 uppercase">{{ $group }}</h3>
                            <ul>
                                @foreach ($items as $i => $item)
                                    <li wire:key="search-{{ \Illuminate\Support\Str::slug($group) }}-{{ $i }}" class="group flex items-center gap-1 rounded-lg focus-within:bg-brand-50 hover:bg-stone-100">
                                        <a href="{{ $item['url'] }}" wire:navigate data-result x-on:click="$wire.close()"
                                           class="flex min-w-0 flex-1 items-center gap-3 rounded-lg px-2 py-2 outline-none">
                                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-stone-100 text-stone-500 group-focus-within:bg-white">
                                                <x-icon :name="$item['icon']" class="size-4" />
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="flex items-center gap-2">
                                                    <span class="truncate font-medium text-stone-900">{{ $item['title'] }}</span>
                                                    @if ($item['badge']) <x-badge :color="$item['badge']['color']" class="shrink-0">{{ $item['badge']['text'] }}</x-badge> @endif
                                                </span>
                                                @if ($item['subtitle'] !== '') <span class="block truncate text-xs text-stone-500">{{ $item['subtitle'] }}</span> @endif
                                            </span>
                                        </a>
                                        @foreach ($item['actions'] as $action)
                                            <a href="{{ $action['url'] }}" wire:navigate data-result x-on:click="$wire.close()"
                                               class="hidden shrink-0 rounded-md px-2 py-1 text-xs font-medium whitespace-nowrap text-brand-700 outline-none hover:bg-white focus:bg-white focus:ring-2 focus:ring-brand-400 sm:inline-block">{{ $action['label'] }}</a>
                                        @endforeach
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @empty
                        <p class="px-3 py-8 text-center text-sm text-stone-500">Rien trouvé pour « {{ $query }} ».</p>
                    @endforelse
                </div>

                <p class="hidden border-t border-stone-100 px-4 py-2 text-xs text-stone-500 sm:block">
                    <kbd class="rounded border border-stone-200 px-1">↑</kbd> <kbd class="rounded border border-stone-200 px-1">↓</kbd> pour choisir · <kbd class="rounded border border-stone-200 px-1">Entrée</kbd> pour ouvrir · <kbd class="rounded border border-stone-200 px-1">Ctrl</kbd> + <kbd class="rounded border border-stone-200 px-1">K</kbd> depuis n'importe quelle page
                </p>
            </div>
        </div>
    @endif
</div>
