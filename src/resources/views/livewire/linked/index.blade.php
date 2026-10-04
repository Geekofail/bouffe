<div>
    <x-page-header title="Proches" subtitle="Les foyers reliés au vôtre : ce que chacun partage, les repas en commun, les surplus." />
    <x-linked-nav />

    {{-- ============================================================ Repas communs reçus (26.5) --}}
    @if ($this->mealInvitations->isNotEmpty())
        <section class="card mb-6 p-4 sm:p-5">
            <h2 class="font-display mb-3 flex items-center gap-2 font-semibold text-stone-900"><x-icon name="cake" class="size-5 text-violet-700" /> Repas en commun</h2>
            <ul class="divide-y divide-stone-100">
                @foreach ($this->mealInvitations as $row)
                    <li wire:key="mi-{{ $row->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-stone-900">{{ $row->occasion->title ?: 'Repas' }} — {{ $row->occasion->household?->name }}</p>
                            <p class="text-sm text-stone-500">{{ ucfirst($row->occasion->date->locale('fr')->isoFormat('dddd D MMMM')) }}{{ $row->occasion->slot ? ' · '.$row->occasion->slot->name : '' }}</p>
                        </div>
                        <span @class(['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1',
                            'bg-amber-50 text-amber-800 ring-amber-200' => $row->status === 'invited',
                            'bg-emerald-50 text-emerald-800 ring-emerald-200' => $row->status === 'accepted',
                            'bg-stone-100 text-stone-700 ring-stone-200' => $row->status === 'declined'])>
                            <x-icon :name="['invited' => 'envelope', 'accepted' => 'check', 'declined' => 'close'][$row->status]" class="size-3.5" />
                            {{ $row->status === 'invited' ? 'À répondre' : \App\Models\MealOccasionHousehold::STATUSES[$row->status] }}
                        </span>
                        <a href="{{ route('linked.meal', $row->id) }}" wire:navigate class="btn btn-secondary py-1 text-sm">Ouvrir</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ============================================================ Foyers reliés --}}
    @forelse ($this->linked as $row)
        @php $h = $row['household']; $their = $row['theirShare']; @endphp
        <section wire:key="linked-{{ $h->id }}" class="card mb-6 p-4 sm:p-5">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-display flex items-center gap-2 text-lg font-semibold text-stone-900"><x-icon name="home" class="size-5 text-brand-600" /> {{ $h->name }}</h2>
                    <p class="text-sm text-stone-500">{{ implode(', ', $row['members']) }}</p>
                </div>
                @if ($owner)
                    <button type="button" wire:click="unlink({{ $h->id }})" wire:confirm="Défaire le lien avec « {{ $h->name }} » ? Plus rien ne sera partagé, dans un sens comme dans l'autre. Les recettes déjà copiées restent." class="text-sm text-stone-500 underline hover:text-red-700">Défaire le lien</button>
                @endif
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-lg bg-stone-50 p-3 text-sm">
                    <h3 class="mb-2 font-medium text-stone-900">Ils nous ouvrent</h3>
                    <ul class="space-y-1.5 text-stone-700">
                        <li class="flex items-center gap-2">
                            <x-icon name="recipes" class="size-4 shrink-0 text-stone-400" />
                            @if ($row['recipes'] > 0)
                                <a href="{{ route('recipes.index', ['source' => 'proches', 'foyer' => $h->id]) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $row['recipes'] }} recette{{ $row['recipes'] > 1 ? 's' : '' }}</a>
                                {{ $their->recipes_all ? '(tout leur carnet)' : '' }}
                            @else
                                Aucune recette pour l'instant
                            @endif
                        </li>
                        <li class="flex items-center gap-2">
                            <x-icon name="calendar" class="size-4 shrink-0 text-stone-400" />
                            @if ($their->planning === 'none')
                                Planning fermé
                            @else
                                <a href="{{ route('linked.planning', $h->id) }}" wire:navigate class="font-medium text-brand-700 hover:underline">Leur planning</a>
                                ({{ $their->planning === 'write' ? 'lecture et écriture' : 'lecture' }})
                            @endif
                        </li>
                        @foreach ($row['lists'] as $list)
                            <li class="flex items-center gap-2">
                                <x-icon name="cart" class="size-4 shrink-0 text-stone-400" />
                                <a href="{{ route('linked.list', $list->id) }}" wire:navigate class="font-medium text-brand-700 hover:underline">Liste groupée : {{ $list->name }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="rounded-lg bg-stone-50 p-3 text-sm">
                    <h3 class="mb-2 font-medium text-stone-900">Nous leur ouvrons</h3>
                    @if (isset($shares[$h->id]))
                        <div class="space-y-3">
                            <label class="flex items-start gap-2 text-stone-700">
                                <input type="checkbox" wire:model="shares.{{ $h->id }}.recipes_all" class="form-checkbox mt-0.5" @disabled(! $owner)>
                                <span>Tout notre carnet de recettes <span class="block text-xs text-stone-500">Sinon : seulement les recettes marquées « Foyers reliés » ou « Toute l'installation ».</span></span>
                            </label>
                            <x-field label="Notre planning" for="share-planning-{{ $h->id }}">
                                <select id="share-planning-{{ $h->id }}" wire:model="shares.{{ $h->id }}.planning" class="form-input" @disabled(! $owner)>
                                    @foreach ($planningOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </x-field>
                            @if ($owner)
                                <div class="flex justify-end"><button type="button" wire:click="saveShare({{ $h->id }})" class="btn btn-primary py-1.5">Enregistrer</button></div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @empty
        <div class="card mb-6 p-6">
            <x-empty-state icon="heart" title="Aucun foyer relié">
                Relier votre foyer à celui de proches (parents, enfants partis, amis) permet de partager des recettes,
                d'ouvrir son planning, d'organiser un repas où chacun apporte un plat, de donner un surplus.
                Rien n'est partagé tant que vous ne l'avez pas décidé.
            </x-empty-state>
        </div>
    @endforelse

    {{-- ============================================================ Relier un foyer --}}
    <section class="card p-4 sm:p-5">
        <h2 class="font-display mb-1 font-semibold text-stone-900">Relier un foyer</h2>
        <p class="mb-4 text-sm text-stone-600">
            Créez un lien et envoyez-le à un responsable de l'autre foyer : il l'ouvre, connecté à Bouffe, et accepte.
            Le lien sert une fois et reste valable {{ \App\Models\HouseholdLink::VALID_DAYS }} jours.
        </p>

        @if ($owner)
            <button type="button" wire:click="createLink" class="btn btn-primary"><x-icon name="link" class="size-4" /> Créer un lien</button>

            @if ($linkUrl)
                <div class="mt-4 rounded-lg bg-brand-50 p-3" x-data="{ copied: false }">
                    <p class="mb-2 text-sm font-medium text-stone-900">Lien à envoyer (il ne sera plus affiché) :</p>
                    <div class="flex flex-wrap gap-2">
                        <input type="text" readonly value="{{ $linkUrl }}" class="form-input min-w-0 flex-1 basis-64 font-mono text-xs" x-on:focus="$el.select()" aria-label="Lien pour relier les foyers">
                        <button type="button" class="btn btn-secondary" x-on:click="navigator.clipboard?.writeText(@js($linkUrl)); copied = true">
                            <x-icon name="duplicate" class="size-4" /> <span x-text="copied ? 'Copié' : 'Copier'">Copier</span>
                        </button>
                        <a class="btn btn-ghost" href="mailto:?subject={{ rawurlencode('Relier nos foyers sur Bouffe') }}&body={{ rawurlencode("Bonjour,\n\nPour relier ton foyer à « ".$household?->name." » sur Bouffe, ouvre ce lien (valable ".\App\Models\HouseholdLink::VALID_DAYS." jours) :\n".$linkUrl) }}">
                            <x-icon name="envelope" class="size-4" /> E-mail
                        </a>
                    </div>
                </div>
            @endif

            @if ($this->invitations->isNotEmpty())
                <ul class="mt-4 divide-y divide-stone-100 text-sm">
                    @foreach ($this->invitations as $invitation)
                        <li wire:key="link-{{ $invitation->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                            <span class="min-w-0 flex-1 text-stone-700">Lien en attente</span>
                            <span class="text-xs text-stone-500">jusqu'au {{ $invitation->expires_at->locale('fr')->isoFormat('D MMM') }}</span>
                            <button type="button" wire:click="revokeLink({{ $invitation->id }})" class="text-sm text-stone-500 underline hover:text-red-700">Annuler</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        @else
            <p class="text-sm text-stone-500">Seuls les responsables du foyer relient les foyers et règlent ce qui est partagé.</p>
        @endif
    </section>
</div>
