{{--
    Menu « Plus » (lot 28, 28.2) : sur téléphone, une feuille qui monte du bas de l'écran ;
    sur un écran plus large, un menu déroulant sous le bouton. Les actions gardent leur libellé.

    <x-action-sheet label="Plus" title="Actions de la semaine">
        <p class="menu-heading">Semaine</p>
        <button type="button" class="menu-item" wire:click="…" x-on:click="open = false"><x-icon name="…" class="size-5" /> Copier</button>
    </x-action-sheet>
--}}
@props(['label' => 'Plus', 'title' => 'Plus d\'actions', 'icon' => 'dots', 'buttonClass' => 'btn btn-secondary'])

{{--
    Sur un écran large, le menu est placé en « fixed » sous le bouton (ou au-dessus s'il manque de place) :
    il ne peut ainsi jamais être coupé par un bloc parent qui masque ce qui dépasse (overflow-hidden),
    comme la carte d'en-tête d'une recette. Il suit le bouton quand la page défile.
--}}
<div x-data="{
        open: false,
        wide: () => window.matchMedia('(min-width: 640px)').matches,
        toggle() {
            this.open = ! this.open;
            if (this.open) this.$nextTick(() => this.place());
        },
        place() {
            const menu = this.$refs.menu;
            menu.style.top = menu.style.bottom = menu.style.right = menu.style.maxHeight = '';
            if (! this.open || ! this.wide()) return;
            const button = this.$refs.button.getBoundingClientRect();
            const below = window.innerHeight - button.bottom;
            const up = below < menu.offsetHeight + 12 && button.top > below;
            if (up) menu.style.bottom = (window.innerHeight - button.top + 4) + 'px';
            else menu.style.top = (button.bottom + 4) + 'px';
            menu.style.right = Math.max(8, document.documentElement.clientWidth - button.right) + 'px';
            menu.style.maxHeight = Math.max(160, (up ? button.top : below) - 16) + 'px';
        },
     }"
     x-on:keydown.escape.window="open = false" x-on:scroll.window.passive="open && place()" x-on:resize.window="place()"
     {{ $attributes->merge(['class' => 'relative']) }}>
    <button type="button" x-ref="button" x-on:click="toggle()" x-bind:aria-expanded="open ? 'true' : 'false'" aria-haspopup="menu" class="{{ $buttonClass }}">
        <x-icon :name="$icon" class="size-4" /> <span>{{ $label }}</span>
    </button>

    {{-- Fond (téléphone) --}}
    <div x-show="open" x-cloak x-transition.opacity x-on:click="open = false" class="fixed inset-0 z-50 bg-stone-900/40 sm:hidden" aria-hidden="true"></div>

    <div x-show="open" x-cloak x-ref="menu" x-on:click.outside="open = false" role="menu" aria-label="{{ $title }}" data-action-menu
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="translate-y-4 opacity-0 sm:translate-y-0" x-transition:enter-end="translate-y-0 opacity-100"
         class="fixed inset-x-0 bottom-0 z-50 max-h-[80vh] overflow-y-auto rounded-t-2xl bg-white p-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] shadow-xl ring-1 ring-stone-200
                sm:inset-x-auto sm:bottom-auto sm:w-64 sm:rounded-xl sm:p-1 sm:shadow-lg">
        <div class="mx-auto mb-1 h-1 w-10 rounded-full bg-stone-300 sm:hidden" aria-hidden="true"></div>
        <p class="px-3 pt-1 pb-2 text-sm font-semibold text-stone-900 sm:hidden">{{ $title }}</p>
        {{ $slot }}
    </div>
</div>
