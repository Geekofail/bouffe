{{-- Carnet familial (26.9) : couverture, sommaire, une recette par page. --}}
<x-print-page :title="$title" :back="route('linked.book')">
    <style @nonce>
        .fb-page { break-before: page; }
        @media screen { .fb-page { margin-top: 3rem; padding-top: 2rem; border-top: 1px dashed #d6d3d1; } }
    </style>

    <section class="flex min-h-[60vh] flex-col items-center justify-center text-center print:min-h-[240mm]">
        <x-app-logo class="mb-6 size-16 text-brand-600" />
        <h1 class="text-4xl font-bold text-stone-900">{{ $title }}</h1>
        <p class="mt-4 text-stone-600">{{ $recipes->pluck('household.name')->filter()->unique()->join(' · ') }}</p>
        <p class="mt-1 text-sm text-stone-500">{{ now()->locale('fr')->isoFormat('MMMM YYYY') }}</p>
    </section>

    <section class="fb-page">
        <h2 class="font-display mb-4 text-2xl font-semibold text-stone-900">Sommaire</h2>
        <ol class="space-y-1.5">
            @foreach ($recipes as $recipe)
                <li class="flex items-baseline gap-2">
                    <span class="w-8 shrink-0 text-right tabular-nums text-stone-500">{{ $loop->iteration }}.</span>
                    <span class="min-w-0 flex-1 font-medium text-stone-900">{{ $recipe->title }}</span>
                    <span class="text-sm text-stone-500">{{ $recipe->household?->name }}</span>
                </li>
            @endforeach
        </ol>
    </section>

    @foreach ($recipes as $recipe)
        <article class="fb-page">
            <header class="mb-5 flex items-start gap-6">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-brand-700">{{ $loop->iteration }}</p>
                    <h2 class="font-display text-3xl font-semibold text-stone-900">{{ $recipe->title }}</h2>
                    <p class="mt-1 text-sm text-stone-600">
                        Recette de {{ $recipe->author?->name ? $recipe->author->name.' — ' : '' }}{{ $recipe->household?->name }}
                    </p>
                    @if ($recipe->description)
                        <p class="mt-2 text-stone-700">{{ $recipe->description }}</p>
                    @endif
                    <p class="mt-2 text-sm text-stone-500">
                        {{ $recipe->servings }} portion{{ $recipe->servings > 1 ? 's' : '' }}
                        @if ($recipe->prep_minutes) · préparation {{ \App\Support\Duration::format($recipe->prep_minutes) }} @endif
                        @if ($recipe->cook_minutes) · cuisson {{ \App\Support\Duration::format($recipe->cook_minutes) }} @endif
                        @if ($recipe->rest_minutes) · repos {{ \App\Support\Duration::format($recipe->rest_minutes) }} @endif
                    </p>
                </div>
                @if ($recipe->photo_path)
                    <img src="{{ $recipe->photoUrl('thumb') }}" alt="" class="size-40 shrink-0 rounded-lg object-cover">
                @endif
            </header>

            <div class="grid grid-cols-3 gap-8">
                <section class="col-span-1">
                    <h3 class="mb-2 border-b border-stone-300 pb-1 text-sm font-bold tracking-wide text-stone-700 uppercase">Ingrédients</h3>
                    @forelse ($lines[$recipe->id] as $group => $groupLines)
                        @if ($group !== '')
                            <h4 class="mt-3 mb-1 text-sm font-semibold text-stone-700">{{ $group }}</h4>
                        @endif
                        <ul class="space-y-1 text-sm">
                            @foreach ($groupLines as $line)
                                <li class="flex gap-2"><span class="font-semibold tabular-nums">{{ $line['quantity'] }}</span> <span>{{ $line['name'] }}@if ($line['preparation']), {{ $line['preparation'] }}@endif @if ($line['optional']) (facultatif) @endif</span></li>
                            @endforeach
                        </ul>
                    @empty
                        <p class="text-sm text-stone-500">—</p>
                    @endforelse
                </section>
                <section class="col-span-2">
                    <h3 class="mb-2 border-b border-stone-300 pb-1 text-sm font-bold tracking-wide text-stone-700 uppercase">Préparation</h3>
                    <ol class="space-y-3">
                        @foreach ($recipe->steps as $step)
                            <li class="flex gap-3 break-inside-avoid">
                                <span class="flex size-6 shrink-0 items-center justify-center rounded-full border border-stone-400 text-xs font-bold">{{ $loop->iteration }}</span>
                                <p class="whitespace-pre-line">{{ $step->instruction }}</p>
                            </li>
                        @endforeach
                    </ol>
                </section>
            </div>
        </article>
    @endforeach
</x-print-page>
