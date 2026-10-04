<div>
    <x-page-header title="Budget" subtitle="Postes, budgets mensuels et dépenses récurrentes.">
        <x-slot:actions>
            <a href="{{ route('budget.index') }}" wire:navigate class="btn btn-secondary"><x-icon name="euro" class="size-4" /> Tableau de bord</a>
        </x-slot:actions>
    </x-page-header>
    <x-settings-nav />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            {{-- ==================================================== Postes et budgets (23.2, 23.7) --}}
            <form wire:submit="saveCategories" class="card p-4 sm:p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="font-display font-semibold text-stone-900">Postes et budget mensuel</h2>
                    <p class="text-xs text-stone-500">Période en cours : {{ $periodLabel }}</p>
                </div>
                <p class="mt-1 text-sm text-stone-500">
                    Laissez un budget vide pour suivre un poste sans limite. Un nouveau montant s'applique à partir de la période en cours ; les périodes passées gardent le leur.
                </p>

                <ul class="mt-4 space-y-3">
                    @foreach ($this->categories as $category)
                        <li wire:key="cat-{{ $category->id }}" x-data="{ colors: false }" class="rounded-xl bg-stone-50 p-2.5 ring-1 ring-stone-200">
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" x-on:click="colors = ! colors" class="grid size-8 shrink-0 place-items-center rounded-lg hover:bg-white" title="Couleur du poste" aria-label="Couleur de {{ $category->name }}">
                                    <span class="size-4 rounded-full {{ \App\Support\Palette::dot($rows[$category->id]['color'] ?? $category->color) }}"></span>
                                </button>
                                <label class="sr-only" for="cat-name-{{ $category->id }}">Nom du poste</label>
                                <input id="cat-name-{{ $category->id }}" type="text" wire:model="rows.{{ $category->id }}.name" maxlength="80"
                                       @class(['form-input min-w-0 flex-1 basis-[calc(100%-2.5rem)] py-1.5 sm:basis-36', 'form-input-error' => $errors->has('rows.'.$category->id.'.name')])>
                                <div class="relative w-28 shrink-0">
                                    <label class="sr-only" for="cat-budget-{{ $category->id }}">Budget mensuel de {{ $category->name }}</label>
                                    <input id="cat-budget-{{ $category->id }}" type="text" inputmode="decimal" wire:model="rows.{{ $category->id }}.budget" placeholder="aucun"
                                           @class(['form-input py-1.5 pr-7 text-right tabular-nums placeholder:text-xs', 'form-input-error' => $errors->has('rows.'.$category->id.'.budget')])>
                                    <span class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-sm text-stone-500">€</span>
                                </div>
                                <div class="ml-auto flex shrink-0 items-center">
                                    <button type="button" wire:click="move({{ $category->id }}, -1)" @disabled($loop->first) class="btn btn-ghost px-1.5 disabled:opacity-30" title="Monter">
                                        <x-icon name="chevron-up" class="size-4" /><span class="sr-only">Monter</span>
                                    </button>
                                    <button type="button" wire:click="move({{ $category->id }}, 1)" @disabled($loop->last) class="btn btn-ghost px-1.5 disabled:opacity-30" title="Descendre">
                                        <x-icon name="chevron-down" class="size-4" /><span class="sr-only">Descendre</span>
                                    </button>
                                    @if ($category->kind !== 'groceries')
                                        <button type="button" wire:click="archive({{ $category->id }})" class="btn btn-ghost px-1.5" title="Archiver">
                                            <x-icon name="archive" class="size-4" /><span class="sr-only">Archiver</span>
                                        </button>
                                    @else
                                        <span class="w-7" aria-hidden="true"></span>{{-- les courses ne s'archivent pas : garde l'alignement --}}
                                    @endif
                                </div>
                            </div>
                            <div x-show="colors" x-cloak class="mt-2 pl-1">
                                <x-color-picker wire:model.live="rows.{{ $category->id }}.color" name="color-{{ $category->id }}" />
                            </div>
                            @error('rows.'.$category->id.'.budget') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                            @error('rows.'.$category->id.'.name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </li>
                    @endforeach
                </ul>

                <div class="mt-4 flex justify-end">
                    <button type="submit" class="btn btn-primary">Enregistrer les postes</button>
                </div>
            </form>

            {{-- Ajouter un poste --}}
            <form wire:submit="addCategory" class="card space-y-3 p-4 sm:p-5">
                <h2 class="font-display font-semibold text-stone-900">Nouveau poste</h2>
                <div class="flex flex-wrap items-end gap-3">
                    <x-field label="Nom" for="new-category" error="newName" class="min-w-0 flex-1 basis-48">
                        <input id="new-category" type="text" wire:model="newName" maxlength="80" placeholder="ex. Cantine, Animaux…"
                               @class(['form-input', 'form-input-error' => $errors->has('newName')])>
                    </x-field>
                    <button type="submit" class="btn btn-secondary"><x-icon name="plus" class="size-4" /> Ajouter</button>
                </div>
                <x-color-picker wire:model="newColor" name="new-color" />
            </form>

            @if ($this->archived->isNotEmpty())
                <section class="card p-4">
                    <h2 class="font-display font-semibold text-stone-900">Postes archivés</h2>
                    <p class="text-sm text-stone-500">Leurs dépenses passées restent comptées ; ils ne sont plus proposés à la saisie.</p>
                    <ul class="mt-2 divide-y divide-stone-100">
                        @foreach ($this->archived as $category)
                            <li wire:key="arch-{{ $category->id }}" class="flex items-center gap-2 py-2">
                                <span class="size-2.5 rounded-full {{ \App\Support\Palette::dot($category->color) }}" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1 truncate text-stone-700">{{ $category->name }}</span>
                                <button type="button" wire:click="restore({{ $category->id }})" class="btn btn-ghost text-sm"><x-icon name="unarchive" class="size-4" /> Rétablir</button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- ==================================================== Dépenses récurrentes (C7) --}}
            <section class="card p-4 sm:p-5">
                <h2 class="font-display font-semibold text-stone-900">Dépenses récurrentes</h2>
                <p class="text-sm text-stone-500">Cantine, panier de légumes, abonnement : saisies une fois, comptées à chaque échéance (jamais d'avance).</p>

                @if ($recurring->isNotEmpty())
                    <ul class="mt-3 divide-y divide-stone-100">
                        @foreach ($recurring as $rule)
                            <li wire:key="rec-{{ $rule->id }}" @class(['flex flex-wrap items-center gap-x-3 gap-y-1 py-2', 'opacity-60' => ! $rule->is_active])>
                                <span class="size-2.5 shrink-0 rounded-full {{ \App\Support\Palette::dot($rule->category?->color) }}" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium text-stone-800">{{ $rule->label }}</span>
                                    <span class="block text-xs text-stone-500">
                                        {{ $rule->category?->name }} · {{ $rule->describe() }}
                                        @if ($rule->is_active) · prochaine le {{ $rule->next_on->locale('fr')->isoFormat('D MMM') }} @else · en pause @endif
                                    </span>
                                </span>
                                <span class="font-semibold text-stone-900 tabular-nums">{{ $tracker->money((float) $rule->amount) }}</span>
                                <span class="flex items-center">
                                    <button type="button" wire:click="toggleRecurring({{ $rule->id }})" class="btn btn-ghost px-2 text-sm">{{ $rule->is_active ? 'Pause' : 'Reprendre' }}</button>
                                    <button type="button" wire:click="deleteRecurring({{ $rule->id }})" wire:confirm="Supprimer « {{ $rule->label }} » ? Les échéances déjà comptées restent." class="btn btn-ghost px-1.5 text-red-700" title="Supprimer">
                                        <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer</span>
                                    </button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <form wire:submit="addRecurring" class="mt-4 grid gap-3 rounded-xl bg-stone-50 p-3 ring-1 ring-stone-200 sm:grid-cols-2">
                    <x-field label="Libellé" for="rec-label" error="recLabel">
                        <input id="rec-label" type="text" wire:model="recLabel" maxlength="150" placeholder="ex. Cantine de Léa" @class(['form-input', 'form-input-error' => $errors->has('recLabel')])>
                    </x-field>
                    <x-field label="Montant (€)" for="rec-amount" error="recAmount">
                        <input id="rec-amount" type="text" inputmode="decimal" wire:model="recAmount" placeholder="ex. 45" @class(['form-input', 'form-input-error' => $errors->has('recAmount')])>
                    </x-field>
                    <x-field label="Poste" for="rec-category" error="recCategory">
                        <select id="rec-category" wire:model="recCategory" class="form-input">
                            @foreach ($this->categories as $category) <option value="{{ $category->id }}">{{ $category->name }}</option> @endforeach
                        </select>
                    </x-field>
                    <div class="grid grid-cols-2 gap-3">
                        <x-field label="Rythme" for="rec-frequency">
                            <select id="rec-frequency" wire:model.live="recFrequency" class="form-input">
                                <option value="monthly">Chaque mois</option>
                                <option value="weekly">Chaque semaine</option>
                            </select>
                        </x-field>
                        <x-field :label="$recFrequency === 'weekly' ? 'Jour' : 'Le'" for="rec-day" error="recDay">
                            <select id="rec-day" wire:model="recDay" class="form-input">
                                @if ($recFrequency === 'weekly')
                                    @foreach (range(1, 7) as $d) <option value="{{ $d }}">{{ ucfirst($days[$d]) }}</option> @endforeach
                                @else
                                    @foreach (range(1, 28) as $d) <option value="{{ $d }}">{{ $d }}</option> @endforeach
                                @endif
                            </select>
                        </x-field>
                    </div>
                    <div class="sm:col-span-2 flex justify-end">
                        <button type="submit" class="btn btn-secondary"><x-icon name="plus" class="size-4" /> Ajouter la dépense récurrente</button>
                    </div>
                </form>
            </section>
        </div>

        {{-- ======================================================== Réglages et explications --}}
        <aside class="space-y-4">
            <form wire:submit="saveOptions" class="card space-y-4 p-4">
                <h2 class="font-display font-semibold text-stone-900">Période budgétaire</h2>
                <x-field label="La période commence le" for="start-day" error="startDay" help="1 = mois civil. 25 si le salaire tombe le 25, par exemple.">
                    <select id="start-day" wire:model="startDay" class="form-input">
                        @foreach (range(1, 28) as $d) <option value="{{ $d }}">{{ $d === 1 ? '1er (mois civil)' : $d.' du mois' }}</option> @endforeach
                    </select>
                </x-field>
                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" wire:model="trackPayer" class="mt-0.5 size-4 rounded border-stone-300 text-brand-600">
                    <span>
                        <span class="font-medium text-stone-800">Noter qui a payé</span>
                        <span class="block text-stone-500">Ajoute « Payé par » à la saisie et un récapitulatif par personne.</span>
                    </span>
                </label>
                <button type="submit" class="btn btn-primary w-full">Enregistrer</button>
            </form>

            <section class="card space-y-3 p-4 text-sm text-stone-600">
                <h2 class="font-display font-semibold text-stone-900">D'où viennent les chiffres</h2>
                <p>Des <strong>dépenses</strong> que vous saisissez (bouton + → « Une dépense », ou la page Budget), et des <strong>prix cochés</strong> dans les listes de courses.</p>
                <p>Un ticket relié à une liste remplace les prix cochés de cette liste : rien n'est compté deux fois.</p>
                <p>Rien n'est deviné : une dépense non saisie n'existe pas pour l'application.</p>
            </section>

            @if ($history->isNotEmpty())
                <section class="card p-4 text-sm">
                    <h2 class="font-display mb-2 font-semibold text-stone-900">Derniers changements de budget</h2>
                    <ul class="space-y-1 text-stone-600">
                        @foreach ($history as $change)
                            <li class="flex justify-between gap-2">
                                <span class="min-w-0 truncate">{{ $change->valid_from->locale('fr')->isoFormat('D MMM YYYY') }} · {{ $change->category?->name }}</span>
                                <span class="shrink-0 tabular-nums">{{ (float) $change->amount > 0 ? $tracker->money((float) $change->amount, 0) : 'aucun' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </aside>
    </div>
</div>
