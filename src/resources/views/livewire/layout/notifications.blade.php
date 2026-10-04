<div class="relative">
    <button type="button" wire:click="toggle" class="btn btn-ghost relative px-2" title="Rappels et alertes">
        <x-icon name="bell" class="size-5" />
        @if ($this->count > 0)
            <span @class([
                'absolute -top-0.5 -right-0.5 flex min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-bold text-white',
                'bg-red-600' => $this->lateCount > 0,
                'bg-brand-600' => $this->lateCount === 0,
            ])>{{ $this->count }}</span>
        @endif
        <span class="sr-only">Rappels et alertes</span>
    </button>

    @if ($open)
        <div class="fixed inset-0 z-30" wire:click="close"></div>

        <div class="absolute right-0 z-40 mt-2 max-h-[70vh] w-80 overflow-y-auto rounded-xl bg-white shadow-xl ring-1 ring-stone-200 sm:w-96"
             x-data x-on:keydown.escape.window="$wire.close()">
            <div class="flex items-center justify-between border-b border-stone-200 px-4 py-3">
                <h2 class="font-display font-semibold text-stone-900">À ne pas oublier</h2>
                <button type="button" wire:click="close" class="btn btn-ghost -mr-2 px-2" title="Fermer">
                    <x-icon name="close" class="size-4" /><span class="sr-only">Fermer</span>
                </button>
            </div>

            @if ($this->count === 0)
                <p class="px-4 py-6 text-center text-sm text-stone-500">Rien à signaler. Bonne journée !</p>
            @else
                {{-- Repas passés à clôturer (lot 21, R23) --}}
                @if ($this->toClose->isNotEmpty())
                    <div class="flex items-center justify-between border-b border-stone-200 bg-stone-50 px-4 py-2 text-xs font-semibold tracking-wide text-stone-500 uppercase">
                        C'était mangé ?
                        {{-- Lot 37 (37.1) : tout se règle d'un coup sur la page du soir. --}}
                        <a href="{{ route('evening') }}" wire:navigate wire:click="close" class="font-medium tracking-normal text-brand-700 normal-case hover:underline">Page « Ce soir »</a>
                    </div>
                    <ul class="divide-y divide-stone-100">
                        @foreach ($this->toClose as $meal)
                            <li wire:key="tc-{{ $meal->id }}" class="px-4 py-2.5">
                                <p class="truncate text-sm font-medium text-stone-900">{{ $meal->label() }}</p>
                                <p class="text-xs text-stone-500">{{ ucfirst($meal->date->locale('fr')->isoFormat('dddd D MMM')) }} · {{ mb_strtolower($meal->slot?->name ?? '') }}</p>
                                <div class="mt-1.5 flex gap-2">
                                    <button type="button" wire:click="closeEaten({{ $meal->id }})" class="btn btn-secondary px-2 py-1 text-xs">
                                        <x-icon name="check" class="size-3.5" /> Mangé
                                    </button>
                                    <button type="button" wire:click="closeSkipped({{ $meal->id }})" class="btn btn-ghost px-2 py-1 text-xs">Pas mangé</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- Retrait du stock resté en attente (22.2) --}}
                @if ($this->stockPending->isNotEmpty())
                    <div class="border-y border-stone-200 bg-stone-50 px-4 py-2 text-xs font-semibold tracking-wide text-stone-500 uppercase">
                        Stock à régulariser
                    </div>
                    <ul class="divide-y divide-stone-100">
                        @foreach ($this->stockPending as $meal)
                            <li wire:key="sp-{{ $meal->id }}" class="flex items-center gap-2 px-4 py-2">
                                <x-icon name="pantry" class="size-4 shrink-0 text-amber-600" />
                                <span class="min-w-0 flex-1 truncate text-sm text-stone-800">{{ $meal->label() }}</span>
                                <button type="button" wire:click="settleStock({{ $meal->id }})" class="btn btn-secondary px-2 py-1 text-xs">Retirer</button>
                                <button type="button" wire:click="ignoreStock({{ $meal->id }})" class="btn btn-ghost px-1.5 py-1 text-xs" title="Ne rien retirer">
                                    <x-icon name="close" class="size-3.5" /><span class="sr-only">Ne rien retirer</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($this->reminders->isNotEmpty())
                    <ul class="divide-y divide-stone-100">
                        @foreach ($this->reminders as $reminder)
                            <li wire:key="rem-{{ $reminder->id }}" class="px-4 py-3">
                                <div class="flex items-start gap-2">
                                    <x-icon :name="$reminder->type->icon()" @class(['mt-0.5 size-4 shrink-0', 'text-red-600' => $reminder->isLate(), 'text-stone-400' => ! $reminder->isLate()]) />
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-stone-900">{{ $reminder->title }}</p>
                                        @if ($reminder->detail)
                                            <p class="truncate text-xs text-stone-500">{{ $reminder->detail }}</p>
                                        @endif
                                        <p @class(['text-xs', 'font-semibold text-red-600' => $reminder->isLate(), 'text-stone-500' => ! $reminder->isLate()])>
                                            {{ $reminder->due_at->locale('fr')->isoFormat('ddd D MMM [à] HH[h]mm') }}
                                            @if ($reminder->isLate()) · maintenant @endif
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-2 flex gap-2 pl-6">
                                    <button type="button" wire:click="markDone({{ $reminder->id }})" class="btn btn-secondary px-2 py-1 text-xs">
                                        <x-icon name="check" class="size-3.5" /> C'est fait
                                    </button>
                                    <button type="button" wire:click="ignore({{ $reminder->id }})" class="btn btn-ghost px-2 py-1 text-xs">
                                        Ignorer
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- Liste préparée par quelqu'un d'autre (19.1) --}}
                @if ($this->readyLists->isNotEmpty())
                    <div class="flex items-center justify-between border-t border-stone-200 bg-stone-50 px-4 py-2 text-xs font-semibold tracking-wide text-stone-500 uppercase">
                        <span>Courses</span>
                        <button type="button" wire:click="markListsSeen" class="text-[10px] font-medium normal-case text-stone-500 underline">Vu</button>
                    </div>
                    <ul class="divide-y divide-stone-100">
                        @foreach ($this->readyLists as $list)
                            <li wire:key="rl-{{ $list->id }}">
                                <a href="{{ route('shopping.show', $list) }}" wire:navigate wire:click="close" class="flex items-center gap-2 px-4 py-2 hover:bg-stone-50">
                                    <x-icon name="cart" class="size-4 shrink-0 text-brand-600" />
                                    <span class="min-w-0 flex-1 truncate text-sm text-stone-800">
                                        <strong>{{ $list->creator?->name }}</strong> a préparé la liste · {{ $list->items_count }} article{{ $list->items_count > 1 ? 's' : '' }}
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- Restes à planifier (19.1) --}}
                @if ($this->leftovers->isNotEmpty())
                    <div class="border-t border-stone-200 bg-stone-50 px-4 py-2 text-xs font-semibold tracking-wide text-stone-500 uppercase">
                        Restes à planifier
                    </div>
                    <ul class="divide-y divide-stone-100">
                        @foreach ($this->leftovers as $item)
                            <li wire:key="lo-{{ $item->id }}">
                                <a href="{{ route('planner.week') }}" wire:navigate wire:click="close" class="flex items-center gap-2 px-4 py-2 hover:bg-stone-50">
                                    <x-icon name="archive" class="size-4 shrink-0 text-sky-600" />
                                    <span class="min-w-0 flex-1 truncate text-sm text-stone-800">{{ $item->name() }}</span>
                                    <span class="text-xs text-stone-500">{{ $item->quantity ? app(\App\Services\QuantityFormatter::class)->number((float) $item->quantity).' p.' : '' }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- Envies notées par quelqu'un d'autre (18.2) --}}
                @if ($this->newWishes->isNotEmpty())
                    <div class="flex items-center justify-between border-t border-stone-200 bg-stone-50 px-4 py-2 text-xs font-semibold tracking-wide text-stone-500 uppercase">
                        <span>Envies de la maison</span>
                        <button type="button" wire:click="markWishesSeen" class="text-[10px] font-medium normal-case text-stone-500 underline">Vu</button>
                    </div>
                    <ul class="divide-y divide-stone-100">
                        @foreach ($this->newWishes as $wish)
                            <li wire:key="nw-{{ $wish->id }}" class="flex items-center gap-2 px-4 py-2">
                                <x-icon name="star" class="size-4 shrink-0 text-amber-500" />
                                <span class="min-w-0 flex-1 truncate text-sm text-stone-800">
                                    <strong>{{ $wish->user?->name }}</strong> a une envie : {{ $wish->label() }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- Repas mangés sans réaction (18.4) --}}
                @if ($this->awaitingReactions->isNotEmpty())
                    <div class="border-t border-stone-200 bg-stone-50 px-4 py-2 text-xs font-semibold tracking-wide text-stone-500 uppercase">
                        C'était bien ?
                    </div>
                    <ul class="divide-y divide-stone-100">
                        @foreach ($this->awaitingReactions as $meal)
                            <li wire:key="ar-{{ $meal->id }}" class="flex items-center gap-2 px-4 py-2">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm text-stone-800">{{ $meal->eatenRecipe()?->title }}</span>
                                    <span class="text-xs text-stone-500">{{ $meal->date->locale('fr')->isoFormat('ddd D MMM') }} · {{ mb_strtolower($meal->slot?->name ?? '') }}</span>
                                </span>
                                <button type="button" wire:click="react({{ $meal->id }}, 1)" class="btn btn-secondary px-2 py-1 text-xs" title="On a aimé">
                                    <x-icon name="heart" class="size-3.5" /><span class="sr-only">On a aimé</span>
                                </button>
                                <button type="button" wire:click="react({{ $meal->id }}, -1)" class="btn btn-ghost px-2 py-1 text-xs" title="Bof">
                                    <x-icon name="minus" class="size-3.5" /><span class="sr-only">Bof</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- Clôturés automatiquement (R23), annulables --}}
                @if ($this->autoClosed->isNotEmpty())
                    <div class="border-t border-stone-200 bg-stone-50 px-4 py-2 text-xs font-semibold tracking-wide text-stone-500 uppercase">
                        Marqués mangés automatiquement
                    </div>
                    <ul class="divide-y divide-stone-100">
                        @foreach ($this->autoClosed as $meal)
                            <li wire:key="ac-{{ $meal->id }}" class="flex items-center gap-2 px-4 py-2">
                                <span class="min-w-0 flex-1 truncate text-sm text-stone-700">{{ $meal->label() }} <span class="text-xs text-stone-500">· {{ $meal->date->locale('fr')->isoFormat('ddd D') }}</span></span>
                                <button type="button" wire:click="undoAutoClose({{ $meal->id }})" class="btn btn-ghost px-2 py-1 text-xs">Pas mangé, en fait</button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($this->expiring->isNotEmpty())
                    <div class="border-t border-stone-200 bg-stone-50 px-4 py-2 text-xs font-semibold tracking-wide text-stone-500 uppercase">
                        À consommer
                    </div>
                    <ul class="divide-y divide-stone-100">
                        @foreach ($this->expiring as $row)
                            <li wire:key="exp-{{ $row['item']->id }}" class="flex items-center gap-2 px-4 py-2">
                                <x-icon name="pantry" class="size-4 shrink-0 text-stone-400" />
                                <span class="min-w-0 flex-1 truncate text-sm text-stone-800">{{ $row['item']->name() }}</span>
                                <span class="text-xs text-stone-500">{{ $row['badge']['text'] ?? '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <a href="{{ route('stock.index', ['filtre' => 'alertes']) }}" wire:navigate wire:click="close"
                       class="block border-t border-stone-200 px-4 py-2 text-center text-sm font-medium text-brand-700 hover:bg-stone-50">
                        Voir le stock
                    </a>
                @endif
            @endif
        </div>
    @endif
</div>
