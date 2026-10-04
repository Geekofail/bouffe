<div class="mx-auto max-w-4xl">
    <x-page-header title="Séjours" subtitle="Une semaine au chalet, un week-end chez les parents : les gens, les repas, les courses et les comptes.">
        <x-slot:actions>
            @if (auth()->user()->canEdit())
                <button type="button" wire:click="open" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Nouveau séjour</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Lot 42 (42.1) : invitations à co-organiser. --}}
    @foreach ($invitations as $invitation)
        <div wire:key="stay-invite-{{ $invitation->id }}" class="card mb-4 flex flex-wrap items-center gap-3 p-4 ring-sky-200" data-stay-invitation>
            <x-icon name="users" class="size-6 text-sky-600" />
            <p class="min-w-0 flex-1 text-sm text-stone-700">
                « {{ $invitation->stay->household?->name }} » vous propose d'organiser ensemble
                <strong class="text-stone-900">{{ $invitation->stay->name }}</strong>, {{ $invitation->stay->period() }}.
                <span class="block text-xs text-stone-500">Vous y prévoirez des repas, noterez vos dépenses et gérerez vos participants.</span>
            </p>
            @if (auth()->user()->canEdit())
                <div class="flex gap-2">
                    <button type="button" wire:click="respond({{ $invitation->id }}, false)" class="btn btn-ghost">Non merci</button>
                    <button type="button" wire:click="respond({{ $invitation->id }}, true)" class="btn btn-primary">Accepter</button>
                </div>
            @endif
        </div>
    @endforeach

    @if ($upcoming->isEmpty() && $past->isEmpty() && $invitations->isEmpty())
        <div class="card">
            <x-empty-state icon="sun" title="Aucun séjour">
                Un séjour a son propre planning et sa propre liste de courses, sans rien changer à ceux de la maison.
                À la fin, Bouffe calcule qui doit combien.
            </x-empty-state>
        </div>
    @endif

    @foreach (['À venir et en cours' => $upcoming, 'Passés' => $past] as $heading => $group)
        @if ($group->isNotEmpty())
            <h2 class="font-display mt-6 mb-3 text-lg font-semibold text-stone-900 first:mt-0">{{ $heading }}</h2>
            <ul class="grid gap-3 sm:grid-cols-2">
                @foreach ($group as $stay)
                    <li wire:key="stay-{{ $stay->id }}">
                        <a href="{{ route('stays.show', $stay) }}" wire:navigate class="card flex h-full flex-col gap-1 p-4 hover:ring-brand-300">
                            <span class="flex items-center gap-2">
                                <span class="font-display text-lg font-semibold text-stone-900">{{ $stay->name }}</span>
                                @if ($stay->isCurrent()) <x-badge color="green">en cours</x-badge> @endif
                                @if ((int) $stay->household_id !== $mine) <x-badge color="sky">avec « {{ $stay->household?->name }} »</x-badge> @endif
                            </span>
                            <span class="text-sm text-stone-600">{{ ucfirst($stay->period()) }}@if ($stay->place) · {{ $stay->place }} @endif</span>
                            <span class="text-xs text-stone-500">{{ $stay->participants_count }} personne{{ $stay->participants_count > 1 ? 's' : '' }} · {{ $stay->meals_count }} repas prévu{{ $stay->meals_count > 1 ? 's' : '' }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    @endforeach

    <x-modal :show="$creating" title="Nouveau séjour" close="close">
        <form id="stay-form" wire:submit="create" class="space-y-4">
            <x-field label="Nom" for="stay-name" error="name">
                <input id="stay-name" type="text" wire:model="name" maxlength="100" placeholder="Chalet à Vianden" class="form-input" autofocus>
            </x-field>
            <x-field label="Lieu (facultatif)" for="stay-place" error="place">
                <input id="stay-place" type="text" wire:model="place" maxlength="150" class="form-input">
            </x-field>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Du" for="stay-from" error="startsOn">
                    <input id="stay-from" type="date" wire:model="startsOn" class="form-input">
                </x-field>
                <x-field label="Au" for="stay-to" error="endsOn">
                    <input id="stay-to" type="date" wire:model="endsOn" class="form-input">
                </x-field>
            </div>
            <p class="text-sm text-stone-500">Les personnes à table d'habitude sont ajoutées tout de suite ; invités et foyers reliés ensuite.</p>
        </form>
        <x-slot:footer>
            <button type="button" wire:click="close" class="btn btn-ghost">Annuler</button>
            <button type="submit" form="stay-form" class="btn btn-primary">Créer le séjour</button>
        </x-slot:footer>
    </x-modal>
</div>
