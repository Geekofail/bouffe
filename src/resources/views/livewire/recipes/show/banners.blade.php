{{-- Fiche recette : bandeaux — recette d'un proche, copie d'une recette de proche, archivée (lot 36 : découpé de show.blade.php). --}}
{{-- ============================================================ Recette d'un foyer relié (26.1, 26.2) --}}
@if ($foreign)
    <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-violet-50 px-4 py-3 text-sm text-violet-900 ring-1 ring-violet-200">
        <x-icon name="home" class="size-5 shrink-0" />
        <p class="min-w-0 flex-1 basis-56">
            Recette de <strong>{{ $recipe->household?->name }}</strong>{{ $recipe->author ? ' ('.$recipe->author->name.')' : '' }}, partagée avec vous.
            Vous pouvez la planifier telle quelle, la cuisiner, la noter — ou la copier pour la modifier.
        </p>
        @if ($this->myCopy)
            <a href="{{ route('recipes.show', $this->myCopy) }}" wire:navigate class="btn btn-secondary py-1.5">Voir notre copie</a>
        @elseif (auth()->user()->canEdit())
            <button type="button" wire:click="copyToMine" class="btn btn-primary py-1.5"><x-icon name="duplicate" class="size-4" /> Copier dans notre carnet</button>
        @endif
    </div>
@endif

{{-- ============================================================ Copie d'une recette de proche (R31) --}}
@if ($info = $this->originInfo)
    <div class="mb-4 rounded-xl bg-stone-100 px-4 py-3 text-sm text-stone-700">
        <div class="flex flex-wrap items-center gap-3">
            <x-icon name="duplicate" class="size-5 shrink-0 text-stone-500" />
            <p class="min-w-0 flex-1 basis-56">
                Copiée de la recette de <strong>{{ $info['household'] ?? 'un foyer' }}</strong>{{ $info['synced'] ? ', le '.$info['synced']->locale('fr')->isoFormat('D MMMM YYYY') : '' }}.
                @if ($info['origin'])
                    <a href="{{ route('recipes.show', $info['origin']) }}" wire:navigate class="font-medium text-brand-700 hover:underline">Voir l'original</a>
                @elseif (! $info['available'])
                    <span class="text-stone-500">L'original n'est plus partagé.</span>
                @endif
            </p>
            @if ($info['updated'] && auth()->user()->canEdit())
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-amber-200"><x-icon name="info" class="size-3.5" /> L'original a changé</span>
                <button type="button" wire:click="$toggle('showOriginDiff')" class="btn btn-secondary py-1 text-sm">{{ $showOriginDiff ? 'Masquer' : 'Voir les différences' }}</button>
            @endif
        </div>

        @if ($info['diff'])
            <div class="mt-3 space-y-3 border-t border-stone-200 pt-3">
                @foreach (\App\Services\Linked\RecipeCopier::SECTIONS as $key => $label)
                    @php $section = $info['diff'][$key]; @endphp
                    @if ($section['changed'])
                        <div wire:key="diff-{{ $key }}" class="rounded-lg bg-white p-3 ring-1 ring-stone-200">
                            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <h3 class="font-medium text-stone-900">{{ $label }}</h3>
                                <button type="button" wire:click="applyOrigin('{{ $key }}')" wire:confirm="Remplacer « {{ mb_strtolower($label) }} » de votre copie par ceux de l'original ?" class="btn btn-primary py-1 text-sm">Reprendre l'original</button>
                            </div>
                            <div class="grid gap-3 md:grid-cols-2">
                                <div>
                                    <p class="mb-1 text-xs font-semibold tracking-wide text-stone-500 uppercase">Notre copie</p>
                                    <ul class="space-y-0.5 text-sm">
                                        @foreach ($section['mine'] as $line)
                                            <li @class(['rounded px-1', 'bg-red-50 text-red-800' => ! in_array($line, $section['theirs'], true)])>{{ $line }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                                <div>
                                    <p class="mb-1 text-xs font-semibold tracking-wide text-stone-500 uppercase">L'original aujourd'hui</p>
                                    <ul class="space-y-0.5 text-sm">
                                        @foreach ($section['theirs'] as $line)
                                            <li @class(['rounded px-1', 'bg-emerald-50 text-emerald-800' => ! in_array($line, $section['mine'], true)])>{{ $line }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="dismissOrigin" class="btn btn-ghost text-sm">Garder notre version telle quelle</button>
                    <span class="text-xs text-stone-500">Rien n'est remplacé sans votre accord.</span>
                </div>
            </div>
        @endif
    </div>
@endif

@if ($recipe->isArchived())
    <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-stone-800 px-4 py-3 text-sm text-white">
        <x-icon name="archive" class="size-5" />
        <span class="flex-1">Recette archivée le {{ $recipe->archived_at->isoFormat('D MMMM YYYY') }}.</span>
        <button type="button" wire:click="toggleArchive" class="font-semibold underline">Désarchiver</button>
    </div>
@endif
