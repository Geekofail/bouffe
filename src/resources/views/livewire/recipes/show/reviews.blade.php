{{-- Fiche recette : avis du foyer et des proches (lot 36). --}}
{{-- ============================================================ Avis du foyer --}}
<section class="card mt-6 p-5">
    <h2 class="font-display mb-4 text-lg font-semibold text-stone-900">Nos avis</h2>
    @if ($foreign)
        <p class="-mt-2 mb-4 text-sm text-stone-500">Vos notes et commentaires sont visibles de {{ $recipe->household?->name }} : un conseil (« une pincée de muscade en plus ») leur sera utile.</p>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($this->householdRatings as $entry)
            @php $isMe = $entry['user']->id === auth()->id(); @endphp
            <div wire:key="rating-{{ $entry['user']->id }}" @class(['rounded-xl p-4', 'bg-brand-50/60 ring-1 ring-brand-100' => $isMe, 'bg-stone-50' => ! $isMe])>
                <div class="mb-2 flex items-center justify-between gap-2">
                    <span class="font-semibold text-stone-800">{{ $entry['user']->name }}{{ $isMe ? ' (vous)' : '' }}</span>

                    <div class="flex" @if ($isMe) role="radiogroup" aria-label="Votre note" @endif>
                        @for ($star = 1; $star <= 5; $star++)
                            @php $filled = $isMe ? $star <= $myRating : $star <= ($entry['rating']?->rating ?? 0); @endphp
                            @if ($isMe)
                                <button type="button" wire:click="rate({{ $star === $myRating ? 0 : $star }})" class="p-0.5 transition hover:scale-110"
                                        title="{{ $star === $myRating ? 'Retirer ma note' : $star.' étoile'.($star > 1 ? 's' : '') }}">
                                    <x-icon :name="$filled ? 'star-solid' : 'star'" @class(['size-6', 'text-amber-500' => $filled, 'text-stone-300' => ! $filled]) />
                                </button>
                            @else
                                <x-icon :name="$filled ? 'star-solid' : 'star'" @class(['size-5', 'text-amber-500' => $filled, 'text-stone-300' => ! $filled]) />
                            @endif
                        @endfor
                    </div>
                </div>

                @if ($isMe)
                    <form wire:submit="saveComment" class="flex flex-col gap-2 sm:flex-row">
                        <input type="text" wire:model="myComment" placeholder="Un commentaire ? (trop salé, à refaire…)" class="form-input">
                        <button type="submit" class="btn btn-secondary shrink-0">Enregistrer</button>
                    </form>
                    @error('myComment') <p class="form-error">{{ $message }}</p> @enderror
                @elseif ($entry['rating']?->comment)
                    <p class="text-sm text-stone-600 italic">« {{ $entry['rating']->comment }} »</p>
                @elseif (! $entry['rating'])
                    <p class="text-sm text-stone-500">Pas encore noté.</p>
                @endif
            </div>
        @endforeach
    </div>
</section>

{{-- ============================================================ Avis des proches (26.3) --}}
@if ($this->linkedReviews->isNotEmpty())
    <section class="card mt-6 p-5">
        <h2 class="font-display mb-4 flex items-center gap-2 text-lg font-semibold text-stone-900"><x-icon name="heart" class="size-5 text-brand-600" /> Avis des proches</h2>
        <ul class="grid gap-3 md:grid-cols-2">
            @foreach ($this->linkedReviews as $review)
                <li wire:key="lr-{{ $review->id }}" class="rounded-xl bg-stone-50 p-4">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <span class="font-semibold text-stone-800">{{ $review->user?->name }} <span class="font-normal text-stone-500">· {{ $review->household?->name }}</span></span>
                        <span class="flex" aria-label="{{ $review->rating }} étoile{{ $review->rating > 1 ? 's' : '' }} sur 5">
                            @for ($star = 1; $star <= 5; $star++)
                                <x-icon :name="$star <= $review->rating ? 'star-solid' : 'star'" class="size-4 {{ $star <= $review->rating ? 'text-amber-500' : 'text-stone-300' }}" />
                            @endfor
                        </span>
                    </div>
                    @if ($review->comment)
                        <p class="text-sm text-stone-600 italic">« {{ $review->comment }} »</p>
                    @endif
                    <p class="mt-1 text-xs text-stone-500">{{ $review->updated_at?->locale('fr')->diffForHumans() }}</p>
                </li>
            @endforeach
        </ul>
    </section>
@endif
