@php
    /** @var array $row */
    $recipe = $row['recipe'];
    $url = route('recipes.show', ['recipe' => $recipe, 'portions' => $this->servings]);
    $percent = (int) round($row['coverage'] * 100);
@endphp
<article wire:key="suggestion-{{ $recipe->id }}" class="card flex overflow-hidden">
    <a href="{{ $url }}" wire:navigate class="relative hidden w-24 shrink-0 sm:block" tabindex="-1" aria-hidden="true">
        @if ($recipe->photo_path)
            <img src="{{ $recipe->photoUrl('thumb') }}" alt="" loading="lazy" class="absolute inset-0 size-full object-cover">
        @else
            <x-dish-illustration :recipe="$recipe" class="absolute inset-0 size-full" inner="w-[70%] h-auto" />
        @endif
    </a>

    <div class="flex min-w-0 flex-1 flex-col gap-1.5 p-3">
        <div class="flex items-start justify-between gap-2">
            <a href="{{ $url }}" wire:navigate class="font-semibold leading-snug text-stone-900 hover:text-brand-700">{{ $recipe->title }}</a>
            @if ($recipe->is_favorite)
                <x-icon name="heart-solid" class="size-4 shrink-0 text-brand-600" />
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-stone-500">
            <span class="inline-flex items-center gap-1.5" title="{{ $percent }} % des ingrédients disponibles">
                <span class="h-1.5 w-12 overflow-hidden rounded-full bg-stone-200"><span @class(['block h-full rounded-full', 'bg-herb-500' => $percent === 100, 'bg-amber-400' => $percent < 100]) style="width: {{ $percent }}%"></span></span>
                {{ $row['available'] }}/{{ $row['counted'] }} ingrédients
            </span>
            @if ($recipe->total_minutes)
                <span class="inline-flex items-center gap-1"><x-icon name="clock" class="size-3.5" /> {{ \App\Support\Duration::format($recipe->total_minutes) }}</span>
            @endif
            @if ($recipe->ratings_avg_rating)
                <span class="inline-flex items-center gap-0.5 text-amber-700"><x-icon name="star-solid" class="size-3.5" /> {{ number_format($recipe->ratings_avg_rating, 1, ',', '') }}</span>
            @endif
        </div>

        @if ($row['urgent'] > 0)
            <p><x-badge color="green">♻ utilise {{ $row['urgent'] }} produit{{ $row['urgent'] > 1 ? 's' : '' }} à consommer vite</x-badge></p>
        @endif
        @if (($row['substitutes_text'] ?? '') !== '')
            <p class="text-sm text-herb-800" data-substitute>Avec {{ $row['substitutes_text'] }}</p>
        @endif
        @if ($row['missing_text'] !== '')
            <p class="text-sm text-amber-800">Manque : {{ $row['missing_text'] }}</p>
        @endif
        @if ($row['recent'])
            <p class="text-xs text-stone-500">Mangée ces deux dernières semaines</p>
        @endif

        <div class="mt-auto flex flex-wrap gap-1.5 pt-1">
            <button type="button" wire:click="openPlan({{ $recipe->id }})" class="btn btn-primary px-2.5 py-1 text-xs">
                <x-icon name="calendar" class="size-3.5" /> Planifier
            </button>
            @if ($row['missing'] !== [])
                <button type="button" wire:click="addMissing({{ $recipe->id }})" class="btn btn-secondary px-2.5 py-1 text-xs">
                    <x-icon name="cart" class="size-3.5" /> Ajouter les manquants
                </button>
            @endif
        </div>
    </div>
</article>
