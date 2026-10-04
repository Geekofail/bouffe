{{-- Planning : en-tête, navigation et actions de la semaine (lot 36 : découpé de week.blade.php). --}}
{{-- ============================================================ En-tête et navigation --}}
<div class="mb-5 flex flex-wrap items-center gap-3">
    <div class="mr-auto">
        <h1 class="page-title">Planning</h1>
        <p class="mt-1 text-sm text-stone-500">
            Semaine du {{ $weekStart->locale('fr')->isoFormat('D MMMM') }} au {{ $weekStart->copy()->addDays(6)->locale('fr')->isoFormat('D MMMM YYYY') }}
            {{-- Personnes à table d'habitude et portions selon l'appétit (lot 32, 32.2). --}}
            · <a href="{{ route('settings.household') }}" wire:navigate class="hover:underline" title="Régler qui est à table d'habitude et l'appétit de chacun">À table : {{ $tableSummary }}</a>
            @if ($guestMealsCount > 0)
                · <span class="font-medium text-violet-700">{{ $guestMealsCount }} repas avec invités</span>
            @endif
            {{-- Coût estimé des repas de la semaine (17.2). --}}
            @if ($weekCost->isKnown())
                · <span class="font-medium text-stone-700" title="Coût estimé des recettes planifiées{{ $weekCost->missingLabel() ? ' · '.$weekCost->missingLabel() : '' }}">{{ $weekCost->label($prices) }}</span>
            @endif
        </p>
    </div>

    <div class="flex items-center gap-1">
        <button type="button" wire:click="previousWeek" class="btn btn-secondary px-2.5" title="Semaine précédente">
            <x-icon name="chevron-left" class="size-4" /><span class="sr-only">Semaine précédente</span>
        </button>
        <button type="button" wire:click="currentWeek" @class(['btn px-3', 'btn-secondary' => ! $isCurrentWeek, 'btn-ghost pointer-events-none text-brand-700' => $isCurrentWeek])>
            Cette semaine
        </button>
        <button type="button" wire:click="nextWeek" class="btn btn-secondary px-2.5" title="Semaine suivante">
            <x-icon name="chevron-right" class="size-4" /><span class="sr-only">Semaine suivante</span>
        </button>
    </div>

    {{-- Actions : sur téléphone et tablette, deux boutons et un menu « Plus » libellé (lot 28, 28.2) ;
         sur grand écran, toutes les actions avec leur libellé. --}}
    <div class="flex w-full items-center gap-2 sm:w-auto xl:hidden print:hidden">
        <a href="{{ route('shopping.index', ['generer' => $weekStart->toDateString()]) }}" wire:navigate class="btn btn-primary flex-1 sm:flex-none">
            <x-icon name="cart" class="size-4" /> Courses
        </a>
        @if (auth()->user()->canEdit())
            <a href="{{ route('planner.fill', ['semaine' => $weekStart->toDateString()]) }}" wire:navigate class="btn btn-secondary flex-1 sm:flex-none">
                <x-icon name="sparkles" class="size-4" /> Remplir
            </a>
        @endif
        <x-action-sheet label="Plus" title="Actions de la semaine" class="flex-1 sm:flex-none" button-class="btn btn-secondary w-full">
            <p class="menu-heading">Cette semaine</p>
            <button type="button" wire:click="openCopy" x-on:click="open = false" class="menu-item"><x-icon name="duplicate" class="size-5 text-stone-400" /> Copier la semaine</button>
            <a href="{{ route('planner.print', ['semaine' => $weekStart->toDateString()]) }}" target="_blank" class="menu-item"><x-icon name="printer" class="size-5 text-stone-400" /> Imprimer le menu</a>
            {{-- Lot 41 (41.2) : les recettes de la semaine gardées dans le téléphone. --}}
            <a href="{{ route('offline.week', ['semaine' => $weekStart->toDateString()]) }}" class="menu-item"><x-icon name="download" class="size-5 text-stone-400" /> Recettes sans réseau</a>
            <a href="{{ route('planner.lunchboxes', ['du' => $weekStart->toDateString(), 'au' => $weekStart->copy()->addDays(6)->toDateString()]) }}" target="_blank" class="menu-item"><x-icon name="lunchbox" class="size-5 text-stone-400" /> Gamelles et étiquettes @if ($weekLunchboxes > 0) <span class="ml-auto text-xs text-stone-500 tabular-nums">{{ $weekLunchboxes }}</span> @endif</a>
            @if (auth()->user()->canEdit())
                <a href="{{ route('planner.templates', ['semaine' => $weekStart->toDateString()]) }}" wire:navigate class="menu-item"><x-icon name="squares" class="size-5 text-stone-400" /> Semaines types</a>
                <a href="{{ route('planner.batch') }}" wire:navigate class="menu-item"><x-icon name="fire" class="size-5 text-stone-400" /> Cuisiner en avance</a>
            @endif
            {{-- Lot 39 : la cantine du midi, le choix des enfants. --}}
            <p class="menu-heading">Les enfants</p>
            <a href="{{ route('planner.canteen', ['semaine' => $weekStart->toDateString()]) }}" wire:navigate class="menu-item"><x-icon name="school" class="size-5 text-stone-400" /> Cantine</a>
            @if (auth()->user()->canEdit())
                <a href="{{ route('planner.choices') }}" wire:navigate class="menu-item"><x-icon name="smile" class="size-5 text-stone-400" /> Le choix des enfants</a>
            @endif
            <p class="menu-heading">Bilan</p>
            <a href="{{ route('planner.stats') }}" wire:navigate class="menu-item"><x-icon name="chart" class="size-5 text-stone-400" /> Statistiques</a>
            <a href="{{ route('planner.year') }}" wire:navigate class="menu-item"><x-icon name="star" class="size-5 text-stone-400" /> L'année en cuisine</a>
            <a href="{{ route('kitchen') }}" class="menu-item"><x-icon name="desktop" class="size-5 text-stone-400" /> Écran de cuisine</a>
            <p class="menu-heading">Affichage</p>
            <button type="button" wire:click="toggleMine" x-on:click="open = false" class="menu-item" aria-pressed="{{ $mineOnly ? 'true' : 'false' }}">
                <x-icon name="user" class="size-5 text-stone-400" /> Mes repas en avant
                @if ($mineOnly) <x-icon name="check" class="ml-auto size-4 text-brand-700" /> @endif
            </button>
            <button type="button" wire:click="toggleView" x-on:click="open = false" class="menu-item">
                <x-icon :name="$view === 'liste' ? 'squares' : 'list'" class="size-5 text-stone-400" /> {{ $view === 'liste' ? 'Vue par jour' : 'Vue liste compacte' }}
            </button>
            <a href="{{ route('guests.index') }}" wire:navigate class="menu-item md:hidden"><x-icon name="users" class="size-5 text-stone-400" /> Carnet d'invités</a>
            @if (auth()->user()->canEdit())
                <div class="my-1 border-t border-stone-100"></div>
                <button type="button" wire:click="clearWeek" wire:confirm="Retirer tous les repas de cette semaine ? (les convives et invités saisis sont conservés)" x-on:click="open = false" class="menu-item menu-item-danger">
                    <x-icon name="delete" class="size-5" /> Vider la semaine
                </button>
            @endif
        </x-action-sheet>
    </div>

    <div class="hidden flex-wrap items-center gap-1 xl:flex print:hidden">
        <a href="{{ route('shopping.index', ['generer' => $weekStart->toDateString()]) }}" wire:navigate class="btn btn-primary">
            <x-icon name="cart" class="size-4" /> Liste de courses
        </a>
        <a href="{{ route('planner.print', ['semaine' => $weekStart->toDateString()]) }}" target="_blank" class="btn btn-ghost">
            <x-icon name="printer" class="size-4" /> Imprimer
        </a>
        @if ($weekLunchboxes > 0)
            <a href="{{ route('planner.lunchboxes', ['du' => $weekStart->toDateString(), 'au' => $weekStart->copy()->addDays(6)->toDateString()]) }}" target="_blank" class="btn btn-ghost">
                <x-icon name="lunchbox" class="size-4" /> Gamelles ({{ $weekLunchboxes }})
            </a>
        @endif
        @if (auth()->user()->canEdit())
            <a href="{{ route('planner.fill', ['semaine' => $weekStart->toDateString()]) }}" wire:navigate class="btn btn-secondary">
                <x-icon name="sparkles" class="size-4" /> Remplir
            </a>
            <a href="{{ route('planner.templates', ['semaine' => $weekStart->toDateString()]) }}" wire:navigate class="btn btn-ghost">
                <x-icon name="squares" class="size-4" /> Semaines types
            </a>
            <a href="{{ route('planner.batch') }}" wire:navigate class="btn btn-ghost">
                <x-icon name="fire" class="size-4" /> En avance
            </a>
        @endif
        <button type="button" wire:click="toggleMine" aria-pressed="{{ $mineOnly ? 'true' : 'false' }}"
                @class(['btn', 'btn-secondary' => $mineOnly, 'btn-ghost' => ! $mineOnly])
                title="Mettre en avant les repas que je cuisine">
            <x-icon name="user" class="size-4" /> Mes repas
        </button>
        <button type="button" wire:click="toggleView" class="btn btn-ghost">
            <x-icon :name="$view === 'liste' ? 'squares' : 'list'" class="size-4" /> {{ $view === 'liste' ? 'Grille' : 'Liste' }}
        </button>
        <button type="button" wire:click="openCopy" class="btn btn-ghost">
            <x-icon name="duplicate" class="size-4" /> Copier
        </button>
        <a href="{{ route('planner.stats') }}" wire:navigate class="btn btn-ghost">
            <x-icon name="chart" class="size-4" /> Statistiques
        </a>
        <a href="{{ route('offline.week', ['semaine' => $weekStart->toDateString()]) }}" class="btn btn-ghost" title="Garder les recettes de la semaine dans l'appareil">
            <x-icon name="download" class="size-4" /> Sans réseau
        </a>
        {{-- Lot 39 : la cantine (si quelqu'un y mange) et le choix des enfants. --}}
        @if (app(\App\Services\People\CanteenCalendar::class)->anyone())
            <a href="{{ route('planner.canteen', ['semaine' => $weekStart->toDateString()]) }}" wire:navigate class="btn btn-ghost">
                <x-icon name="school" class="size-4" /> Cantine
            </a>
        @endif
        @if (auth()->user()->canEdit())
            <a href="{{ route('planner.choices') }}" wire:navigate class="btn btn-ghost" title="Le choix des enfants">
                <x-icon name="smile" class="size-4" /> Choix des enfants
            </a>
        @endif
        @if (auth()->user()->canEdit())
            <button type="button" wire:click="clearWeek" wire:confirm="Retirer tous les repas de cette semaine ? (les convives et invités saisis sont conservés)" class="btn btn-ghost hover:text-red-600">
                <x-icon name="delete" class="size-4" /> Vider
            </button>
        @endif
    </div>
</div>
