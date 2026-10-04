{{-- Fiche recette : préparation, photos d'étapes, notes et source (lot 36). --}}
<section class="card h-fit p-5 lg:col-span-3">
    <h2 class="font-display mb-4 text-lg font-semibold text-stone-900">Préparation</h2>

    @if ($this->subRecipes->isNotEmpty())
        <p class="mb-4 rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-900">
            À préparer d’abord : {!! $this->subRecipes->map(fn ($sub) => '<a href="'.e(route('recipes.show', $sub['recipe'])).'" wire:navigate class="font-medium underline">'.e(mb_strtolower($sub['recipe']->title)).'</a>')->join(', ', ' et ') !!}.
        </p>
    @endif

    @if ($recipe->steps->isEmpty())
        <p class="text-sm text-stone-500">Aucune étape renseignée.</p>
    @else
        <ol class="space-y-4">
            @foreach ($recipe->steps as $step)
                @if ($step->group_name && ($loop->first || $step->group_name !== $recipe->steps[$loop->index - 1]->group_name))
                    <li class="pt-2 text-sm font-semibold tracking-wide text-stone-500 uppercase first:pt-0">{{ $step->group_name }}</li>
                @endif
                <li class="flex gap-3">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white">{{ $loop->iteration }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="pt-1 whitespace-pre-line text-stone-700">{{ $this->subRecipes->isEmpty() ? $step->instruction : app(\App\Services\Recipes\SubRecipes::class)->linkifyHtml($step->instruction, $this->subRecipes->pluck('recipe')) }}</p>
                        {{-- Notes du foyer (40.2). --}}
                        @foreach ($this->stepNotes->get($loop->iteration, collect()) as $stepNote)
                            <p wire:key="show-step-note-{{ $stepNote->id }}" class="mt-2 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-1.5 text-sm text-amber-900">
                                <x-icon name="note" class="mt-0.5 size-4 shrink-0 text-amber-700" /> <span>{{ $stepNote->note }}</span>
                            </p>
                        @endforeach
                        {{-- Photos de l'étape (31.2). --}}
                        @if ($this->stepPhotos->has($loop->iteration))
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($this->stepPhotos->get($loop->iteration) as $photo)
                                    <a href="{{ $photo->url('large') }}" target="_blank" rel="noopener" wire:key="step-photo-{{ $photo->id }}">
                                        <img src="{{ $photo->url('thumb') }}" alt="{{ $photo->caption ?: 'Photo de l\'étape '.$photo->step_number }}" loading="lazy" class="h-24 w-32 rounded-lg object-cover ring-1 ring-stone-200">
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif

    @if ($recipe->notes || $recipe->source)
        <div class="mt-6 space-y-3 border-t border-stone-200 pt-4 text-sm">
            @if ($recipe->notes)
                <div>
                    <h3 class="mb-1 font-semibold text-stone-800">Notes</h3>
                    <p class="whitespace-pre-line text-stone-600">{{ $recipe->notes }}</p>
                </div>
            @endif
            @if ($recipe->source)
                <p class="flex items-center gap-1.5 text-stone-500">
                    <x-icon name="link" class="size-4" /> Source :
                    @if ($recipe->sourceIsUrl())
                        <a href="{{ $recipe->source }}" target="_blank" rel="noopener noreferrer" class="truncate text-brand-700 underline">{{ parse_url($recipe->source, PHP_URL_HOST) }}</a>
                    @else
                        {{ $recipe->source }}
                    @endif
                </p>
            @endif
        </div>
    @endif
</section>
