<div class="pb-4">
    <a href="{{ route('recipes.index', $foreign ? ['source' => 'proches'] : []) }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> {{ $foreign ? 'Recettes des proches' : 'Recettes' }}
    </a>

    @include('livewire.recipes.show.banners')

    @include('livewire.recipes.show.header')

    {{-- ============================================================ Ingrédients & étapes --}}
    <div class="mt-6 grid gap-6 lg:grid-cols-5">
        @include('livewire.recipes.show.ingredients')

        @include('livewire.recipes.show.steps')
    </div>

    {{-- ============================================================ Photos (31.2) --}}
    <livewire:recipes.photos :recipe="$recipe" wire:key="recipe-photos-{{ $recipe->id }}" />

    @if (! $foreign && auth()->user()->canEdit())
        <livewire:recipes.recipe-collections :recipe="$recipe" wire:key="recipe-collections-{{ $recipe->id }}" />
        <livewire:recipes.share-links :recipe="$recipe" wire:key="recipe-share-{{ $recipe->id }}" />
        @if ($this->assistantAvailable)
            <livewire:recipes.assistant-transform :recipe="$recipe" wire:key="recipe-assistant-{{ $recipe->id }}" />
        @endif
    @endif

    @include('livewire.recipes.show.reviews')

    @include('livewire.recipes.show.plan-modal')
</div>
