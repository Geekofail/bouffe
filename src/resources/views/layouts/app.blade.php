@php
    $navigation = [
        ['route' => 'dashboard', 'label' => 'Accueil', 'icon' => 'home'],
        ['route' => 'recipes.index', 'label' => 'Recettes', 'icon' => 'recipes'],
        ['route' => 'planner.week', 'label' => 'Planning', 'icon' => 'calendar'],
        ['route' => 'guests.index', 'label' => 'Invités', 'icon' => 'users', 'desktop' => true],
        ['route' => 'stock.index', 'label' => 'Stock', 'icon' => 'pantry'],
        ['route' => 'shopping.index', 'label' => 'Courses', 'icon' => 'cart'],
        ['route' => 'budget.index', 'label' => 'Budget', 'icon' => 'euro', 'desktop' => true],
        ['route' => 'settings.index', 'label' => 'Paramètres', 'icon' => 'settings', 'mobileHeader' => true],
    ];
    $stockAlerts = \App\Support\Settings::bool('stock.nav_badge', true) ? app(\App\Services\Stock\ExpiryAlerts::class)->counts() : ['total' => 0, 'urgent' => 0];
    // Lot 27 : « Prix et magasins » est rangé sous Budget.
    $isActive = fn (string $route) => request()->routeIs(\Illuminate\Support\Str::before($route, '.').'*') || ($route === 'budget.index' && request()->routeIs('prices.*'));
    // Sur téléphone, les invités sont rattachés à l'onglet Planning (bouton « Invités » du planning).
    $isActiveMobile = fn (string $route) => $isActive($route) || ($route === 'planner.week' && request()->routeIs('guests.*'));
    // Barre du bas (lot 11) : 4 raccourcis choisis dans Paramètres → Affichage, le reste dans « Plus ».
    $bottomKeys = \App\Support\Navigation::bottom(auth()->user());
    $mobileNavigation = array_map(fn (string $key) => ['key' => $key, 'route' => \App\Support\Navigation::SECTIONS[$key][0], 'label' => \App\Support\Navigation::SECTIONS[$key][1], 'icon' => \App\Support\Navigation::SECTIONS[$key][2]], $bottomKeys);
    // Foyers (lot 24, 25.4) : sélecteur s'il y en a plusieurs, lien d'administration pour l'administrateur.
    $myHouseholds = auth()->user()->households()->whereNull('disabled_at')->get(['households.id', 'households.name']);
    $activeHouseholdId = \App\Support\CurrentHousehold::id();
    // Lot 26 : le menu du foyer mène aussi aux proches (foyers reliés) : toujours affiché.
    $showHouseholdMenu = true;
    $moreNavigation = collect(\App\Support\Navigation::SECTIONS)->except($bottomKeys)->map(fn ($s, $key) => ['key' => $key, 'route' => $s[0], 'label' => $s[1], 'icon' => $s[2]])->values()->all();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" wire:transition.navigate @class(['h-full bg-stone-50', 'text-large' => auth()->user()?->preference('text_size') === 'grand'])>
    <head>
        @include('partials.head')
    </head>
    <body class="h-full font-sans text-stone-800 antialiased print:bg-white">
        <div class="min-h-full pb-20 md:pb-0">
            {{-- Barre supérieure --}}
            <header wire:transition.navigate="entete" class="sticky top-0 z-30 border-b border-stone-200 bg-white/90 backdrop-blur print:hidden">
                <div class="mx-auto flex h-14 max-w-7xl items-center gap-6 px-4 sm:px-6 lg:px-8">
                    <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2 text-brand-700">
                        <x-app-logo class="size-7" />
                        <span class="font-display text-xl font-bold tracking-tight">Bouffe</span>
                    </a>

                    <nav class="hidden flex-1 items-center gap-1 md:flex" aria-label="Navigation principale">
                        @foreach ($navigation as $item)
                            <a href="{{ route($item['route']) }}" wire:navigate
                               @class([
                                   'flex items-center gap-1.5 rounded-lg px-2 py-2 text-sm font-medium transition 2xl:gap-2 2xl:px-3',
                                   'bg-brand-50 text-brand-700' => $isActive($item['route']),
                                   'text-stone-600 hover:bg-stone-100 hover:text-stone-900' => ! $isActive($item['route']),
                               ])
                               title="{{ $item['label'] }}"
                               @if ($isActive($item['route'])) aria-current="page" @endif>
                                <span class="relative">
                                    <x-icon :name="$item['icon']" class="size-5" />
                                    @if ($item['route'] === 'stock.index' && $stockAlerts['total'] > 0)
                                        <span @class(['absolute -top-1.5 -right-2 min-w-4 rounded-full px-1 text-center text-[10px] leading-4 font-bold text-white tabular-nums xl:hidden', 'bg-red-600' => $stockAlerts['urgent'] > 0, 'bg-orange-500' => $stockAlerts['urgent'] === 0])>{{ $stockAlerts['total'] }}</span>
                                    @endif
                                </span>
                                <span class="sr-only xl:not-sr-only">{{ $item['label'] }}</span>
                                @if ($item['route'] === 'stock.index' && $stockAlerts['total'] > 0)
                                    <span @class(['hidden min-w-5 rounded-full px-1.5 text-center text-xs leading-5 font-bold text-white tabular-nums xl:inline-block', 'bg-red-600' => $stockAlerts['urgent'] > 0, 'bg-orange-500' => $stockAlerts['urgent'] === 0])
                                          title="{{ $stockAlerts['total'] }} produit(s) à surveiller">{{ $stockAlerts['total'] }}</span>
                                @endif
                            </a>
                        @endforeach
                    </nav>

                    <div class="ml-auto flex items-center gap-1 sm:gap-2 md:gap-1 2xl:gap-2">
                        {{-- Lot 41 (41.1) : les minuteurs de la maison, sur toutes les pages, dès qu'il y en a un. --}}
                        @persist('timer-pill')
                            <div x-data="bouffeTimerPill()" x-show="timers.length > 0 && ! hidden" x-cloak data-timer-pill
                                 class="relative print:hidden" x-on:keydown.escape.window="open = false">
                                <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open"
                                        class="flex min-h-10 items-center gap-1.5 rounded-full px-2.5 py-1 text-sm font-medium ring-1"
                                        x-bind:class="next && finished(next) ? 'bg-red-600 text-white ring-red-700 animate-pulse' : 'bg-stone-900 text-white ring-stone-900'"
                                        x-bind:title="next ? next.label : 'Minuteurs'">
                                    <x-icon name="clock" class="size-4 shrink-0" />
                                    <span class="hidden max-w-40 truncate lg:inline" x-text="next ? next.label : ''"></span>
                                    <span class="font-bold tabular-nums" x-text="next ? display(next) : ''"></span>
                                    <span x-show="timers.length > 1" class="rounded-full bg-white/20 px-1.5 text-xs" x-text="'+' + (timers.length - 1)"></span>
                                    <span class="sr-only">Minuteurs</span>
                                </button>
                                <div x-show="open" x-transition x-on:click.outside="open = false" role="dialog" aria-label="Minuteurs"
                                     class="fixed inset-x-3 top-16 z-40 space-y-1.5 rounded-xl bg-white p-2 shadow-xl ring-1 ring-stone-200 sm:absolute sm:inset-x-auto sm:top-full sm:right-0 sm:mt-2 sm:w-80">
                                    <template x-for="timer in timers" :key="(timer.local ? 'l' : 's') + timer.id">
                                        <div class="flex items-center gap-2 rounded-lg px-2 py-1.5" :class="finished(timer) ? 'bg-red-50 text-red-900' : 'text-stone-800'">
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-medium" x-text="timer.label"></span>
                                                <span class="block truncate text-xs text-stone-500" x-text="timer.local ? 'sur cet appareil' : (timer.mine ? 'lancé par vous' : 'lancé par ' + (timer.by || 'quelqu\'un'))"></span>
                                            </span>
                                            <span class="font-bold tabular-nums" x-text="display(timer)"></span>
                                            <button type="button" x-on:click="remove(timer)" class="btn btn-secondary min-h-10 px-2.5 text-sm" x-text="finished(timer) ? 'OK' : 'Arrêter'"></button>
                                        </div>
                                    </template>
                                    <a href="{{ route('kitchen') }}" class="block px-2 pt-1 text-xs font-medium text-brand-700 hover:underline">Écran de cuisine</a>
                                </div>
                            </div>
                        @endpersist
                        <button type="button" x-data x-on:click="Livewire.dispatch('open-search')"
                                class="hidden items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-stone-500 ring-1 ring-stone-200 transition hover:text-stone-800 hover:ring-stone-300 md:flex"
                                title="Rechercher (Ctrl + K)">
                            <x-icon name="search" class="size-4" />
                            <span class="hidden 2xl:inline">Rechercher</span>
                            <kbd class="hidden rounded border border-stone-200 px-1 text-[10px] text-stone-500 2xl:inline">Ctrl K</kbd>
                        </button>
                        <button type="button" x-data x-on:click="Livewire.dispatch('open-search')" class="btn btn-ghost px-2 md:hidden" title="Rechercher">
                            <x-icon name="search" class="size-5" /><span class="sr-only">Rechercher</span>
                        </button>
                        <livewire:layout.notifications />
                        {{-- Lot 29 : « Plus » (toutes les sections) passe en haut sur téléphone ; le « + » prend le centre de la barre du bas. --}}
                        <button type="button" x-data x-on:click="$dispatch('open-more')" class="btn btn-ghost relative px-2 md:hidden" title="Plus : toutes les sections"
                                @if (collect($moreNavigation)->contains(fn ($i) => $isActive($i['route']))) aria-current="page" @endif>
                            <x-icon name="squares" @class(['size-5', 'text-brand-700' => collect($moreNavigation)->contains(fn ($i) => $isActive($i['route']))]) />
                            @if (! in_array('stock', $bottomKeys, true) && $stockAlerts['total'] > 0)
                                <span @class(['absolute top-1.5 right-1 size-2.5 rounded-full', 'bg-red-600' => $stockAlerts['urgent'] > 0, 'bg-orange-500' => $stockAlerts['urgent'] === 0])></span>
                            @endif
                            <span class="sr-only">Plus : toutes les sections</span>
                        </button>
                        <button type="button" x-data x-on:click="Livewire.dispatch('open-quick-add')" class="btn btn-ghost hidden px-2 md:inline-flex" title="Ajouter (stock, courses, repas, recette, dépense)">
                            <x-icon name="plus" class="size-5" /><span class="sr-only">Ajouter</span>
                        </button>
                        <button type="button" x-data="{ theme: window.bouffeTheme?.get() ?? 'auto' }"
                                x-on:click="theme = { auto: 'light', light: 'dark', dark: 'auto' }[theme]; window.bouffeTheme.set(theme)"
                                class="btn btn-ghost hidden px-2 md:inline-flex"
                                x-bind:title="{ auto: 'Thème : automatique', light: 'Thème : clair', dark: 'Thème : sombre' }[theme]">
                            <span x-show="theme === 'auto'"><x-icon name="desktop" class="size-5" /></span>
                            <span x-show="theme === 'light'" x-cloak><x-icon name="sun" class="size-5" /></span>
                            <span x-show="theme === 'dark'" x-cloak><x-icon name="moon" class="size-5" /></span>
                            <span class="sr-only">Changer de thème</span>
                        </button>
                        @if ($showHouseholdMenu)
                            <div x-data="{ open: false }" class="relative hidden md:block" x-on:keydown.escape="open = false">
                                <button type="button" x-on:click="open = ! open" class="btn btn-ghost max-w-44 gap-1 px-2 text-sm" title="Foyer : changer de foyer, administration" x-bind:aria-expanded="open">
                                    <x-icon name="users" class="size-5 shrink-0" />
                                    <span class="hidden truncate 2xl:inline">{{ $myHouseholds->firstWhere('id', $activeHouseholdId)?->name ?? 'Foyer' }}</span>
                                    <x-icon name="chevron-down" class="size-3.5 shrink-0" />
                                </button>
                                <div x-show="open" x-cloak x-transition x-on:click.outside="open = false"
                                     class="absolute right-0 z-50 mt-1 w-64 rounded-xl bg-white p-2 shadow-xl ring-1 ring-stone-200">
                                    <p class="px-2 pb-1 text-xs font-medium text-stone-500">Vos foyers</p>
                                    @foreach ($myHouseholds as $h)
                                        <form method="POST" action="{{ route('households.switch', $h->id) }}">
                                            @csrf
                                            <button type="submit" @class(['flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm', 'bg-brand-50 font-medium text-brand-700' => $h->id === $activeHouseholdId, 'text-stone-700 hover:bg-stone-100' => $h->id !== $activeHouseholdId])>
                                                <x-icon :name="$h->id === $activeHouseholdId ? 'check' : 'home'" class="size-4 shrink-0" /> <span class="truncate">{{ $h->name }}</span>
                                            </button>
                                        </form>
                                    @endforeach
                                    <div class="mt-1 border-t border-stone-100 pt-1">
                                        <a href="{{ route('settings.household') }}" wire:navigate class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-stone-700 hover:bg-stone-100"><x-icon name="settings" class="size-4" /> Membres et invitations</a>
                                        <a href="{{ route('linked.index') }}" wire:navigate class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-stone-700 hover:bg-stone-100"><x-icon name="heart" class="size-4" /> Proches</a>
                                        @if (auth()->user()->isAdmin())
                                            <a href="{{ route('admin.households') }}" wire:navigate class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-stone-700 hover:bg-stone-100"><x-icon name="lock" class="size-4" /> Administration</a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endif
                        {{-- Compte (lot 25) : Mon compte, Vos données, déconnexion — un seul bouton pour garder la barre à 1280 px. --}}
                        <div x-data="{ open: false }" class="relative hidden md:block" x-on:keydown.escape="open = false">
                            <button type="button" x-on:click="open = ! open" class="btn btn-ghost gap-1.5 px-2 text-sm" title="Mon compte, déconnexion" x-bind:aria-expanded="open">
                                <x-icon name="user" class="size-5 text-stone-500" />
                                <span class="hidden text-stone-600 2xl:inline">{{ auth()->user()->name }}</span>
                                <span class="sr-only">Mon compte</span>
                            </button>
                            <div x-show="open" x-cloak x-transition x-on:click.outside="open = false"
                                 class="absolute right-0 z-50 mt-1 w-56 rounded-xl bg-white p-2 shadow-xl ring-1 ring-stone-200">
                                <p class="truncate px-2 pb-1 text-xs font-medium text-stone-500">{{ auth()->user()->name }}</p>
                                <a href="{{ route('account.show') }}" wire:navigate class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-stone-700 hover:bg-stone-100"><x-icon name="user" class="size-4" /> Mon compte</a>
                                <a href="{{ route('privacy') }}" wire:navigate class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-stone-700 hover:bg-stone-100"><x-icon name="lock" class="size-4" /> Vos données</a>
                                <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-stone-100 pt-1">
                                    @csrf
                                    <button type="submit" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm text-stone-700 hover:bg-stone-100" title="Se déconnecter">
                                        <x-icon name="logout" class="size-4" /> Se déconnecter
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 print:p-0">
                {{ $slot }}
            </main>
        </div>

        {{-- Barre d'onglets mobile : 4 raccourcis + « Plus » --}}
        <nav wire:transition.navigate="barre" x-data="{ more: false }" x-on:keydown.escape.window="more = false" x-on:livewire:navigating.window="more = false" x-on:open-more.window="more = true"
             class="fixed inset-x-0 bottom-0 z-30 border-t border-stone-200 bg-white pb-[env(safe-area-inset-bottom)] md:hidden print:hidden"
             aria-label="Navigation mobile">
            <div class="grid grid-cols-5 items-end">
                @foreach ($mobileNavigation as $index => $item)
                    @if ($index === 2)
                        {{-- Lot 29 : le « + » au centre de la barre, il ne recouvre plus le contenu. --}}
                        <div class="flex justify-center pb-1.5">
                            <button type="button" x-on:click="Livewire.dispatch('open-quick-add')"
                                    class="-mt-3 flex size-13 items-center justify-center rounded-2xl bg-brand-600 text-white shadow-lg shadow-brand-600/25 transition active:scale-95"
                                    title="Ajouter">
                                <x-icon name="plus" class="size-7" /><span class="sr-only">Ajouter</span>
                            </button>
                        </div>
                    @endif
                    <a href="{{ route($item['route']) }}" wire:navigate
                       @class([
                           'flex min-h-14 flex-col items-center justify-center gap-0.5 py-1.5 text-[11px] font-medium',
                           'text-brand-700' => $isActiveMobile($item['route']),
                           'text-stone-600' => ! $isActiveMobile($item['route']),
                       ])
                       @if ($isActiveMobile($item['route'])) aria-current="page" @endif>
                        <span class="relative">
                            <x-icon :name="$item['icon']" class="size-6" />
                            @if ($item['route'] === 'stock.index' && $stockAlerts['total'] > 0)
                                <span @class(['absolute -top-1 -right-2.5 min-w-4 rounded-full px-1 text-center text-[10px] leading-4 font-bold text-white tabular-nums', 'bg-red-600' => $stockAlerts['urgent'] > 0, 'bg-orange-500' => $stockAlerts['urgent'] === 0])>{{ $stockAlerts['total'] }}</span>
                            @endif
                        </span>
                        <span class="max-w-full truncate px-1">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>

            <div x-show="more" x-cloak class="fixed inset-0 z-40" x-on:click="more = false">
                <div class="absolute inset-0 bg-stone-900/40" x-show="more" x-transition.opacity></div>
            </div>
            <div x-show="more" x-cloak x-transition role="dialog" aria-label="Toutes les sections"
                 class="absolute inset-x-0 bottom-full z-50 max-h-[75vh] overflow-y-auto rounded-t-2xl bg-white p-4 shadow-xl ring-1 ring-stone-200">
                <p class="section-title mb-3">Toutes les sections</p>
                <div class="grid grid-cols-4 gap-2">
                    @foreach ($moreNavigation as $item)
                        <a href="{{ route($item['route']) }}" wire:navigate
                           @class(['flex flex-col items-center gap-1 rounded-xl p-2 text-center text-xs font-medium', 'bg-brand-50 text-brand-700' => $isActive($item['route']), 'text-stone-700 hover:bg-stone-100' => ! $isActive($item['route'])])>
                            <x-icon :name="$item['icon']" class="size-6" />
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
                @if ($showHouseholdMenu)
                    <div class="mt-3 border-t border-stone-100 pt-3">
                        <p class="mb-1 text-xs text-stone-500">Foyer</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($myHouseholds as $h)
                                <form method="POST" action="{{ route('households.switch', $h->id) }}">
                                    @csrf
                                    <button type="submit" @class(['rounded-full px-3 py-1 text-xs font-medium ring-1', 'bg-brand-600 text-white ring-brand-600' => $h->id === $activeHouseholdId, 'text-stone-700 ring-stone-200' => $h->id !== $activeHouseholdId])>{{ $h->name }}</button>
                                </form>
                            @endforeach
                            @if (auth()->user()->isAdmin())
                                <a href="{{ route('admin.households') }}" wire:navigate class="rounded-full px-3 py-1 text-xs font-medium text-stone-700 ring-1 ring-stone-200"><x-icon name="lock" class="inline size-3.5" /> Administration</a>
                            @endif
                        </div>
                    </div>
                @endif
                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-stone-100 pt-3" x-data="{ theme: window.bouffeTheme?.get() ?? 'auto' }">
                    <span class="text-xs text-stone-500">Thème</span>
                    @foreach (['auto' => ['desktop', 'Auto'], 'light' => ['sun', 'Clair'], 'dark' => ['moon', 'Sombre']] as $value => [$icon, $label])
                        <button type="button" x-on:click="theme = '{{ $value }}'; window.bouffeTheme.set(theme)"
                                class="flex items-center gap-1 rounded-full px-2.5 py-1 text-xs ring-1"
                                x-bind:class="theme === '{{ $value }}' ? 'bg-brand-600 text-white ring-brand-600' : 'text-stone-600 ring-stone-200'">
                            <x-icon :name="$icon" class="size-4" /> {{ $label }}
                        </button>
                    @endforeach
                    <a href="{{ route('settings.display') }}" wire:navigate class="ml-auto text-xs font-medium text-brand-700">Personnaliser</a>
                    <a href="{{ route('account.show') }}" wire:navigate class="btn btn-ghost px-2 py-1 text-xs"><x-icon name="user" class="size-4" /> Mon compte</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost px-2 py-1 text-xs"><x-icon name="logout" class="size-4" /> Déconnexion</button>
                    </form>
                </div>
            </div>
        </nav>

        <livewire:layout.global-search />
        <livewire:layout.quick-add />
        <livewire:layout.undo />
        {{-- Retrait du stock au repas mangé : une seule fenêtre pour toutes les pages (planning, accueil, cloche). --}}
        <livewire:stock.meal-stock-dialog />
        {{-- Saisie d'une dépense (lot 22), ouverte depuis le bouton +, le budget ou le planning. --}}
        <livewire:budget.expense-form />

        <script @nonce>
            // Ctrl+K / Cmd+K ou « / » (hors champ de saisie) : recherche globale.
            if (! window.bouffeShortcuts) window.bouffeShortcuts = document.addEventListener('keydown', (event) => {
                const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable;
                if (((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') || (event.key === '/' && ! typing)) {
                    event.preventDefault();
                    window.Livewire?.dispatch('open-search');
                }
            }) || true;
        </script>

        <x-flash />
        <x-install-prompt />

        @include('partials.livewire-hooks')
        @livewireScripts
    </body>
</html>
