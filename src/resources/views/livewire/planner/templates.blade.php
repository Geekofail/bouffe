<div>
    <a href="{{ route('planner.week', ['semaine' => $this->weekStart()->toDateString()]) }}" wire:navigate
       class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Planning
    </a>

    <x-page-header title="Semaines types"
                   :subtitle="'Semaine en cours : du '.$this->weekStart()->locale('fr')->isoFormat('D MMMM').' ('.$this->existingCount.' repas)'">
        <x-slot:actions>
            <button type="button" wire:click="openSave" class="btn btn-primary">
                <x-icon name="plus" class="size-4" /> Enregistrer cette semaine
            </button>
        </x-slot:actions>
    </x-page-header>

    @if ($this->templates->isEmpty())
        <div class="card">
            <x-empty-state icon="calendar" title="Aucune semaine type">
                Composez une semaine qui vous plaît sur le planning, puis enregistrez-la ici pour la réutiliser.
            </x-empty-state>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($this->templates as $template)
                <section wire:key="tpl-{{ $template->id }}" class="card flex flex-col p-4">
                    <div class="mb-2 flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-display font-semibold text-stone-900">{{ $template->name }}</h2>
                            <p class="text-xs text-stone-500">{{ $template->summary() }}</p>
                        </div>
                        <button type="button" wire:click="delete({{ $template->id }})"
                                wire:confirm="Supprimer la semaine type « {{ $template->name }} » ?"
                                class="btn btn-ghost px-2 hover:text-red-600" title="Supprimer">
                            <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer</span>
                        </button>
                    </div>

                    @if ($template->notes)
                        <p class="mb-2 text-sm text-stone-600">{{ $template->notes }}</p>
                    @endif

                    <ul class="mb-3 flex-1 space-y-1 text-sm text-stone-600">
                        @foreach ($template->meals->groupBy('weekday') as $weekday => $meals)
                            <li wire:key="tpl-{{ $template->id }}-d{{ $weekday }}">
                                <span class="font-medium text-stone-700">{{ \App\Livewire\Planner\Templates::weekdayName($weekday) }}</span>
                                — {{ $meals->map(fn ($m) => $m->label())->join(', ') }}
                            </li>
                        @endforeach
                    </ul>

                    <button type="button" wire:click="openApply({{ $template->id }})" class="btn btn-secondary self-start">
                        <x-icon name="calendar" class="size-4" /> Appliquer à cette semaine
                    </button>
                </section>
            @endforeach
        </div>
    @endif

    {{-- ============================================================ Enregistrer --}}
    <x-modal :show="$showSave" title="Enregistrer la semaine comme modèle" close="$set('showSave', false)">
        <div class="space-y-4">
            <p class="text-sm text-stone-600">
                Les {{ $this->existingCount }} repas de la semaine du {{ $this->weekStart()->locale('fr')->isoFormat('D MMMM') }}
                seront retenus par jour de la semaine et par créneau.
            </p>

            <x-field label="Nom" for="tpl-name" error="name">
                <input id="tpl-name" type="text" wire:model="name" placeholder="ex. Semaine rapide"
                       @class(['form-input', 'form-input-error' => $errors->has('name')]) autofocus>
            </x-field>

            <x-field label="Note" for="tpl-notes" error="notes" optional>
                <textarea id="tpl-notes" wire:model="notes" rows="2" class="form-input"
                          placeholder="À quoi sert cette semaine type ?"></textarea>
            </x-field>
        </div>

        <x-slot:footer>
            <button type="button" wire:click="$set('showSave', false)" class="btn btn-secondary">Annuler</button>
            <button type="button" wire:click="save" class="btn btn-primary">Enregistrer</button>
        </x-slot:footer>
    </x-modal>

    {{-- ============================================================ Appliquer --}}
    <x-modal :show="$applyingId !== null" :title="'Appliquer « '.($this->applying?->name ?? '').' »'" close="closeApply">
        @if ($report)
            <div class="space-y-3 text-sm">
                <p class="font-semibold text-herb-700">{{ $report['placed'] }} repas placé(s).</p>

                @if ($report['removed'] > 0)
                    <p class="text-stone-600">{{ $report['removed'] }} repas retiré(s) de la semaine.</p>
                @endif

                @if ($report['warnings'])
                    <div class="rounded-lg bg-amber-50 p-3 text-amber-900">
                        <p class="font-semibold">À vérifier pour les invités :</p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5">
                            @foreach ($report['warnings'] as $warning) <li>{{ $warning }}</li> @endforeach
                        </ul>
                    </div>
                @endif

                @if ($report['skipped'])
                    <div class="rounded-lg bg-stone-50 p-3 text-stone-600">
                        <p class="font-semibold">Non placés :</p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5">
                            @foreach ($report['skipped'] as $skip) <li>{{ $skip }}</li> @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @else
            <div class="space-y-3 text-sm text-stone-700">
                <p>Semaine du <strong>{{ $this->weekStart()->locale('fr')->isoFormat('D MMMM') }}</strong>, qui contient {{ $this->existingCount }} repas.</p>

                <label class="flex items-start gap-2">
                    <input type="radio" wire:model="mode" value="fill" class="mt-1">
                    <span><strong>Remplir les cases vides</strong> — ce qui est déjà planifié n'est pas touché.</span>
                </label>
                <label class="flex items-start gap-2">
                    <input type="radio" wire:model="mode" value="replace" class="mt-1">
                    <span><strong>Remplacer la semaine</strong> — les {{ $this->existingCount }} repas existants sont d'abord retirés.</span>
                </label>

                <p class="text-stone-500">
                    Les portions sont recalculées d'après les convives de cette semaine, et les recettes archivées entre-temps sont signalées.
                </p>
            </div>
        @endif

        <x-slot:footer>
            @if ($report)
                <a href="{{ route('planner.week', ['semaine' => $this->weekStart()->toDateString()]) }}" wire:navigate class="btn btn-primary">
                    Voir le planning
                </a>
            @else
                <button type="button" wire:click="closeApply" class="btn btn-secondary">Annuler</button>
                <button type="button" wire:click="apply" @class(['btn', 'btn-danger' => $mode === 'replace', 'btn-primary' => $mode !== 'replace'])>
                    {{ $mode === 'replace' ? 'Remplacer la semaine' : 'Remplir les cases vides' }}
                </button>
            @endif
        </x-slot:footer>
    </x-modal>
</div>
