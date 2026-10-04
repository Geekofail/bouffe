{{-- Élément d'une case du planning (cliquable, déplaçable) --}}
@props(['meal', 'conflict' => null, 'stockConflict' => null, 'dimmed' => false])

@php
    $styles = match ($meal->type) {
        \App\Enums\MealType::Recipe => 'bg-white ring-stone-200 hover:ring-brand-300',
        \App\Enums\MealType::Leftover => 'bg-sky-50 ring-sky-100 hover:ring-sky-300 text-sky-900',
        \App\Enums\MealType::Free => 'bg-stone-100 ring-stone-200 hover:ring-stone-300 italic text-stone-600',
    };
    $recipe = $meal->eatenRecipe();
    // Lot 29 (29.5) : le type de plat en couleur douce, la même que celle des illustrations.
    $courseStyle = match ($meal->course) {
        \App\Enums\Course::Starter, \App\Enums\Course::Aperitif => 'bg-course-entree text-course-entree-ink',
        \App\Enums\Course::Dessert => 'bg-course-dessert text-course-dessert-ink',
        default => 'bg-course-plat text-course-plat-ink',
    };
@endphp

<button type="button" wire:click="selectMeal({{ $meal->id }})"
        @class(['flex w-full cursor-grab items-center gap-2 rounded-xl px-1.5 py-1.5 text-left text-sm shadow-xs ring-1 transition active:cursor-grabbing', $styles, 'opacity-40' => $dimmed || $meal->skipped_at])
        title="{{ $meal->label() }}{{ $meal->comment ? ' — '.$meal->comment : '' }}{{ $conflict ? ' — ⚠ '.implode(' ; ', $conflict['messages']) : '' }}">
    @if ($meal->isRecipe() && $recipe?->photo_path)
        <img src="{{ $recipe->photoUrl('thumb') }}" alt="" class="size-10 shrink-0 rounded-lg object-cover xl:size-8" loading="lazy">
    @elseif ($meal->isRecipe() && $recipe)
        <x-dish-illustration :recipe="$recipe" :course="$meal->course" class="size-10 rounded-lg xl:size-8" />
    @elseif ($meal->isLunchbox())
        <x-icon name="lunchbox" class="ml-1 size-4 shrink-0 text-sky-600" />
    @elseif ($meal->isLeftover())
        <x-icon name="archive" class="ml-1 size-4 shrink-0 text-sky-600" />
    @endif

    <span class="min-w-0 flex-1">
        @if ($meal->course)
            <span class="mb-0.5 inline-block rounded-full px-1.5 text-[10px] leading-4 font-semibold not-italic {{ $courseStyle }}">{{ $meal->course->label() }}</span>
        @endif
        <span @class(['block leading-snug break-words', 'line-through decoration-stone-400' => false, 'font-medium text-stone-900' => $meal->isRecipe()])>
            {{ $meal->isLunchbox() ? $meal->lunchboxLabel() : ($meal->isLeftover() ? 'Restes' : $meal->label()) }}
        </span>
        @if ($meal->isLeftover())
            <span class="block truncate text-xs text-sky-700">{{ $recipe?->title ?? $meal->leftoverOf?->free_text }}</span>
        @endif
        @if ($meal->comment)
            <span class="block truncate text-xs text-stone-500 not-italic">{{ $meal->comment }}</span>
        @endif
    </span>

    <span class="flex shrink-0 flex-col items-end gap-0.5">
        @unless ($meal->isFree())
            <span class="flex items-center gap-0.5 text-xs text-stone-500 tabular-nums not-italic" title="{{ \App\Services\Planning\Appetites::label($meal->servings) }}">
                <x-icon name="users" class="size-3" />{{ \App\Services\Planning\Appetites::format($meal->servings) }}
            </span>
        @endunless
        @if ($stockConflict)
            <span title="Stock déjà prévu pour un autre repas : {{ collect($stockConflict)->pluck('ingredient')->join(', ') }}"><x-icon name="pantry" class="size-4 text-amber-500" /></span>
        @endif
        @if ($conflict)
            <x-icon name="warning" @class(['size-4', 'text-red-600' => $conflict['level'] === 'danger', 'text-amber-500' => $conflict['level'] !== 'danger']) />
        @endif
        @if ($meal->cookLabel())
            <span class="rounded-full bg-violet-100 px-1.5 text-[10px] font-semibold text-violet-800 not-italic"
                  title="Cuisiné par {{ $meal->cookLabel() }}">{{ $meal->cook_together ? '2' : mb_substr($meal->cookLabel(), 0, 1) }}</span>
        @endif
        @if ($meal->skipped_at)
            <span class="text-[10px] font-semibold text-stone-500 not-italic" title="Pas mangé">pas fait</span>
        @elseif ($meal->cooked_at)
            <x-icon name="success" class="size-4 text-herb-600" />
        @elseif ($meal->prepared_at)
            <span title="Cuisiné à l'avance, au stock"><x-icon name="fire" class="size-4 text-orange-500" /></span>
        @endif
    </span>
</button>
