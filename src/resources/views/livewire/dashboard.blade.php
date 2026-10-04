<div>
    {{-- Pastille de l'icône (37.1) : le nombre de repas à clôturer. --}}
    <span hidden data-app-badge="{{ $toClose->count() }}" wire:key="app-badge-{{ $toClose->count() }}" x-data x-init="document.dispatchEvent(new Event('bouffe-badge'))"></span>
    <x-page-header :title="$this->greeting().' '.auth()->user()->name" :subtitle="'Aujourd\'hui · '.$today->locale('fr')->isoFormat('dddd D MMMM YYYY')">
        <x-slot:actions>
            <a href="{{ route('kitchen') }}" class="btn btn-ghost hidden text-sm md:inline-flex" title="Pour une tablette posée en cuisine"><x-icon name="desktop" class="size-4" /> Écran de cuisine</a>
            <a href="{{ route('settings.display') }}#accueil" wire:navigate class="btn btn-ghost hidden text-sm sm:inline-flex"><x-icon name="squares" class="size-4" /> Personnaliser</a>
            <a href="{{ route('recipes.create') }}" wire:navigate class="btn btn-secondary hidden md:inline-flex"><x-icon name="plus" class="size-4" /> Nouvelle recette</a>
        </x-slot:actions>
    </x-page-header>

    @if ($backupWarning)
        <a href="{{ route('settings.backups') }}" wire:navigate class="mb-6 flex items-center gap-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200 hover:bg-amber-100">
            <x-icon name="warning" class="size-5 shrink-0" />
            <span class="flex-1">{{ $backupWarning }}</span>
            <span class="font-medium whitespace-nowrap">Sauvegardes <x-icon name="chevron-right" class="inline size-4" /></span>
        </a>
    @endif

    @if ($yearReview)
        <a href="{{ route('planner.year', $yearReview) }}" wire:navigate class="mb-6 flex items-center gap-3 rounded-xl bg-brand-50 p-3 text-sm text-brand-900 ring-1 ring-brand-200 hover:bg-brand-100" data-year-banner>
            <x-icon name="star" class="size-5 shrink-0" />
            <span class="flex-1"><strong>L'année {{ $yearReview }} en cuisine</strong> est prête : vos classiques, vos découvertes, vos habitudes.</span>
            <x-icon name="chevron-right" class="size-4" />
        </a>
    @endif

    {{-- Repas passés à clôturer (lot 21, R23) : le stock ne suit que les repas marqués mangés. --}}
    @if ($toClose->isNotEmpty())
        <section class="card mb-6 p-4" wire:key="home-to-close">
            <h2 class="font-display flex items-center gap-2 font-semibold text-stone-900">
                <x-icon name="check" class="size-5 text-herb-600" /> C'était mangé ?
            </h2>
            <p class="mb-2 text-sm text-stone-500">Pour que le stock suive : un geste par repas.</p>
            {{-- Lot 37 (37.2) : les repas d'hier d'un seul geste, stock compris ; « Annuler » pendant 10 secondes. --}}
            @if ($yesterdayCount >= 2 && auth()->user()->canEdit())
                <div class="mb-2 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl bg-herb-50 px-3 py-2.5 ring-1 ring-herb-100" data-yesterday>
                    <button type="button" wire:click="closeYesterday" wire:loading.attr="disabled" class="btn btn-secondary min-h-11 text-herb-800">
                        <x-icon name="check" class="size-5" /> Hier comme prévu
                    </button>
                    <span class="min-w-0 flex-1 basis-48 text-sm text-herb-900">Les {{ $yesterdayCount }} repas d'hier sont marqués mangés et le stock mis à jour.</span>
                </div>
            @endif
            <ul class="divide-y divide-stone-100">
                @foreach ($toClose->take(4) as $meal)
                    {{-- Lot 28 (E3, 28.5) : le nom du plat garde la place ; ✓ / ✕ compacts, libellés dès que l'écran le permet. --}}
                    <li wire:key="home-tc-{{ $meal->id }}" class="flex items-center gap-2 py-2">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-stone-800">{{ $meal->label() }}</span>
                            <span class="block truncate text-xs text-stone-500">{{ ucfirst($meal->date->locale('fr')->isoFormat('ddd D MMM')) }} · {{ mb_strtolower($meal->slot?->name ?? '') }}</span>
                        </span>
                        <button type="button" wire:click="closeEaten({{ $meal->id }})" class="btn btn-secondary min-h-11 min-w-11 px-2.5 text-herb-700 sm:px-3" title="Mangé">
                            <x-icon name="check" class="size-5" /><span class="sr-only sm:not-sr-only">Mangé</span>
                        </button>
                        <button type="button" wire:click="closeSkipped({{ $meal->id }})" class="btn btn-ghost min-h-11 min-w-11 px-2.5 sm:px-3" title="Pas mangé">
                            <x-icon name="close" class="size-5" /><span class="sr-only sm:not-sr-only">Pas mangé</span>
                        </button>
                    </li>
                @endforeach
            </ul>
            @if ($toClose->count() > 2)
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-stone-100 pt-2 text-sm text-stone-500">
                    <span>
                        @if ($toClose->count() > 4)
                            <a href="{{ route('evening') }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $toClose->count() - 4 }} autre{{ $toClose->count() - 4 > 1 ? 's' : '' }}</a>.
                        @endif
                        Stock déjà à jour ?
                    </span>
                    <button type="button" wire:click="closeAllWithoutStock" wire:confirm="Marquer les {{ $toClose->count() }} repas comme mangés, sans rien retirer du stock ?" class="btn btn-ghost px-2 py-1 text-sm">
                        Tout marquer mangé, sans toucher au stock
                    </button>
                </div>
            @endif
        </section>
    @endif

    <div class="gap-6 lg:columns-2">
        @foreach ($sections as $section)
            @switch($section)
                {{-- ==================================================== Au menu --}}
                @case('menu')
                    <x-home.section key="menu" title="Au menu aujourd'hui" icon="calendar" :link="route('planner.week')" link-label="Voir la semaine" wire:key="home-menu">
                        @php $primaryUsed = false; /* lot 29 : un seul bouton plein, celui du prochain repas à cuisiner */ @endphp
                        <ul class="divide-y divide-stone-100">
                            @forelse ($mealSlots as $slot)
                                @php $meals = $todayMeals->get($slot->id, collect()); $occasion = $todayOccasions->get($slot->id); @endphp
                                <li wire:key="today-{{ $slot->id }}" class="py-3 first:pt-0 last:pb-0">
                                    <p class="mb-1 flex items-center gap-2 text-xs font-semibold tracking-wide text-stone-500 uppercase">
                                        {{ $slot->name }}
                                        @if ($occasion)
                                            <span class="flex min-w-0 items-center gap-1 font-medium tracking-normal text-violet-700 normal-case">
                                                <x-icon name="users" class="size-3.5 shrink-0" />{{ app(\App\Services\Planning\OccasionService::class)->summary($occasion) }}
                                                @if ($occasion->title) <span class="truncate">· {{ $occasion->title }}</span> @endif
                                            </span>
                                        @endif
                                    </p>
                                    {{-- Plusieurs plats (entrée, plat, dessert) : un seul mode cuisine, étapes entrelacées (31.3). --}}
                                    @if ($meals->filter(fn ($m) => ! $m->isFree() && $m->eatenRecipe() && ! $m->cooked_at)->count() >= 2)
                                        <a href="{{ route('planner.cook', ['date' => $today->toDateString(), 'slot' => $slot->id]) }}" wire:navigate
                                           class="mb-1 inline-flex min-h-10 items-center gap-1.5 text-sm font-medium text-brand-700 hover:underline">
                                            <x-icon name="fire" class="size-4" /> Cuisiner tout le repas, étapes entrelacées
                                        </a>
                                    @endif
                                    @forelse ($meals as $meal)
                                        @php $recipe = $meal->eatenRecipe(); @endphp
                                        <div wire:key="today-meal-{{ $meal->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 py-1.5">
                                            @if ($recipe?->photo_path)
                                                <img src="{{ $recipe->photoUrl('thumb') }}" alt="" class="size-14 shrink-0 rounded-xl object-cover" loading="lazy">
                                            @elseif ($recipe)
                                                <x-dish-illustration :recipe="$recipe" :course="$meal->course" class="size-14 rounded-xl" />
                                            @endif
                                            <div class="min-w-0 flex-1 basis-40">
                                                @if ($recipe)
                                                    <a href="{{ route('recipes.show', ['recipe' => $recipe, 'repas' => $meal->id]) }}" wire:navigate @class(['font-semibold hover:text-brand-700', 'text-stone-900' => ! $meal->cooked_at, 'text-stone-500' => $meal->cooked_at])>
                                                        {{ $meal->isLeftover() ? 'Restes : '.$recipe->title : $recipe->title }}
                                                    </a>
                                                    <span class="text-xs text-stone-500">· {{ \App\Services\Planning\Appetites::label($meal->servings) }}</span>
                                                @else
                                                    <span class="font-medium text-stone-700 italic">{{ $meal->label() }}</span>
                                                @endif
                                                @if ($meal->comment) <p class="text-xs text-stone-500">{{ $meal->comment }}</p> @endif
                                            </div>
                                            <div class="flex items-center gap-2">
                                            @if ($recipe && ! $meal->cooked_at && $meal->isRecipe())
                                                <a href="{{ route('recipes.cook', ['recipe' => $recipe, 'portions' => $meal->servings, 'repas' => $meal->id]) }}" wire:navigate
                                                   @class(['btn min-h-10 px-3 py-1.5', 'btn-primary' => ! $primaryUsed, 'btn-secondary' => $primaryUsed])>
                                                    <x-icon name="fire" class="size-4" /> Cuisiner
                                                </a>
                                                @php $primaryUsed = true; @endphp
                                            @endif
                                            <button type="button" wire:click="toggleCooked({{ $meal->id }})"
                                                    @class(['flex min-h-10 items-center gap-1 rounded-lg px-2.5 py-1 text-sm font-medium ring-1 transition', 'bg-herb-50 text-herb-800 ring-herb-200' => $meal->cooked_at, 'text-stone-600 ring-stone-300 hover:text-stone-900' => ! $meal->cooked_at])
                                                    title="{{ $meal->cooked_at ? 'Mangé' : 'Marquer comme mangé' }}">
                                                <x-icon :name="$meal->cooked_at ? 'success' : 'check'" class="size-4" /> {{ $meal->cooked_at ? 'Mangé' : 'Mangé ?' }}
                                            </button>
                                            </div>
                                        </div>
                                    @empty
                                        <a href="{{ route('planner.week', ['ajouter' => $today->toDateString(), 'creneau' => $slot->id]) }}" wire:navigate class="inline-flex min-h-10 items-center gap-1 text-sm text-stone-600 hover:text-brand-700"><x-icon name="plus" class="size-4" /> Rien de prévu — planifier</a>
                                    @endforelse
                                </li>
                            @empty
                                <li class="text-sm text-stone-500">Aucun créneau actif.</li>
                            @endforelse
                        </ul>
                        @if ($tomorrowMeals->isNotEmpty())
                            <p class="mt-3 border-t border-stone-100 pt-3 text-sm text-stone-500">
                                <span class="font-medium text-stone-700">Demain :</span>
                                {{ $tomorrowMeals->map(fn ($m) => mb_strtolower($m->slot->name).' — '.$m->label())->join(' · ') }}
                            </p>
                        @endif
                    </x-home.section>
                    @break

                {{-- ==================================================== Restes --}}
                @case('leftovers')
                    {{-- Gamelles du lendemain, à préparer ce soir (lot 32, 32.3). --}}
                    @if ($lunchboxes->isNotEmpty())
                        <x-home.section key="lunchboxes" title="Gamelles à préparer ce soir" icon="lunchbox" :count="$lunchboxes->count()" wire:key="home-lunchboxes">
                            <ul class="divide-y divide-stone-100">
                                @foreach ($lunchboxes as $box)
                                    <li wire:key="lunchbox-{{ $box->id }}" class="py-2.5 first:pt-0 last:pb-0">
                                        <p class="font-medium text-stone-900">{{ $box->lunchboxLabel() }} · {{ $box->eatenRecipe()?->title ?? $box->leftoverOf?->free_text }}</p>
                                        <p class="text-xs text-stone-500">
                                            {{ \App\Services\Planning\Appetites::label($box->servings) }} pour demain{{ $box->slot ? ', '.mb_strtolower($box->slot->name) : '' }}
                                            · restes du {{ app(\App\Services\Planning\Lunchboxes::class)->origin($box) }}
                                        </p>
                                    </li>
                                @endforeach
                            </ul>
                            <a href="{{ route('planner.lunchboxes', ['du' => $tomorrow->toDateString(), 'au' => $tomorrow->toDateString()]) }}" target="_blank" rel="noopener"
                               class="mt-3 inline-flex min-h-10 items-center gap-1.5 text-sm font-medium text-brand-700 hover:underline">
                                <x-icon name="printer" class="size-4" /> Imprimer les étiquettes
                            </a>
                        </x-home.section>
                    @endif

                    @if ($leftovers->isNotEmpty())
                        <x-home.section key="leftovers" title="Restes à finir" icon="archive" :count="$leftovers->count()" wire:key="home-leftovers">
                            <ul class="divide-y divide-stone-100">
                                @foreach ($leftovers as $row)
                                    @php $badge = $row['item'] ? app(\App\Services\Stock\ExpiryCalculator::class)->badge($row['item']) : null; @endphp
                                    <li wire:key="leftover-{{ $row['meal']?->id ?? 'i'.$row['item']->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5 first:pt-0 last:pb-0">
                                        <div class="min-w-0 flex-1">
                                            <p class="flex flex-wrap items-center gap-2">
                                                <span class="font-medium text-stone-900">{{ $row['title'] }}</span>
                                                @if ($badge) <x-badge :color="$badge['color']">{{ $badge['text'] }}</x-badge> @endif
                                            </p>
                                            <p class="text-xs text-stone-500">
                                                @if ($row['remaining'] > 0)
                                                    {{ \App\Services\Planning\Appetites::label($row['remaining']) }} à placer · {{ $row['meal']->date->locale('fr')->isoFormat('dddd') }}
                                                @endif
                                                @if ($row['item'])
                                                    @if ($row['remaining'] > 0) · @endif
                                                    {{ $row['item']->location->name }}
                                                @endif
                                            </p>
                                        </div>
                                        @if ($row['meal'] && $row['remaining'] > 0)
                                            <button type="button" wire:click="placeLeftovers({{ $row['meal']->id }})" class="btn btn-secondary px-2.5 py-1 text-xs">
                                                <x-icon name="calendar" class="size-3.5" /> Placer demain
                                            </button>
                                        @else
                                            <a href="{{ route('stock.index', ['q' => $row['title']]) }}" wire:navigate class="btn btn-ghost px-2.5 py-1 text-xs">Voir au stock</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </x-home.section>
                    @endif
                    @break

                {{-- ==================================================== À consommer --}}
                @case('expiring')
                    <div class="mb-6 break-inside-avoid" wire:key="home-expiring">
                        <livewire:stock.expiry-alerts-card />
                    </div>
                    @break

                {{-- ==================================================== Courses --}}
                @case('shopping')
                    <x-home.section key="shopping" title="Courses" icon="cart" :link="route('shopping.index')" link-label="Toutes les listes" wire:key="home-shopping">
                        @if ($activeList)
                            <p class="mb-2 truncate text-sm text-stone-600">{{ $activeList->name }}</p>
                            <x-shopping.progress :checked="$activeList->checked_count" :total="$activeList->total_count" />
                            <a href="{{ route('shopping.show', $activeList) }}" wire:navigate class="btn btn-secondary mt-3 w-full">Ouvrir la liste</a>
                        @else
                            <p class="mb-3 text-sm text-stone-500">Aucune liste en cours.</p>
                            <a href="{{ route('shopping.index', ['generer' => now()->toDateString()]) }}" wire:navigate class="btn btn-secondary w-full">Générer la liste</a>
                        @endif
                    </x-home.section>
                    @break

                {{-- ==================================================== Semaine --}}
                @case('week')
                    <x-home.section key="week" title="Cette semaine" icon="calendar" wire:key="home-week">
                        <div class="flex items-end justify-between gap-3">
                            <p class="text-3xl font-bold text-stone-900 tabular-nums">{{ $filledCells }}<span class="text-lg font-medium text-stone-500"> / {{ $totalCells }}</span> <span class="text-sm font-normal text-stone-500">repas planifiés</span></p>
                        </div>
                        <div class="mt-3 grid grid-cols-7 gap-1.5" aria-label="Repas planifiés par jour">
                            @foreach ($weekDays as $day)
                                @php $full = $mealSlots->count() > 0 && $day['filled'] >= $mealSlots->count(); @endphp
                                <a href="{{ route('planner.week', ['semaine' => $weekStart->toDateString()]) }}" wire:navigate
                                   @class(['flex flex-col items-center gap-1 rounded-lg py-1.5 text-xs', 'bg-brand-50 font-semibold text-brand-700 ring-1 ring-brand-200' => $day['today'], 'text-stone-500 hover:bg-stone-100' => ! $day['today']])
                                   title="{{ ucfirst($day['date']->locale('fr')->isoFormat('dddd')) }} : {{ $day['filled'] }} / {{ $mealSlots->count() }}">
                                    {{ mb_substr($day['date']->locale('fr')->isoFormat('dd'), 0, 2) }}
                                    <span class="flex gap-0.5">
                                        @foreach ($mealSlots as $i => $slot)
                                            <span @class(['size-1.5 rounded-full', 'bg-brand-500' => $i < $day['filled'], 'bg-stone-200' => $i >= $day['filled']])></span>
                                        @endforeach
                                    </span>
                                </a>
                            @endforeach
                        </div>
                        <a href="{{ route('planner.week') }}" wire:navigate class="btn btn-secondary mt-4 w-full">
                            <x-icon name="calendar" class="size-4" /> {{ $filledCells < $totalCells ? 'Compléter le planning' : 'Voir le planning' }}
                        </a>
                    </x-home.section>
                    @break

                {{-- ==================================================== Rappels (14.6) --}}
                @case('reminders')
                    @if ($reminders->isNotEmpty())
                        <x-home.section key="reminders" title="À préparer à l'avance" icon="clock" :count="$reminders->count()" wire:key="home-reminders">
                            <ul class="divide-y divide-stone-100">
                                @foreach ($reminders as $reminder)
                                    <li wire:key="home-rem-{{ $reminder->id }}" class="flex items-start gap-2 py-2">
                                        <x-icon :name="$reminder->type->icon()" @class(['mt-0.5 size-4 shrink-0', 'text-red-600' => $reminder->isLate(), 'text-stone-400' => ! $reminder->isLate()]) />
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-medium text-stone-900">{{ $reminder->title }}</p>
                                            <p class="truncate text-xs text-stone-500">{{ $reminder->detail }}</p>
                                        </div>
                                        <button type="button" wire:click="reminderDone({{ $reminder->id }})" class="btn btn-ghost px-1.5" title="C'est fait">
                                            <x-icon name="check" class="size-4" /><span class="sr-only">Fait</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </x-home.section>
                    @endif
                    @break

                {{-- ==================================================== Envies (14.5) --}}
                @case('wishes')
                    <div wire:key="home-wishes" class="mb-6 break-inside-avoid">
                        <livewire:planner.wish-box />
                    </div>
                    @break

                {{-- ==================================================== Fil des proches (26.10) --}}
                @case('linked')
                    @if ($linkedFeed->isNotEmpty())
                        <x-home.section key="linked" title="Chez les proches" icon="heart" :link="route('linked.index')" link-label="Proches" wire:key="home-linked">
                            <ul class="space-y-2">
                                @foreach ($linkedFeed as $item)
                                    <li wire:key="feed-{{ $loop->index }}" class="flex items-start gap-2 text-sm">
                                        <x-icon :name="$item['icon']" class="mt-0.5 size-4 shrink-0 text-stone-400" />
                                        <div class="min-w-0 flex-1">
                                            @if ($item['url'])
                                                <a href="{{ $item['url'] }}" wire:navigate class="font-medium text-stone-900 hover:text-brand-700">{{ $item['text'] }}</a>
                                            @else
                                                <span class="font-medium text-stone-900">{{ $item['text'] }}</span>
                                            @endif
                                            @if ($item['detail']) <p class="truncate text-xs text-stone-500">{{ $item['detail'] }}</p> @endif
                                        </div>
                                        <span class="shrink-0 text-xs text-stone-500">{{ $item['at']?->locale('fr')->diffForHumans(short: true) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </x-home.section>
                    @endif
                    @break

                {{-- ==================================================== Hausses de prix (lot 27, C2) --}}
                @case('prices')
                    @if ($priceRises->isNotEmpty())
                        <x-home.section key="prices" title="Hausses de prix" icon="trending-up" :link="route('prices.index', ['vue' => 'evolution'])" link-label="Nos prix" wire:key="home-prices">
                            <ul class="space-y-2">
                                @foreach ($priceRises as $rise)
                                    <li wire:key="rise-{{ $loop->index }}" class="flex items-baseline gap-2 text-sm">
                                        <div class="min-w-0 flex-1">
                                            <span class="font-medium text-stone-900">{{ $rise['ingredient']->name }}</span>
                                            <p class="truncate text-xs text-stone-500">{{ $rise['store']?->name ?? 'Magasin non précisé' }} · {{ $rise['label_before'] }} → {{ $rise['label_after'] }}</p>
                                        </div>
                                        <span class="shrink-0 font-semibold text-amber-800 tabular-nums">+{{ number_format($rise['change'] * 100, 0, ',', '') }}&nbsp;%</span>
                                    </li>
                                @endforeach
                            </ul>
                        </x-home.section>
                    @endif
                    @break

                {{-- ==================================================== Idées --}}
                @case('ideas')
                    @if ($ideas->isNotEmpty())
                        <x-home.section key="ideas" title="Ça fait longtemps…" icon="sparkles" :link="route('recipes.index', ['tri' => 'forgotten'])" link-label="Plus d'idées" wire:key="home-ideas">
                            <div class="space-y-2">
                                @foreach ($ideas as $idea)
                                    <a wire:key="idea-{{ $idea->id }}" href="{{ route('recipes.show', $idea) }}" wire:navigate class="group flex items-center gap-3 rounded-lg p-1.5 transition hover:bg-stone-50">
                                        @if ($idea->photoUrl())
                                            <img src="{{ $idea->photoUrl() }}" alt="" class="size-12 shrink-0 rounded-lg object-cover" loading="lazy">
                                        @else
                                            <div class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-400"><x-icon name="recipes" class="size-5" /></div>
                                        @endif
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate font-semibold text-stone-900 group-hover:text-brand-700">{{ $idea->title }}</p>
                                            <p class="text-xs text-stone-500">
                                                {{ $idea->last_planned_on ? 'Dernière fois '.\Illuminate\Support\Carbon::parse($idea->last_planned_on)->locale('fr')->diffForHumans() : 'Jamais planifiée' }}
                                            </p>
                                        </div>
                                        <x-icon name="chevron-right" class="size-4 shrink-0 text-stone-400" />
                                    </a>
                                @endforeach
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2 border-t border-stone-100 pt-3 text-sm">
                                <a href="{{ route('suggestions') }}" wire:navigate class="font-medium text-brand-700 hover:underline">Que cuisiner avec le stock ?</a>
                                <span class="text-stone-300">·</span>
                                <a href="{{ route('guests.index') }}" wire:navigate class="text-stone-600 hover:underline">Invités</a>
                            </div>
                        </x-home.section>
                    @endif
                    @break
            @endswitch
        @endforeach
    </div>

    @if ($sections === [])
        <div class="card"><x-empty-state icon="home" title="Accueil vide">Tous les blocs sont masqués. <a href="{{ route('settings.display') }}#accueil" wire:navigate class="text-brand-700 underline">Les réafficher</a></x-empty-state></div>
    @endif

</div>
