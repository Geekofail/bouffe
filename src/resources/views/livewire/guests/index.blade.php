<div>
    <x-page-header title="Invités" subtitle="Le carnet des amis et de la famille : contraintes alimentaires et repas partagés.">
        <x-slot:actions>
            <a href="{{ route('planner.week') }}" wire:navigate class="btn btn-ghost"><x-icon name="calendar" class="size-4" /> Planning</a>
            <a href="{{ route('receptions.index') }}" wire:navigate class="btn btn-ghost"><x-icon name="cake" class="size-4" /> Réceptions</a>
            <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Nouvel invité</button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 flex flex-wrap items-center gap-3">
        <div class="relative min-w-56 flex-1">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Nom ou groupe…" class="form-input pl-10" aria-label="Rechercher un invité">
        </div>
        @if ($archivedCount > 0)
            <label class="flex items-center gap-2 text-sm text-stone-600">
                <input type="checkbox" wire:model.live="showArchived" class="form-checkbox"> Afficher les archivés ({{ $archivedCount }})
            </label>
        @endif
    </div>

    @if ($groups->isEmpty())
        <div class="card">
            <x-empty-state icon="users" title="{{ trim($search) === '' ? 'Aucun invité pour l\'instant' : 'Aucun invité trouvé' }}">
                Ajoutez les proches que vous recevez : leurs allergies et ce qu'ils n'aiment pas seront signalés au moment de choisir le menu.
            </x-empty-state>
        </div>
    @else
        <div class="space-y-6">
            @foreach ($groups as $group => $guests)
                <section wire:key="group-{{ $group === '' ? 'none' : $group }}">
                    <h2 class="mb-2 text-sm font-semibold tracking-wide text-stone-500 uppercase">{{ $group === '' ? 'Sans groupe' : $group }} <span class="font-normal">· {{ $guests->count() }}</span></h2>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($guests as $guest)
                            <div wire:key="guest-{{ $guest->id }}" @class(['card flex items-start gap-3 p-4', 'opacity-60' => $guest->isArchived()])>
                                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-violet-100 text-sm font-bold text-violet-700">{{ $guest->initials() }}</div>
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('guests.show', $guest) }}" wire:navigate class="font-semibold text-stone-900 hover:text-brand-700">{{ $guest->name }}</a>
                                    @if ($guest->is_child) <x-badge color="sky">Enfant</x-badge> @endif
                                    @if ($guest->isArchived()) <x-badge>Archivé</x-badge> @endif
                                    <p class="text-xs text-stone-500">
                                        @if ($guest->occasions_count > 0)
                                            {{ $guest->occasions_count }} repas · dernier {{ \Illuminate\Support\Carbon::parse($guest->last_visit)->locale('fr')->isoFormat('D MMM YYYY') }}
                                        @else
                                            Pas encore invité
                                        @endif
                                    </p>
                                    @if ($guest->restrictions->isNotEmpty())
                                        <div class="mt-2 flex flex-wrap gap-1">
                                            @foreach ($guest->restrictions as $restriction)
                                                <x-badge :color="$restriction->type->color()">{{ $restriction->type->shortLabel() }} · {{ $restriction->subject() }}</x-badge>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <button type="button" wire:click="edit({{ $guest->id }})" class="btn btn-ghost px-2" title="Modifier">
                                    <x-icon name="edit" class="size-4" /><span class="sr-only">Modifier</span>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif

    <livewire:guests.guest-editor />
</div>
