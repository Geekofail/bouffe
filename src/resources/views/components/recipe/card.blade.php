@props(['recipe', 'cost' => null, 'season' => null])
@php $foreign = $recipe->isForeign(); /* recette d'un foyer relié (26.1) */ @endphp

<article {{ $attributes->merge(['class' => 'card group relative flex flex-col overflow-hidden rounded-2xl transition hover:shadow-md hover:ring-brand-300']) }}>
    <a href="{{ route('recipes.show', $recipe) }}" wire:navigate class="block" tabindex="-1" aria-hidden="true">
        <div class="relative aspect-[3/2] overflow-hidden">
            @if ($recipe->photo_path)
                <img src="{{ $recipe->photoUrl('thumb') }}" alt="" loading="lazy"
                     class="size-full object-cover transition duration-300 group-hover:scale-105">
            @else
                {{-- Lot 29 (29.3) : une illustration du plat plutôt qu'une grande lettre. --}}
                <x-dish-illustration :recipe="$recipe" class="size-full" inner="h-[92%] w-auto transition duration-300 group-hover:scale-105" />
            @endif

            {{-- Étiquettes empilées dans un seul coin (lot 28, E5) : jamais superposées. --}}
            <div class="absolute top-2 left-2 flex max-w-[calc(100%-3.5rem)] flex-wrap gap-1">
                @if ($recipe->isArchived())
                    <span class="rounded-full bg-stone-900/70 px-2 py-0.5 text-xs font-medium text-white">Archivée</span>
                @elseif ($recipe->is_to_test)
                    <span class="rounded-full bg-white/90 px-2 py-0.5 text-xs font-medium text-stone-700">À tester</span>
                @endif
                {{-- De saison ce mois-ci (17.1) : signalé, jamais reproché. --}}
                @if (($season['status'] ?? null) === 'season')
                    <span class="flex items-center gap-1 rounded-full bg-herb-600/90 px-2 py-0.5 text-xs font-medium text-white" title="Tous ses fruits et légumes sont de saison">
                        <x-icon name="leaf" class="size-3" /> De saison
                    </span>
                @endif
            </div>
        </div>
    </a>

    @unless ($foreign)
    <button type="button" wire:click="toggleFavorite({{ $recipe->id }})"
            class="absolute top-2 right-2 rounded-full bg-white/90 p-1.5 shadow-sm transition hover:scale-110"
            title="{{ $recipe->is_favorite ? 'Retirer des favoris' : 'Ajouter aux favoris' }}">
        <x-icon :name="$recipe->is_favorite ? 'heart-solid' : 'heart'" @class(['size-5', 'text-brand-600' => $recipe->is_favorite, 'text-stone-500' => ! $recipe->is_favorite]) />
        <span class="sr-only">Favori</span>
    </button>
    @endunless

    <div class="flex flex-1 flex-col gap-1.5 p-3 sm:gap-2">
        <a href="{{ route('recipes.show', $recipe) }}" wire:navigate class="text-[15px] leading-snug font-semibold text-stone-900 hover:text-brand-700 sm:text-base">
            {{ $recipe->title }}
        </a>
        @if ($foreign)
            <p class="flex items-center gap-1 text-xs font-medium text-violet-700"><x-icon name="home" class="size-3.5" /> {{ $recipe->household?->name }}</p>
        @elseif (($recipe->visibility ?? 'private') !== 'private')
            <p class="flex items-center gap-1 text-xs text-stone-500" title="Visible de vos proches"><x-icon name="share" class="size-3.5" /> {{ $recipe->visibility === 'instance' ? 'Partagée avec toute l\'installation' : 'Partagée avec les foyers reliés' }}</p>
        @endif

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-stone-600">
            @if ($recipe->total_minutes)
                <span class="inline-flex items-center gap-1"><x-icon name="clock" class="size-3.5" /> {{ \App\Support\Duration::format($recipe->total_minutes) }}</span>
            @endif
            @if ($recipe->difficulty)
                <span>{{ $recipe->difficulty->label() }}</span>
            @endif
            @if ($recipe->ratings_avg_rating)
                <span class="inline-flex items-center gap-0.5 text-amber-700">
                    <x-icon name="star-solid" class="size-3.5" /> {{ number_format($recipe->ratings_avg_rating, 1, ',', '') }}
                </span>
            @endif
            @if ($cost?->isKnown() && $cost->perServing() !== null)
                {{-- 17.2 : coût estimé par portion, « ≥ » tant qu'il manque des prix (R17). --}}
                <span class="inline-flex items-center gap-1" title="Coût estimé par portion{{ $cost->missingLabel() ? ' · '.$cost->missingLabel() : '' }}">
                    <x-icon name="euro" class="size-3.5" />
                    {{ $cost->label(app(\App\Services\Pricing\PriceBook::class), $cost->perServing()) }}
                </span>
            @endif
        </div>

        @if ($recipe->tags->isNotEmpty())
            <div class="mt-auto flex flex-wrap gap-1 pt-1">
                @foreach ($recipe->tags->take(2) as $tag)
                    <x-badge :color="$tag->color">{{ $tag->name }}</x-badge>
                @endforeach
                @if ($recipe->tags->count() > 2)
                    <span class="text-xs text-stone-500">+{{ $recipe->tags->count() - 2 }}</span>
                @endif
            </div>
        @endif
    </div>
</article>
