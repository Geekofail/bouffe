<div>
    <x-page-header :title="($occasion->title ?: 'Repas en commun').' — '.$host?->name" :subtitle="ucfirst($occasion->date->locale('fr')->isoFormat('dddd D MMMM YYYY')).($slotName ? ' · '.$slotName : '').' · '.$serveAt->format('H:i')" />
    <x-linked-nav />

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- ============================================================ Réponse --}}
        <section class="card space-y-4 p-4 sm:p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-display font-semibold text-stone-900">Votre réponse</h2>
                <span @class(['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1',
                    'bg-amber-50 text-amber-800 ring-amber-200' => $row->status === 'invited',
                    'bg-emerald-50 text-emerald-800 ring-emerald-200' => $row->status === 'accepted',
                    'bg-stone-100 text-stone-700 ring-stone-200' => $row->status === 'declined'])>
                    <x-icon :name="['invited' => 'envelope', 'accepted' => 'check', 'declined' => 'close'][$row->status]" class="size-3.5" />
                    {{ $row->status === 'invited' ? 'Pas encore répondu' : \App\Models\MealOccasionHousehold::STATUSES[$row->status] }}
                </span>
            </div>
            @if ($canEdit)
                <div class="grid gap-3 sm:grid-cols-[8rem_1fr]">
                    <x-field label="Nous serons" for="sm-people" error="people">
                        <input id="sm-people" type="number" min="1" max="50" wire:model="people" class="form-input">
                    </x-field>
                    <x-field label="Un mot" for="sm-message" error="message" optional>
                        <input id="sm-message" type="text" maxlength="255" wire:model="message" class="form-input" placeholder="« On arrive vers 19 h »">
                    </x-field>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="respond(true)" class="btn btn-primary"><x-icon name="check" class="size-4" /> Nous venons</button>
                    <button type="button" wire:click="respond(false)" class="btn btn-secondary">Nous ne venons pas</button>
                </div>
            @endif

            <div class="border-t border-stone-100 pt-3 text-sm">
                <h3 class="mb-1 font-medium text-stone-900">Contraintes alimentaires transmises</h3>
                <ul class="space-y-1 text-stone-600">
                    @foreach ($sharing as $eater)
                        <li class="flex gap-2">
                            <x-icon :name="$eater->shared ? 'check' : 'lock'" class="mt-0.5 size-4 shrink-0 {{ $eater->shared ? 'text-emerald-700' : 'text-stone-400' }}" />
                            <span>
                                <strong class="font-medium text-stone-800">{{ \Illuminate\Support\Str::before($eater->name, ' (') }}</strong> :
                                @if (! $eater->shared)
                                    non partagées (Mon compte → « Partager mes contraintes »)
                                @elseif ($eater->all->isEmpty())
                                    aucune
                                @else
                                    {{ $eater->all->map(fn ($r) => $r->type->label().' : '.$r->subject())->join(', ') }}
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        {{-- ============================================================ Menu --}}
        <section class="card p-4 sm:p-5">
            <h2 class="font-display mb-3 font-semibold text-stone-900">Au menu</h2>
            <ul class="space-y-1.5 text-sm">
                @forelse ($hostMenu as $meal)
                    <li class="flex gap-2"><x-icon name="home" class="mt-0.5 size-4 shrink-0 text-stone-400" /> <span>{{ $meal->course?->label() ? $meal->course->label().' : ' : '' }}{{ $meal->label() }} <span class="text-stone-500">— {{ $host?->name }}</span></span></li>
                @empty
                    <li class="text-stone-500">{{ $host?->name }} n'a pas encore prévu ses plats.</li>
                @endforelse
                @foreach ($others as $meal)
                    <li class="flex gap-2"><x-icon name="users" class="mt-0.5 size-4 shrink-0 text-stone-400" /> <span>{{ $meal->course?->label() ? $meal->course->label().' : ' : '' }}{{ $meal->label() }} <span class="text-stone-500">— {{ $meal->household?->name }}</span></span></li>
                @endforeach
            </ul>

            <h3 class="mt-5 mb-2 font-medium text-stone-900">Ce que nous apportons</h3>
            <ul class="divide-y divide-stone-100 text-sm">
                @forelse ($mine as $meal)
                    <li wire:key="mine-{{ $meal->id }}" class="flex items-center gap-2 py-2">
                        <span class="min-w-0 flex-1">{{ $meal->course?->label() ? $meal->course->label().' : ' : '' }}<a href="{{ route('recipes.show', $meal->recipe) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $meal->label() }}</a> · {{ \App\Services\Planning\Appetites::label($meal->servings) }}</span>
                        @if ($canEdit)
                            <button type="button" wire:click="removeDish({{ $meal->id }})" wire:confirm="Retirer ce plat (et de votre planning) ?" class="text-stone-400 hover:text-red-700" title="Retirer"><x-icon name="close" class="size-4" /><span class="sr-only">Retirer</span></button>
                        @endif
                    </li>
                @empty
                    <li class="py-2 text-stone-500">Rien pour l'instant.</li>
                @endforelse
            </ul>

            @if ($canEdit && $row->isAccepted())
                <form wire:submit="bring" class="mt-4 grid gap-3 rounded-lg bg-stone-50 p-3 sm:grid-cols-2">
                    <x-field label="Recette de notre carnet" for="sm-recipe" error="recipeId" class="sm:col-span-2">
                        <select id="sm-recipe" wire:model="recipeId" class="form-input">
                            <option value="">Choisir…</option>
                            @foreach ($recipes as $recipe)
                                <option value="{{ $recipe->id }}">{{ $recipe->title }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field label="Dans le menu" for="sm-course" optional>
                        <select id="sm-course" wire:model="course" class="form-input">
                            <option value="">—</option>
                            @foreach ($courses as $c)
                                <option value="{{ $c->value }}">{{ $c->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field label="Portions" for="sm-servings" error="servings" optional help="Par défaut : le nombre de convives.">
                        <input id="sm-servings" type="number" min="0.5" step="0.5" max="50" wire:model="servings" class="form-input">
                    </x-field>
                    <div class="sm:col-span-2"><button type="submit" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Apporter ce plat</button></div>
                </form>
            @elseif ($canEdit)
                <p class="mt-3 text-sm text-stone-500">Répondez « Nous venons » pour choisir ce que vous apportez.</p>
            @endif
        </section>
    </div>

    {{-- Lot 42 (42.2) : qui apporte quoi, partagé avec le foyer qui reçoit et les autres invités. --}}
    @if ($row->status !== 'declined')
        <div class="mt-6">
            <livewire:contributions.board subject="occasion" :subject-id="$occasion->id" :key="'board-shared-'.$occasion->id" />
        </div>
    @endif
</div>
