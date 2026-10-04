<x-print-page :title="'Imprimer · '.$recipe->title" :back="route('recipes.show', $recipe)">
    <x-slot:options>
        <form method="GET" class="flex flex-wrap items-center gap-3 text-sm text-stone-600">
            <label class="flex items-center gap-1">
                Portions
                <input type="number" name="portions" min="0.5" step="0.5" max="50" value="{{ $servings }}" class="form-input w-20 py-1">
            </label>
            <label class="flex items-center gap-1.5">
                <input type="hidden" name="photo" value="0">
                <input type="checkbox" name="photo" value="1" @checked($withPhoto) class="form-checkbox" @disabled(! $recipe->photo_path)> Photo
            </label>
            <label class="flex items-center gap-1.5">
                <input type="hidden" name="notes" value="0">
                <input type="checkbox" name="notes" value="1" @checked($withNotes) class="form-checkbox"> Notes
            </label>
            <button type="submit" class="btn btn-secondary py-1.5">Appliquer</button>
        </form>
    </x-slot:options>

    <header class="mb-6 flex items-start gap-6">
        <div class="min-w-0 flex-1">
            <h1 class="text-3xl font-bold text-stone-900">{{ $recipe->title }}</h1>
            @if ($recipe->description)
                <p class="mt-1 text-stone-600">{{ $recipe->description }}</p>
            @endif
            <p class="mt-2 text-sm text-stone-500">
                {{ \App\Services\Planning\Appetites::label($servings) }}
                @if ($recipe->prep_minutes) · préparation {{ \App\Support\Duration::format($recipe->prep_minutes) }} @endif
                @if ($recipe->cook_minutes) · cuisson {{ \App\Support\Duration::format($recipe->cook_minutes) }} @endif
                @if ($recipe->rest_minutes) · repos {{ \App\Support\Duration::format($recipe->rest_minutes) }} @endif
                @if ($recipe->tags->isNotEmpty()) · {{ $recipe->tags->pluck('name')->join(', ') }} @endif
            </p>
        </div>
        @if ($withPhoto)
            <img src="{{ $recipe->photoUrl('thumb') }}" alt="" class="size-32 shrink-0 rounded-lg object-cover">
        @endif
    </header>

    <div class="grid grid-cols-3 gap-8">
        <section class="col-span-1">
            <h2 class="mb-2 border-b border-stone-300 pb-1 text-sm font-bold tracking-wide text-stone-700 uppercase">Ingrédients</h2>
            @forelse ($groups as $group => $lines)
                @if ($group !== '')
                    <h3 class="mt-3 mb-1 text-sm font-semibold text-stone-700">{{ $group }}</h3>
                @endif
                <ul class="space-y-1 text-sm">
                    @foreach ($lines as $line)
                        <li class="flex gap-2">
                            <span class="font-semibold tabular-nums">{{ $line['parts']['quantity'] }}</span>
                            <span>{{ $line['parts']['name'] }}@if ($line['preparation']), {{ $line['preparation'] }} @endif
                                @if ($line['optional']) (facultatif) @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @empty
                <p class="text-sm text-stone-500">Aucun ingrédient.</p>
            @endforelse
        </section>

        <section class="col-span-2">
            <h2 class="mb-2 border-b border-stone-300 pb-1 text-sm font-bold tracking-wide text-stone-700 uppercase">Préparation</h2>
            @if ($recipe->steps->isEmpty())
                <p class="text-sm text-stone-500">Aucune étape.</p>
            @else
                <ol class="space-y-3">
                    @foreach ($recipe->steps as $step)
                        @if ($step->group_name && ($loop->first || $step->group_name !== $recipe->steps[$loop->index - 1]->group_name))
                            <li class="pt-1 text-xs font-bold tracking-wide uppercase">{{ $step->group_name }}</li>
                        @endif
                        <li class="flex gap-3 break-inside-avoid">
                            <span class="flex size-6 shrink-0 items-center justify-center rounded-full border border-stone-400 text-xs font-bold">{{ $loop->iteration }}</span>
                            <p class="whitespace-pre-line">{{ $step->instruction }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif

            @if ($withNotes && ($recipe->notes || $cookNotes->isNotEmpty()))
                <div class="mt-5 border-t border-stone-300 pt-3 text-sm">
                    <h3 class="mb-1 font-semibold">Notes</h3>
                    @if ($recipe->notes) <p class="whitespace-pre-line text-stone-700">{{ $recipe->notes }}</p> @endif
                    @foreach ($cookNotes as $note)
                        <p class="text-stone-600">« {{ $note->note }} » — {{ $note->user?->name }}, {{ $note->created_at->locale('fr')->isoFormat('D MMM YYYY') }}</p>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <footer class="mt-8 border-t border-stone-200 pt-2 text-xs text-stone-500">
        Bouffe · {{ $recipe->source ?: 'recette du carnet' }} · imprimé le {{ now()->locale('fr')->isoFormat('D MMMM YYYY') }}
    </footer>
</x-print-page>
