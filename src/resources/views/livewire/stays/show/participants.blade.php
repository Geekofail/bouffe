{{-- Séjour — participants (34.1). --}}
@php
    $appetites = app(\App\Services\Planning\Appetites::class);
    $groups = $stay->participants->pluck('group_label')->unique()->values();
    $rows = $this->eaters->groupBy(fn ($row) => $row['participant']->group_label);
    $viewer = (int) \App\Support\CurrentHousehold::id();
    $organizer = $role === \App\Services\Stays\StayCoorganizers::ORGANIZER;
@endphp
<div class="grid gap-6 lg:grid-cols-3" data-stay-participants>
    <div class="space-y-4 lg:col-span-2">
        @error('participants') <p class="form-error">{{ $message }}</p> @enderror

        @forelse ($rows as $group => $members)
            <section class="card p-4" wire:key="group-{{ md5($group) }}">
                <h2 class="font-display mb-2 flex items-center gap-2 text-lg font-semibold text-stone-900">
                    <x-icon name="users" class="size-5 text-stone-400" /> {{ $group }}
                    <span class="text-sm font-normal text-stone-500">· {{ $members->count() }}</span>
                </h2>
                <ul class="divide-y divide-stone-100">
                    @foreach ($members as $row)
                        @php $p = $row['participant']; $mine = $row['owner'] === $viewer; @endphp
                        <li wire:key="participant-{{ $p->id }}" class="space-y-2 py-3">
                            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                <p class="font-medium text-stone-900">
                                    {{ $p->name }}
                                    @if (! $mine)
                                        <span class="text-xs font-normal text-stone-500">· avec « {{ $householdNames[$row['owner']] ?? '' }} »</span>
                                    @elseif ($p->origin()) <span class="text-xs font-normal text-stone-500">· {{ $p->origin() }}</span> @endif
                                </p>
                                <p class="flex flex-wrap gap-1">
                                    @forelse ($row['restrictions'] as $restriction)
                                        <x-badge :color="$restriction->type->color()">{{ $restriction->type->shortLabel() }} : {{ $restriction->ingredient?->name ?? $restriction->tag?->name }}</x-badge>
                                    @empty
                                        <span class="text-xs text-stone-500">
                                            @if (! $mine || ($row['eater'] instanceof \App\Services\Linked\LinkedEater && ! $row['eater']->shared)) Contraintes non partagées
                                            @elseif (! $row['eater']) Contraintes inconnues
                                            @else Aucune contrainte connue
                                            @endif
                                        </span>
                                    @endforelse
                                </p>
                            </div>
                            @if ($canEdit && $mine)
                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                    <select aria-label="Appétit de {{ $p->name }}" wire:change="updateParticipant({{ $p->id }}, 'appetite', $event.target.value)" class="form-input w-auto py-1.5 text-sm">
                                        @foreach (\App\Services\Planning\Appetites::LEVELS as $level => [$label])
                                            <option value="{{ $level }}" @selected($p->appetite === $level)>{{ $label }} ({{ \App\Services\Planning\Appetites::formatPart($appetites->part($level)) }})</option>
                                        @endforeach
                                    </select>
                                    <input type="text" aria-label="Groupe de {{ $p->name }}" value="{{ $p->group_label }}" list="stay-groups" maxlength="60"
                                           wire:change="updateParticipant({{ $p->id }}, 'group_label', $event.target.value)" class="form-input w-44 py-1.5 text-sm">
                                    <span class="flex items-center gap-1 text-stone-500">
                                        <input type="date" aria-label="Arrivée de {{ $p->name }}" value="{{ $p->present_from?->toDateString() }}" min="{{ $stay->starts_on->toDateString() }}" max="{{ $stay->ends_on->toDateString() }}"
                                               wire:change="updateParticipant({{ $p->id }}, 'present_from', $event.target.value)" class="form-input w-36 py-1.5 text-sm">
                                        →
                                        <input type="date" aria-label="Départ de {{ $p->name }}" value="{{ $p->present_to?->toDateString() }}" min="{{ $stay->starts_on->toDateString() }}" max="{{ $stay->ends_on->toDateString() }}"
                                               wire:change="updateParticipant({{ $p->id }}, 'present_to', $event.target.value)" class="form-input w-36 py-1.5 text-sm">
                                    </span>
                                    <button type="button" wire:click="removeParticipant({{ $p->id }})" wire:confirm="Retirer {{ $p->name }} du séjour ?" class="btn btn-ghost min-h-10 px-2 hover:text-red-600" title="Retirer">
                                        <x-icon name="delete" class="size-4" /><span class="sr-only">Retirer {{ $p->name }}</span>
                                    </button>
                                </div>
                            @else
                                <p class="text-sm text-stone-600">{{ $appetites->levelLabel($p->appetite) }} · {{ $p->daysPresent($stay) }} jour{{ $p->daysPresent($stay) > 1 ? 's' : '' }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="card">
                <x-empty-state icon="users" title="Personne pour l'instant">Ajoutez le foyer, des invités du carnet, un foyer relié ou d'autres personnes.</x-empty-state>
            </div>
        @endforelse

        <datalist id="stay-groups">
            @foreach ($groups as $group) <option value="{{ $group }}"></option> @endforeach
        </datalist>

        <p class="text-xs text-stone-500">
            Le <strong>groupe</strong> réunit ceux qui paient ensemble (une famille, un couple) : c'est lui qui a une part des frais.
            Des dates d'arrivée ou de départ vides : présent tout le séjour.
        </p>
    </div>

    @if ($canEdit)
        <aside class="space-y-4">
            <section class="card space-y-3 p-4">
                <h2 class="font-display text-lg font-semibold text-stone-900">Ajouter</h2>

                <button type="button" wire:click="addHousehold" class="btn btn-secondary w-full justify-center">
                    <x-icon name="home" class="size-4" /> Les personnes {{ $organizer ? 'du foyer' : 'de notre foyer' }}
                </button>

                <form wire:submit="addGuest" class="flex items-end gap-2">
                    <x-field label="Un invité du carnet" for="stay-guest" error="guestId" class="min-w-0 flex-1">
                        <select id="stay-guest" wire:model="guestId" class="form-input">
                            <option value="">Choisir…</option>
                            @foreach ($this->availableGuests as $guest)
                                <option value="{{ $guest->id }}">{{ $guest->name }}{{ $guest->group_name ? ' ('.$guest->group_name.')' : '' }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <button type="submit" class="btn btn-secondary">Ajouter</button>
                </form>

                @if ($organizer && $this->linkedHouseholds->isNotEmpty())
                    <form wire:submit="addLinked" class="flex items-end gap-2">
                        <x-field label="Un foyer relié" for="stay-linked" error="linkedId" class="min-w-0 flex-1">
                            <select id="stay-linked" wire:model="linkedId" class="form-input">
                                <option value="">Choisir…</option>
                                @foreach ($this->linkedHouseholds as $household)
                                    <option value="{{ $household->id }}">{{ $household->name }}</option>
                                @endforeach
                            </select>
                        </x-field>
                        <button type="submit" class="btn btn-secondary">Ajouter</button>
                    </form>
                @endif

                <form wire:submit="addPerson" class="space-y-2 border-t border-stone-100 pt-3">
                    <p class="form-label">Une autre personne</p>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" wire:model="personName" maxlength="60" placeholder="Prénom" aria-label="Prénom" @class(['form-input', 'form-input-error' => $errors->has('personName')])>
                        <select wire:model="personAppetite" aria-label="Appétit" class="form-input">
                            @foreach (\App\Services\Planning\Appetites::LEVELS as $level => [$label, $hint])
                                <option value="{{ $level }}">{{ $label }} — {{ $hint }}</option>
                            @endforeach
                        </select>
                    </div>
                    <input type="text" wire:model="personGroup" list="stay-groups" maxlength="60" placeholder="Groupe (qui paie avec qui)" aria-label="Groupe" class="form-input">
                    @error('personName') <p class="form-error">{{ $message }}</p> @enderror
                    <button type="submit" class="btn btn-secondary w-full justify-center"><x-icon name="plus" class="size-4" /> Ajouter</button>
                </form>
            </section>

            {{-- Lot 42 (42.1) : inviter un foyer relié à co-organiser. --}}
            @if ($organizer && ($this->linkedHouseholds->isNotEmpty() || $this->coorganizerRows->isNotEmpty()))
                <section class="card space-y-3 p-4" data-stay-coorganizers>
                    <h2 class="font-display text-lg font-semibold text-stone-900">Organiser à plusieurs</h2>
                    <p class="text-sm text-stone-600">Un foyer relié qui accepte prévoit des repas, note ses dépenses et gère ses participants. Il ne voit de vous que ce qu'il voyait déjà.</p>
                    @if ($this->coorganizerRows->isNotEmpty())
                        <ul class="divide-y divide-stone-100 text-sm">
                            @foreach ($this->coorganizerRows as $invited)
                                <li wire:key="coorg-{{ $invited->id }}" class="flex flex-wrap items-center gap-2 py-2">
                                    <span class="min-w-0 flex-1 font-medium text-stone-800">{{ $invited->household?->name }}</span>
                                    <x-badge :color="match ($invited->status) { 'accepted' => 'green', 'invited' => 'amber', default => 'stone' }">{{ \App\Models\StayHousehold::STATUSES[$invited->status] ?? $invited->status }}</x-badge>
                                    @if (in_array($invited->status, ['invited', 'accepted'], true))
                                        <button type="button" wire:click="removeCoorganizer({{ $invited->household_id }})"
                                                wire:confirm="Retirer « {{ $invited->household?->name }} » de l'organisation ? Ses dépenses restent dans les comptes."
                                                class="btn btn-ghost min-h-10 px-2 text-sm hover:text-red-600">Retirer</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @php $invitable = $this->linkedHouseholds->reject(fn ($h) => $this->coorganizerRows->whereIn('status', ['invited', 'accepted'])->contains('household_id', $h->id)); @endphp
                    @if ($invitable->isNotEmpty())
                        <form wire:submit="inviteCoorganizer" class="flex items-end gap-2">
                            <x-field label="Inviter à co-organiser" for="stay-coorganizer" error="coorganizerId" class="min-w-0 flex-1">
                                <select id="stay-coorganizer" wire:model="coorganizerId" class="form-input">
                                    <option value="">Choisir…</option>
                                    @foreach ($invitable as $household)
                                        <option value="{{ $household->id }}">{{ $household->name }}</option>
                                    @endforeach
                                </select>
                            </x-field>
                            <button type="submit" class="btn btn-secondary">Inviter</button>
                        </form>
                    @endif
                </section>
            @endif

            <p class="px-1 text-xs text-stone-500">
                Allergies et régimes viennent de la fiche de chaque invité, du profil des membres du foyer et, pour un foyer relié,
                de ceux qui ont choisi de les partager. Les repas du séjour sont vérifiés d'après les présents du jour.
            </p>
        </aside>
    @endif
</div>
