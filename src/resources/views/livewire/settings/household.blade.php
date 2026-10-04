<div>
    <x-page-header title="Foyer" subtitle="Membres, invitations et contraintes alimentaires." />
    <x-settings-nav />

    @php
        $owner = auth()->user()->managesHousehold();
        $household = $this->household;
    @endphp

    @if ($household?->deletion_requested_at)
        <div class="mb-6 flex flex-wrap items-center gap-3 rounded-xl bg-red-50 p-4 text-sm text-red-900 ring-1 ring-red-200" role="alert">
            <x-icon name="warning" class="size-5 shrink-0" />
            <p class="min-w-0 flex-1">Ce foyer et toutes ses données seront supprimés le <strong>{{ $household->deletionDate()->locale('fr')->isoFormat('D MMMM YYYY') }}</strong>.</p>
            @if ($owner) <button type="button" wire:click="cancelDeletion" class="btn btn-secondary">Annuler la suppression</button> @endif
        </div>
    @endif

    {{-- ============================================================ Le foyer (lot 24) --}}
    <div class="mb-6 grid gap-4 lg:grid-cols-3">
        <form wire:submit="saveHousehold" class="card space-y-4 p-4 lg:col-span-2">
            <h2 class="font-display font-semibold text-stone-900">Le foyer</h2>
            <x-field label="Nom du foyer" for="h-name" error="householdName">
                <input id="h-name" type="text" wire:model="householdName" maxlength="100" class="form-input" @disabled(! $owner)>
            </x-field>
            @if ($owner) <div class="flex justify-end"><button type="submit" class="btn btn-primary">Enregistrer</button></div> @endif
        </form>

        <section class="card space-y-2 p-4 text-sm">
            <h2 class="font-display font-semibold text-stone-900">Vos données</h2>
            <p class="text-stone-600">Recettes, planning, stock, courses, budget et invités de ce foyer ne sont visibles que par ses membres.</p>
            @if ($owner)
                <a href="{{ route('settings.export') }}" class="btn btn-secondary w-full"><x-icon name="download" class="size-4" /> Exporter le foyer</a>
            @endif
        </section>
    </div>

    {{-- ============================================================ Les personnes du foyer (lot 32, 32.1, R33 ; lot 39, 39.1, R40) --}}
    @php
        $appetites = app(\App\Services\Planning\Appetites::class);
        $canEdit = auth()->user()->canEdit();
        $people = $this->people;
    @endphp
    <section class="mb-6 space-y-4" aria-labelledby="people-title" data-people>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0 flex-1 basis-72">
                <h2 id="people-title" class="font-display text-lg font-semibold text-stone-900">Les personnes du foyer</h2>
                <p class="text-sm text-stone-600">
                    Avec ou sans compte : un enfant a son prénom, son appétit, ses goûts et sa cantine. Chacun compte
                    selon son appétit (ici, à table d'habitude :
                    <strong>{{ app(\App\Services\Planning\OccasionService::class)->summary(null) }}</strong>), et ce qu'il ne
                    mange pas déclenche les mêmes alertes que pour un invité.
                </p>
            </div>
            @if ($canEdit)
                <button type="button" wire:click="openPerson" class="btn btn-secondary" @disabled($people->count() >= \App\Services\Planning\Appetites::MAX_PEOPLE)>
                    <x-icon name="plus" class="size-4" /> Ajouter une personne
                </button>
            @endif
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($people as $index => $row)
                <article wire:key="person-{{ $row->id }}" class="card flex flex-col gap-3 p-4" data-person="{{ $row->name }}">
                    <div class="flex items-start gap-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full text-base font-semibold text-white" style="background-color: {{ $row->hex() }}" aria-hidden="true">{{ $row->initial() }}</span>
                        <div class="min-w-0 flex-1">
                            <h3 class="font-semibold text-stone-900">{{ $row->name }}</h3>
                            <p class="text-xs text-stone-500">
                                {{ $row->user ? 'Compte de '.$row->user->name : 'Sans compte' }}
                                · appétit {{ mb_strtolower($appetites->levelLabel($row->appetite)) }} ({{ \App\Services\Planning\Appetites::formatPart($appetites->part($row->appetite)) }})
                                @unless ($row->at_table) · <span class="font-medium text-stone-700">pas à table d'habitude</span> @endunless
                            </p>
                            @if ($row->hasCanteen())
                                <p class="mt-1 inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-800 ring-1 ring-sky-200">
                                    <x-icon name="school" class="size-3.5" />
                                    Cantine {{ collect($row->canteenDays())->map(fn ($d) => mb_strtolower(mb_substr(\App\Models\HouseholdPerson::SCHOOL_DAYS[$d], 0, 3)))->join(', ') }}@if ($row->canteen_name) · {{ $row->canteen_name }}@endif
                                </p>
                            @endif
                        </div>
                        @if ($canEdit)
                            <div class="flex shrink-0 items-center">
                                <button type="button" wire:click="movePerson({{ $row->id }}, -1)" class="btn btn-ghost min-h-10 px-2" @disabled($index === 0) title="Monter {{ $row->name }}"><x-icon name="chevron-up" class="size-4" /><span class="sr-only">Monter {{ $row->name }}</span></button>
                                <button type="button" wire:click="movePerson({{ $row->id }}, 1)" class="btn btn-ghost min-h-10 px-2" @disabled($index === $people->count() - 1) title="Descendre {{ $row->name }}"><x-icon name="chevron-down" class="size-4" /><span class="sr-only">Descendre {{ $row->name }}</span></button>
                            </div>
                        @endif
                    </div>

                    @if ($row->restrictions->isEmpty())
                        <p class="flex-1 text-sm text-stone-500">Aucune contrainte alimentaire.</p>
                    @else
                        <ul class="flex-1 space-y-1.5">
                            @foreach ($row->restrictions->sortBy(fn ($r) => array_search($r->type, \App\Enums\RestrictionType::cases())) as $restriction)
                                <li wire:key="pr-{{ $restriction->id }}" class="flex items-center gap-2">
                                    <x-badge :color="$restriction->type->color()">{{ $restriction->type->shortLabel() }}</x-badge>
                                    <span class="min-w-0 flex-1 truncate text-sm text-stone-800">
                                        {{ $restriction->subject() }}
                                        @if ($restriction->note) <span class="text-stone-500">· {{ $restriction->note }}</span> @endif
                                    </span>
                                    @if ($canEdit)
                                        <button type="button" wire:click="removeRestriction({{ $restriction->id }})" class="btn btn-ghost min-h-10 px-1.5 hover:text-red-600" title="Retirer {{ $restriction->subject() }}">
                                            <x-icon name="close" class="size-4" /><span class="sr-only">Retirer {{ $restriction->subject() }}</span>
                                        </button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($row->restrictions->isNotEmpty())
                            <p class="text-xs text-stone-500">
                                @if ($row->sharesTastes()) Montrés aux foyers reliés. @else Pas montrés aux foyers reliés. @endif
                            </p>
                        @endif
                    @endif

                    @if ($canEdit)
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="openPerson({{ $row->id }})" class="btn btn-secondary"><x-icon name="edit" class="size-4" /> Modifier</button>
                            <button type="button" wire:click="openRestriction({{ $row->id }})" class="btn btn-ghost"><x-icon name="plus" class="size-4" /> Goût ou allergie</button>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>

        @if ($canEdit && $this->accountsWithoutPerson->isNotEmpty())
            <p class="text-sm text-stone-600">
                Pas encore dans la liste :
                @foreach ($this->accountsWithoutPerson as $account)
                    <button type="button" wire:key="add-account-{{ $account->id }}" wire:click="openPerson(0, {{ $account->id }})" class="font-medium text-brand-700 underline hover:text-brand-800">{{ $account->name }}</button>@if (! $loop->last), @endif
                @endforeach
                (compte du foyer).
            </p>
        @endif

        <details class="card px-4 py-3">
            <summary class="cursor-pointer text-sm font-medium text-stone-700">Régler les parts de chaque appétit</summary>
            <form wire:submit="saveParts" class="mt-3 space-y-3">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach (\App\Services\Planning\Appetites::LEVELS as $level => [$label, $hint])
                        <x-field :label="$label" :for="'part-'.$level" :help="$hint">
                            <input id="part-{{ $level }}" type="text" inputmode="decimal" wire:model="parts.{{ $level }}" class="form-input" @disabled(! $owner)>
                        </x-field>
                    @endforeach
                </div>
                @error('parts') <p class="form-error">{{ $message }}</p> @enderror
                <p class="text-xs text-stone-500">Une part de 1 = une portion de recette. Les invités ajoutés au nombre comptent 1 (adulte) ou « Petit » (enfant).</p>
                @if ($owner) <div class="flex justify-end"><button type="submit" class="btn btn-secondary">Enregistrer les parts</button></div> @endif
            </form>
        </details>
    </section>

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="font-display text-lg font-semibold text-stone-900">Comptes</h2>
            <p class="text-sm text-stone-500">
                Qui se connecte à ce foyer, et avec quels droits. Les goûts de chacun sont notés sur sa personne, ci-dessus.
            </p>
        </div>
        @if ($owner)
            <button type="button" wire:click="openMember" class="btn btn-secondary">
                <x-icon name="plus" class="size-4" /> Créer un compte
            </button>
        @endif
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($this->members as $member)
            <section wire:key="member-{{ $member->id }}" class="card flex flex-col p-4">
                <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="font-semibold text-stone-900">
                            {{ $member->name }}
                            @if ($member->is(auth()->user()))
                                <span class="text-sm font-normal text-stone-500">· vous</span>
                            @endif
                        </h3>
                        <p class="truncate text-xs text-stone-500">{{ $member->email }}</p>
                    </div>

                    @if ($owner)
                        <select wire:change="changeRole({{ $member->id }}, $event.target.value)" aria-label="Rôle de {{ $member->name }}"
                                class="form-input w-auto py-1 text-sm">
                            @foreach (\App\Enums\UserRole::cases() as $role)
                                <option value="{{ $role->value }}" @selected($member->role === $role)>{{ $role->label() }}</option>
                            @endforeach
                        </select>
                    @else
                        <span class="rounded-full bg-stone-100 px-2 py-1 text-xs font-medium text-stone-700">{{ $member->role?->label() }}</span>
                    @endif
                </div>

                <div class="mt-auto flex flex-wrap items-center gap-2">
                    @if ($member->is(auth()->user()))
                        <button type="button" wire:click="leave" wire:confirm="Quitter « {{ $household?->name }} » ? Vous n'y aurez plus accès, sauf nouvelle invitation." class="ml-auto inline-flex min-h-11 items-center text-sm text-stone-500 underline hover:text-red-700">Quitter le foyer</button>
                    @elseif ($owner)
                        <button type="button" wire:click="removeMember({{ $member->id }})" wire:confirm="Retirer {{ $member->name }} du foyer ? Son compte reste, il n'aura plus accès à ce foyer." class="ml-auto inline-flex min-h-11 items-center text-sm text-stone-500 underline hover:text-red-700">Retirer du foyer</button>
                    @endif
                </div>
            </section>
        @endforeach
    </div>

    <p class="mt-4 text-sm text-stone-500">
        Le rôle « {{ \App\Enums\UserRole::Viewer->label() }} » est un garde-fou entre personnes de confiance :
        il évite les fausses manœuvres, il ne remplace pas un mot de passe bien gardé.
    </p>

    {{-- ============================================================ Invitations (25.2) --}}
    @if ($owner)
        <section class="card mt-6 space-y-4 p-4 sm:p-5">
            <div>
                <h2 class="font-display font-semibold text-stone-900">Inviter quelqu'un</h2>
                <p class="text-sm text-stone-500">Un lien à usage unique, valable {{ \App\Models\Invitation::VALID_DAYS }} jours : la personne choisit son mot de passe, ou rejoint le foyer avec son compte existant.</p>
            </div>
            <form wire:submit="invite" class="flex flex-wrap items-end gap-3">
                <x-field label="Adresse e-mail" for="inv-email" error="inviteEmail" optional class="min-w-0 flex-1 basis-56">
                    <input id="inv-email" type="email" wire:model="inviteEmail" class="form-input" placeholder="Réserver le lien à cette adresse">
                </x-field>
                <x-field label="Rôle" for="inv-role" error="inviteRole">
                    <select id="inv-role" wire:model="inviteRole" class="form-input">
                        @foreach (\App\Enums\UserRole::cases() as $userRole) <option value="{{ $userRole->value }}">{{ $userRole->label() }}</option> @endforeach
                    </select>
                </x-field>
                <button type="submit" class="btn btn-primary"><x-icon name="link" class="size-4" /> Créer le lien</button>
            </form>

            @if ($inviteUrl)
                <div x-data="{ copied: false }" class="rounded-xl bg-herb-50 p-3 ring-1 ring-herb-200">
                    <p class="mb-2 text-sm font-medium text-herb-900">Lien à envoyer (il ne sera plus affiché) :</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" readonly value="{{ $inviteUrl }}" class="form-input min-w-0 flex-1 basis-64 font-mono text-xs" x-on:focus="$el.select()" aria-label="Lien d'invitation">
                        <button type="button" class="btn btn-secondary" x-on:click="navigator.clipboard?.writeText(@js($inviteUrl)); copied = true">
                            <x-icon name="duplicate" class="size-4" /> <span x-text="copied ? 'Copié' : 'Copier'"></span>
                        </button>
                        <a class="btn btn-ghost" href="mailto:?subject={{ rawurlencode('Invitation dans le foyer '.$household?->name.' sur Bouffe') }}&body={{ rawurlencode("Bonjour,\n\nRejoins le foyer « ".$household?->name." » sur Bouffe avec ce lien (valable ".\App\Models\Invitation::VALID_DAYS." jours) :\n".$inviteUrl) }}">
                            <x-icon name="envelope" class="size-4" /> E-mail
                        </a>
                    </div>
                </div>
            @endif

            @if ($this->invitations->isNotEmpty())
                <ul class="divide-y divide-stone-100 text-sm">
                    @foreach ($this->invitations as $invitation)
                        <li wire:key="inv-{{ $invitation->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                            <span class="min-w-0 flex-1 truncate text-stone-800">{{ $invitation->email ?? 'Lien ouvert à tous' }}</span>
                            <span class="text-xs text-stone-500">{{ $invitation->role->label() }} · jusqu'au {{ $invitation->expires_at->locale('fr')->isoFormat('D MMM') }}</span>
                            <button type="button" wire:click="revokeInvitation({{ $invitation->id }})" class="text-sm text-stone-500 underline hover:text-red-700">Annuler</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- ============================================================ Supprimer le foyer (25.8) --}}
        @unless ($household?->deletion_requested_at)
            <section class="card mt-6 p-4 sm:p-5">
                <h2 class="font-display font-semibold text-stone-900">Supprimer le foyer</h2>
                <p class="text-sm text-stone-500">Toutes les données du foyer (recettes, planning, stock, budget, photos) seront supprimées {{ \App\Models\Household::DELETION_DELAY_DAYS }} jours après la demande. Les comptes des membres restent. Pensez à exporter d'abord.</p>
                @if ($showDelete)
                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        <x-field label="Tapez le nom du foyer pour confirmer" for="h-delete" error="deleteConfirmation" class="min-w-0 flex-1 basis-56">
                            <input id="h-delete" type="text" wire:model="deleteConfirmation" class="form-input" autocomplete="off">
                        </x-field>
                        <button type="button" wire:click="requestDeletion" class="btn btn-danger">Supprimer dans {{ \App\Models\Household::DELETION_DELAY_DAYS }} jours</button>
                    </div>
                @else
                    <button type="button" wire:click="$set('showDelete', true)" class="mt-3 text-sm font-medium text-red-700 underline">Supprimer ce foyer…</button>
                @endif
            </section>
        @endunless
    @endif

    {{-- ============================================================ Contrainte --}}
    <x-modal :show="$restrictionPersonId !== null" :title="'Goût ou allergie de '.($this->editingPerson?->name ?? '')" close="closeRestriction">
        <div class="space-y-4">
            <div>
                <span class="form-label">Type</span>
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Enums\RestrictionType::cases() as $type)
                        <button type="button" wire:click="$set('restrictionType', '{{ $type->value }}')"
                                wire:key="type-{{ $type->value }}" aria-pressed="{{ $restrictionType === $type->value ? 'true' : 'false' }}"
                                @class([
                                    'rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset transition',
                                    \App\Support\Palette::badge($type->color()) => $restrictionType === $type->value,
                                    'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => $restrictionType !== $type->value,
                                ])>
                            {{ $type->label() }}
                        </button>
                    @endforeach
                </div>
            </div>

            @if ($this->type()->usesIngredient())
                <x-field label="Ingrédient" for="hr-ingredient" error="subjectId">
                    <select id="hr-ingredient" wire:model="subjectId" class="form-input">
                        <option value="">Choisir…</option>
                        @foreach ($this->ingredients as $ingredient)
                            <option value="{{ $ingredient->id }}">{{ $ingredient->name }}</option>
                        @endforeach
                    </select>
                </x-field>
            @else
                <x-field label="Catégorie requise" for="hr-tag" error="subjectId"
                         help="Les recettes qui n'ont pas cette catégorie seront signalées.">
                    <select id="hr-tag" wire:model="subjectId" class="form-input">
                        <option value="">Choisir…</option>
                        @foreach ($this->tags as $tag)
                            <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                        @endforeach
                    </select>
                </x-field>
            @endif

            <x-field label="Note" for="hr-note" error="note" optional>
                <input id="hr-note" type="text" wire:model="note" class="form-input" placeholder="ex. sauf cuit">
            </x-field>
        </div>

        <x-slot:footer>
            <button type="button" wire:click="closeRestriction" class="btn btn-secondary">Annuler</button>
            <button type="button" wire:click="addRestriction" class="btn btn-primary">Ajouter</button>
        </x-slot:footer>
    </x-modal>

    {{-- ============================================================ Personne (lot 39) --}}
    @php $accounts = \App\Models\User::query()->inHousehold()->orderBy('name')->get(['id', 'name']); @endphp
    <x-modal :show="$editingPersonId !== null" :title="$editingPersonId ? 'Modifier '.($person['name'] ?? '') : 'Nouvelle personne'" close="closePerson">
        <form id="person-form" wire:submit="savePerson" class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-field label="Prénom" for="p-name" error="person.name">
                    <input id="p-name" type="text" wire:model="person.name" maxlength="60" class="form-input" autocomplete="off">
                </x-field>
                <x-field label="Compte" for="p-user" help="Un enfant n'a pas besoin de compte.">
                    <select id="p-user" wire:model.live="person.user_id" class="form-input">
                        <option value="">Sans compte</option>
                        @foreach ($accounts as $account) <option value="{{ $account->id }}">{{ $account->name }}</option> @endforeach
                    </select>
                </x-field>
                <x-field label="Appétit" for="p-appetite">
                    <select id="p-appetite" wire:model="person.appetite" class="form-input">
                        @foreach (\App\Services\Planning\Appetites::LEVELS as $level => [$label, $hint])
                            <option value="{{ $level }}">{{ $label }} · {{ \App\Services\Planning\Appetites::formatPart(app(\App\Services\Planning\Appetites::class)->part($level)) }} ({{ $hint }})</option>
                        @endforeach
                    </select>
                </x-field>
                <fieldset>
                    <legend class="form-label">Couleur</legend>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (\App\Models\HouseholdPerson::COLORS as $key => [$colorName, $hex])
                            <label wire:key="color-{{ $key }}" class="cursor-pointer" title="{{ $colorName }}">
                                <input type="radio" wire:model="person.color" value="{{ $key }}" class="peer sr-only">
                                <span class="block size-8 rounded-full ring-2 ring-transparent ring-offset-2 peer-checked:ring-stone-700 peer-focus-visible:ring-brand-500" style="background-color: {{ $hex }}"></span>
                                <span class="sr-only">{{ $colorName }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            </div>

            <label class="flex items-start gap-2 text-sm text-stone-700">
                <input type="checkbox" wire:model="person.at_table" class="form-checkbox mt-0.5">
                <span><strong>À table d'habitude</strong> — compte dans les portions de chaque repas. Décochez pour quelqu'un qui vient de temps en temps.</span>
            </label>

            <fieldset class="space-y-2 rounded-xl bg-stone-50 p-3">
                <legend class="sr-only">Cantine</legend>
                <p class="text-sm font-medium text-stone-800">Mange à la cantine le midi</p>
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Models\HouseholdPerson::SCHOOL_DAYS as $day => $dayName)
                        <label wire:key="canteen-day-{{ $day }}" class="flex min-h-10 cursor-pointer items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm ring-1 ring-stone-200 has-checked:bg-sky-50 has-checked:ring-sky-300">
                            <input type="checkbox" wire:model.live="person.canteen_days" value="{{ $day }}" class="form-checkbox">
                            {{ $dayName }}
                        </label>
                    @endforeach
                </div>
                @if (! empty($person['canteen_days']))
                    <x-field label="Où ?" for="p-canteen" error="person.canteen_name" optional>
                        <input id="p-canteen" type="text" wire:model="person.canteen_name" maxlength="80" placeholder="ex. École de Bonnevoie, maison relais" class="form-input">
                    </x-field>
                @endif
                <p class="text-xs text-stone-500">Ces jours-là, au déjeuner, elle n'est pas comptée à la maison. Le menu se note dans Planning › Plus › Cantine.</p>
            </fieldset>

            @if (($person['user_id'] ?? '') === '')
                <label class="flex items-start gap-2 text-sm text-stone-700">
                    <input type="checkbox" wire:model="person.share_tastes" class="form-checkbox mt-0.5">
                    <span>Montrer ses goûts et allergies aux <strong>foyers reliés</strong> qui la reçoivent (réceptions, séjours).</span>
                </label>
            @else
                <p class="text-xs text-stone-500">Avec un compte, la personne choisit elle-même de montrer ses goûts aux foyers reliés (Mon compte).</p>
            @endif
        </form>

        <x-slot:footer>
            @if ($editingPersonId)
                <button type="button" wire:click="deletePerson" wire:confirm="Retirer {{ $person['name'] ?? 'cette personne' }} de la liste ? Ses goûts et ses menus de cantine seront effacés ; son compte, s'il y en a un, reste." class="btn btn-ghost mr-auto text-red-700 hover:bg-red-50">Retirer</button>
            @endif
            <button type="button" wire:click="closePerson" class="btn btn-secondary">Annuler</button>
            <button type="submit" form="person-form" class="btn btn-primary">Enregistrer</button>
        </x-slot:footer>
    </x-modal>

    {{-- ============================================================ Nouveau membre --}}
    <x-modal :show="$showMember" title="Créer un compte dans le foyer" close="closeMember">
        <div class="space-y-4">
            <x-field label="Prénom" for="m-name" error="name">
                <input id="m-name" type="text" wire:model="name" class="form-input" autofocus>
            </x-field>

            <x-field label="Adresse e-mail" for="m-email" error="email" help="Elle sert d'identifiant de connexion.">
                <input id="m-email" type="email" wire:model="email" class="form-input">
            </x-field>

            <x-field label="Mot de passe" for="m-password" error="password" help="8 caractères minimum.">
                <input id="m-password" type="password" wire:model="password" class="form-input" autocomplete="new-password">
            </x-field>

            <div>
                <span class="form-label">Rôle</span>
                <div class="space-y-2">
                    @foreach (\App\Enums\UserRole::cases() as $userRole)
                        <label wire:key="role-{{ $userRole->value }}" class="flex items-start gap-2 text-sm text-stone-700">
                            <input type="radio" wire:model="role" value="{{ $userRole->value }}" class="mt-1">
                            <span><strong>{{ $userRole->label() }}</strong> — {{ $userRole->help() }}</span>
                        </label>
                    @endforeach
                </div>
                @error('role') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <x-slot:footer>
            <button type="button" wire:click="closeMember" class="btn btn-secondary">Annuler</button>
            <button type="button" wire:click="addMember" class="btn btn-primary">Créer le compte</button>
        </x-slot:footer>
    </x-modal>
</div>
