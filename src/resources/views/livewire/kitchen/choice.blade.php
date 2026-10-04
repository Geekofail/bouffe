{{-- Lot 39 (39.3) : le choix des enfants, en grand. --}}
<div class="mx-auto max-w-5xl" data-child-choice>
    <div class="mb-4 flex items-center justify-between gap-3">
        <a href="{{ route('kitchen') }}" wire:navigate class="btn btn-ghost min-h-11"><x-icon name="chevron-left" class="size-5" /> Écran de cuisine</a>
    </div>

    @if ($this->done)
        @php $done = $this->done; @endphp
        <div class="card flex flex-col items-center gap-4 p-8 text-center" role="status">
            @if ($done->chosenRecipe?->photoUrl('large'))
                <img src="{{ $done->chosenRecipe->photoUrl('large') }}" alt="" class="aspect-[4/3] w-full max-w-md rounded-2xl object-cover">
            @endif
            <p class="font-display text-3xl font-semibold text-stone-900">C'est noté !</p>
            <p class="text-xl text-stone-700">{{ $done->chosenRecipe?->title }} pour le {{ $done->dayLabel() }}.</p>
            <p class="text-sm text-stone-500">{{ $done->planned_meal_id ? 'C\'est au planning.' : 'Les parents le verront dans les envies.' }}</p>
            <button type="button" wire:click="next" class="btn btn-secondary min-h-12 px-6 text-base">Terminé</button>
        </div>
    @elseif ($choice = $this->choice)
        @php $recipes = $choice->recipes(); @endphp
        <h1 class="mb-6 text-center font-display text-3xl font-semibold text-stone-900 sm:text-4xl">
            {{ $choice->person ? $choice->person->name.', choisis' : 'Choisissez' }} {{ $choice->person ? 'ton' : 'votre' }} {{ $choice->dayLabel() }}
        </h1>

        @if ($picked && ($recipe = $recipes->firstWhere('id', $picked)))
            <div class="card mx-auto flex max-w-xl flex-col items-center gap-5 p-6 text-center">
                @if ($recipe->photoUrl('large'))
                    <img src="{{ $recipe->photoUrl('large') }}" alt="" class="aspect-[4/3] w-full rounded-2xl object-cover">
                @endif
                <p class="text-2xl text-stone-800">{{ $choice->person ? 'Tu choisis' : 'Vous choisissez' }} <strong>{{ $recipe->title }}</strong> ?</p>
                <div class="flex w-full gap-3">
                    <button type="button" wire:click="back" class="btn btn-secondary min-h-14 flex-1 text-lg">Non, revenir</button>
                    <button type="button" wire:click="confirm" class="btn btn-primary min-h-14 flex-1 text-lg">Oui !</button>
                </div>
            </div>
        @else
            <ul @class(['grid gap-4', 'sm:grid-cols-2' => $recipes->count() <= 2, 'sm:grid-cols-3' => $recipes->count() >= 3])>
                @foreach ($recipes as $recipe)
                    <li wire:key="pick-{{ $recipe->id }}">
                        <button type="button" wire:click="pick({{ $recipe->id }})" data-pick
                                class="card flex h-full w-full flex-col overflow-hidden text-left ring-brand-400 transition hover:ring-4 focus-visible:ring-4">
                            @if ($recipe->photoUrl('large'))
                                <img src="{{ $recipe->photoUrl('large') }}" alt="" class="aspect-[4/3] w-full object-cover">
                            @else
                                <span class="flex aspect-[4/3] w-full items-center justify-center bg-brand-50 font-display text-6xl font-semibold text-brand-700" aria-hidden="true">{{ mb_strtoupper(mb_substr($recipe->title, 0, 1)) }}</span>
                            @endif
                            <span class="block p-4 text-center text-xl font-semibold text-stone-900">{{ $recipe->title }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    @else
        <div class="card p-8">
            <x-empty-state icon="smile" title="Rien à choisir pour l'instant">
                Un adulte prépare le choix dans Planning › Plus › Le choix des enfants.
            </x-empty-state>
        </div>
    @endif
</div>
