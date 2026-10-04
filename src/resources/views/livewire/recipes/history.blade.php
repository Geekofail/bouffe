<div>
    <a href="{{ route('recipes.show', $recipe) }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> {{ $recipe->title }}
    </a>

    <x-page-header title="Historique" subtitle="Chaque enregistrement depuis « Modifier » garde une version complète de la recette (sans les photos)." />

    @if ($this->revisions->isEmpty())
        <div class="card">
            <x-empty-state icon="clock" title="Aucune modification enregistrée">
                L'historique commence à la prochaine modification de la recette : sa version actuelle sera gardée comme « version d'origine ».
            </x-empty-state>
        </div>
    @else
        @unless ($this->latestIsCurrent)
            <p class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">La recette a changé depuis la dernière version gardée (par exemple en reprenant l'original d'un proche).</p>
        @endunless
        <ol class="space-y-3">
            @foreach ($this->revisions as $revision)
                @php $snapshot = $revision->snapshot; $open = $openId === $revision->id; @endphp
                <li wire:key="revision-{{ $revision->id }}" class="card p-4">
                    <div class="flex flex-wrap items-start gap-3">
                        <span @class(['mt-1 size-2.5 shrink-0 rounded-full', 'bg-brand-600' => $loop->first, 'bg-stone-300' => ! $loop->first]) aria-hidden="true"></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-stone-900">
                                {{ $revision->summary }}
                                @if ($loop->first && $this->latestIsCurrent) <x-badge color="green">Version actuelle</x-badge> @endif
                            </p>
                            <p class="text-sm text-stone-500">
                                {{ $revision->created_at?->locale('fr')->isoFormat('dddd D MMMM YYYY [à] HH:mm') }}
                                @if ($revision->user) · {{ $revision->user->name }} @endif
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            <button type="button" wire:click="toggle({{ $revision->id }})" class="btn btn-ghost py-1.5 text-sm" aria-expanded="{{ $open ? 'true' : 'false' }}">
                                {{ $open ? 'Masquer' : 'Voir' }}
                            </button>
                            @if ((! $loop->first || ! $this->latestIsCurrent) && auth()->user()->canEdit())
                                <button type="button" wire:click="restore({{ $revision->id }})"
                                        wire:confirm="Revenir à cette version ? La version actuelle reste dans l'historique."
                                        class="btn btn-secondary py-1.5 text-sm"><x-icon name="undo" class="size-4" /> Revenir à cette version</button>
                            @endif
                        </div>
                    </div>

                    @if ($open)
                        <div class="mt-4 grid gap-5 border-t border-stone-200 pt-4 text-sm md:grid-cols-5">
                            <div class="md:col-span-2">
                                <p class="font-semibold text-stone-900">{{ $snapshot['title'] ?? '' }}</p>
                                <p class="mb-2 text-stone-500">
                                    {{ $snapshot['servings'] ?? '' }} portions
                                    @if (! empty($snapshot['prep_minutes'])) · préparation {{ \App\Support\Duration::format($snapshot['prep_minutes']) }} @endif
                                    @if (! empty($snapshot['cook_minutes'])) · cuisson {{ \App\Support\Duration::format($snapshot['cook_minutes']) }} @endif
                                    @if (! empty($snapshot['rest_minutes'])) · repos {{ \App\Support\Duration::format($snapshot['rest_minutes']) }} @endif
                                </p>
                                @if (! empty($snapshot['tags'])) <p class="mb-2 text-stone-500">{{ collect($snapshot['tags'])->pluck('name')->join(', ') }}</p> @endif
                                <ul class="space-y-0.5">
                                    @foreach ($snapshot['ingredients'] ?? [] as $line)
                                        <li>
                                            @if ($line['quantity'] !== null) <span class="font-semibold tabular-nums">{{ str_replace('.', ',', $line['quantity']) }} {{ $line['unit'] }}</span> @endif
                                            {{ $line['name'] }}@if (! empty($line['preparation'])), {{ $line['preparation'] }} @endif
                                            @if (! empty($line['is_optional'])) <span class="text-stone-500">(facultatif)</span> @endif
                                        </li>
                                    @endforeach
                                    @foreach ($snapshot['components'] ?? [] as $component)
                                        <li class="text-stone-600">+ {{ $component['title'] }}</li>
                                    @endforeach
                                </ul>
                            </div>
                            <ol class="list-decimal space-y-1.5 pl-5 md:col-span-3">
                                @foreach ($snapshot['steps'] ?? [] as $step)
                                    <li class="whitespace-pre-line text-stone-700">{{ $step['instruction'] }}</li>
                                @endforeach
                            </ol>
                            @if (! empty($snapshot['notes']))
                                <p class="whitespace-pre-line text-stone-600 md:col-span-5"><span class="font-semibold text-stone-800">Notes :</span> {{ $snapshot['notes'] }}</p>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</div>
