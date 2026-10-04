{{-- Écran de cuisine : les repas d'un jour, par créneau. --}}
<ul class="divide-y divide-stone-100">
    @foreach ($rows as $row)
        <li wire:key="k-{{ $date->toDateString() }}-{{ $row['slot']->id }}" class="py-3 first:pt-0 last:pb-0">
            <p class="flex flex-wrap items-center gap-x-2 text-sm font-semibold tracking-wide text-stone-500 uppercase">
                {{ $row['slot']->name }}
                @if ($row['occasion'])
                    <span class="font-medium tracking-normal text-violet-700 normal-case">· {{ $row['occasion'] }}</span>
                @endif
            </p>
            {{-- Lot 41 (41.3) : un repas de plusieurs plats se cuisine ensemble, étapes réparties. --}}
            @if ($big && $row['meals']->filter(fn ($m) => ! $m->isFree() && $m->eatenRecipe() && ! $m->cooked_at)->count() >= 2)
                <a href="{{ route('planner.cook', ['date' => $date->toDateString(), 'slot' => $row['slot']->id]) }}"
                   class="mt-1 mb-1 inline-flex min-h-11 items-center gap-1.5 text-base font-medium text-brand-700 hover:underline">
                    <x-icon name="fire" class="size-5" /> Cuisiner tout le repas · qui fait quoi
                </a>
            @endif
            {{-- Lot 39 (39.2) : qui mange à la cantine ce midi, et ce qu'on y sert. --}}
            @foreach ($row['canteen'] ?? [] as $entry)
                <p class="flex items-center gap-2 py-1 text-base text-sky-900">
                    <x-icon name="school" class="size-5 shrink-0" />
                    <span><span class="font-medium">{{ $entry['person']->name }}</span> à la cantine{{ $entry['label'] ? ' : '.$entry['label'] : '' }}</span>
                </p>
            @endforeach
            @forelse ($row['meals'] as $meal)
                @php $recipe = $meal->eatenRecipe(); @endphp
                <div wire:key="k-meal-{{ $meal->id }}" class="flex flex-wrap items-center gap-3 py-1.5">
                    @if ($big && $recipe)
                        @if ($recipe->photo_path)
                            <img src="{{ $recipe->photoUrl('thumb') }}" alt="" class="size-16 shrink-0 rounded-xl object-cover">
                        @else
                            <x-dish-illustration :recipe="$recipe" :course="$meal->course" class="size-16 rounded-xl" />
                        @endif
                    @endif
                    <div class="min-w-0 flex-1 basis-48">
                        <p @class(['font-semibold', 'text-3xl' => $big, 'text-xl' => ! $big, 'text-stone-900' => ! $meal->cooked_at, 'text-stone-500 line-through' => $meal->cooked_at])>
                            {{ $meal->label() }}
                        </p>
                        <p class="text-base text-stone-500">
                            @if ($meal->course && $meal->course !== \App\Enums\Course::Main) {{ $meal->course->label() }} · @endif
                            {{ \App\Services\Planning\Appetites::label($meal->servings) }}
                            @if ($recipe && $meal->isRecipe() && $recipe->total_minutes) · {{ \App\Support\Duration::format($recipe->total_minutes) }} @endif
                            @if ($meal->cooked_at) · mangé @endif
                            {{-- Lot 41 (41.3) : qui fait quoi ce soir. --}}
                            @if ($meal->cook_together) · <span class="font-medium text-stone-700">ensemble</span>
                            @elseif ($meal->cook) · <span class="font-medium text-stone-700">{{ $meal->cook->name }}</span>
                            @endif
                        </p>
                    </div>
                    @if ($big && $recipe && $meal->isRecipe() && ! $meal->cooked_at)
                        <a href="{{ route('recipes.cook', ['recipe' => $recipe, 'portions' => $meal->servings, 'repas' => $meal->id]) }}"
                           class="btn btn-primary min-h-14 w-full justify-center px-5 text-lg sm:w-auto">
                            <x-icon name="fire" class="size-6" /> Cuisiner
                        </a>
                    @endif
                </div>
            @empty
                <p @class(['text-stone-500', 'text-xl' => $big, 'text-base' => ! $big])>Rien de prévu</p>
            @endforelse
        </li>
    @endforeach
</ul>
