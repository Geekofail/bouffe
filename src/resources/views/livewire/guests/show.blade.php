<div>
    <a href="{{ route('guests.index') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Invités
    </a>

    <x-page-header :title="$guest->name" :subtitle="collect([$guest->group_name, $guest->is_child ? 'Enfant' : null, $guest->isArchived() ? 'Archivé' : null])->filter()->join(' · ') ?: null">
        <x-slot:actions>
            <button type="button" wire:click="edit" class="btn btn-secondary"><x-icon name="edit" class="size-4" /> Modifier</button>
            <button type="button" wire:click="toggleArchive" class="btn btn-ghost" title="{{ $guest->isArchived() ? 'Sortir des archives' : 'Archiver' }}">
                <x-icon :name="$guest->isArchived() ? 'unarchive' : 'archive'" class="size-4" />
                <span class="sr-only sm:not-sr-only">{{ $guest->isArchived() ? 'Désarchiver' : 'Archiver' }}</span>
            </button>
            @if ($history->isEmpty())
                <button type="button" wire:click="delete" wire:confirm="Supprimer « {{ $guest->name }} » du carnet ?" class="btn btn-ghost hover:text-red-600" title="Supprimer">
                    <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer</span>
                </button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <aside class="space-y-4">
            <section class="card p-4">
                <h2 class="font-display mb-3 font-semibold text-stone-900">Contraintes alimentaires</h2>
                @if ($guest->restrictions->isEmpty())
                    <p class="text-sm text-stone-500">Aucune contrainte connue.</p>
                @else
                    <ul class="space-y-2 text-sm">
                        @foreach ($guest->restrictions->sortBy(fn ($r) => array_search($r->type, \App\Enums\RestrictionType::cases())) as $restriction)
                            <li wire:key="r-{{ $restriction->id }}" class="flex flex-wrap items-center gap-2">
                                <x-badge :color="$restriction->type->color()">{{ $restriction->type->shortLabel() }}</x-badge>
                                <span class="font-medium text-stone-800">{{ $restriction->subject() }}</span>
                                @if ($restriction->note) <span class="text-stone-500">— {{ $restriction->note }}</span> @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if ($guest->notes)
                <section class="card p-4">
                    <h2 class="font-display mb-2 font-semibold text-stone-900">Notes</h2>
                    <p class="text-sm whitespace-pre-line text-stone-600">{{ $guest->notes }}</p>
                </section>
            @endif
        </aside>

        <section class="card min-w-0 lg:col-span-2">
            <div class="border-b border-stone-200 px-4 py-3">
                <h2 class="font-display font-semibold text-stone-900">Repas partagés <span class="font-normal text-stone-500">· {{ $history->count() }}</span></h2>
            </div>

            @if ($history->isEmpty())
                <x-empty-state icon="calendar" title="Pas encore de repas ensemble">
                    Dans le planning, cliquez sur 👥 dans une case pour ajouter {{ $guest->name }} aux convives.
                </x-empty-state>
            @else
                <ul class="divide-y divide-stone-100">
                    @foreach ($history as $row)
                        @php $occasion = $row['occasion']; @endphp
                        <li wire:key="h-{{ $occasion->id }}" class="flex flex-wrap items-start gap-x-4 gap-y-1 px-4 py-3">
                            <a href="{{ route('planner.week', ['semaine' => $occasion->date->copy()->startOfWeek()->toDateString()]) }}" wire:navigate class="w-36 shrink-0 text-sm">
                                <span class="block font-medium text-stone-900 hover:text-brand-700">{{ ucfirst($occasion->date->locale('fr')->isoFormat('ddd D MMM YYYY')) }}</span>
                                <span class="text-xs text-stone-500">{{ $occasion->slot->name }}</span>
                            </a>
                            <div class="min-w-0 flex-1 text-sm">
                                <p class="font-medium text-violet-800">
                                    <a href="{{ route('receptions.show', $occasion) }}" wire:navigate class="hover:underline">{{ $occasion->title ?: app(\App\Services\Receptions\Receptions::class)->name($occasion) }}</a>
                                </p>
                                @if ($row['meals']->isEmpty())
                                    <p class="text-stone-500 italic">Menu non renseigné</p>
                                @else
                                    <p class="text-stone-700">
                                        @foreach ($row['meals'] as $meal)
                                            @if ($meal->eatenRecipe())
                                                <a href="{{ route('recipes.show', $meal->eatenRecipe()) }}" wire:navigate class="hover:text-brand-700 hover:underline">{{ $meal->label() }}</a>
                                            @else
                                                <span class="italic">{{ $meal->label() }}</span>
                                            @endif
                                            @unless ($loop->last) · @endunless
                                        @endforeach
                                    </p>
                                @endif
                                {{-- Souvenir de la réception (21.4) --}}
                                @if ($occasion->memory_note)
                                    <p class="mt-1 text-stone-600 italic">« {{ \Illuminate\Support\Str::limit($occasion->memory_note, 200) }} »</p>
                                @endif
                            </div>
                            @if ($photo = app(\App\Services\Receptions\Receptions::class)->photoUrl($occasion))
                                <a href="{{ route('receptions.show', $occasion) }}" wire:navigate class="shrink-0">
                                    <img src="{{ $photo }}" alt="Souvenir du {{ $occasion->date->locale('fr')->isoFormat('D MMMM') }}" class="size-16 rounded-lg object-cover" loading="lazy">
                                </a>
                            @endif
                            @if ($occasion->date->isFuture())
                                <x-badge color="violet">À venir</x-badge>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <livewire:guests.guest-editor />
</div>
