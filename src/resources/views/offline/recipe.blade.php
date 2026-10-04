<x-offline-page :title="$recipe->title" :back="$back">
    <h1 class="page-title">{{ $recipe->title }}</h1>
    <p class="mt-1 text-sm text-stone-600">
        {{ \App\Services\Planning\Appetites::label($servings) }}
        @if ($recipe->prep_minutes) · préparation {{ \App\Support\Duration::format($recipe->prep_minutes) }} @endif
        @if ($recipe->cook_minutes) · cuisson {{ \App\Support\Duration::format($recipe->cook_minutes) }} @endif
        @if ($recipe->rest_minutes) · repos {{ \App\Support\Duration::format($recipe->rest_minutes) }} @endif
    </p>

    {{-- Minuteurs : partagés avec la maison s'il y a du réseau, gardés dans ce téléphone sinon. --}}
    <div class="mt-4 space-y-2" data-offline-timers aria-live="polite"></div>

    <section class="card mt-4 p-4">
        <h2 class="font-display text-lg font-semibold text-stone-900">Ingrédients</h2>
        @foreach ($groups as $group => $lines)
            @if ($group !== '')
                <h3 class="mt-3 text-sm font-semibold tracking-wide text-brand-700 uppercase">{{ $group }}</h3>
            @endif
            <ul class="mt-1 space-y-1 text-base">
                @foreach ($lines as $line)
                    <li>
                        <label class="flex items-start gap-3">
                            <input type="checkbox" class="form-checkbox mt-1 size-5">
                            <span><span class="font-semibold tabular-nums">{{ $line['quantity'] }}</span> {{ $line['name'] }}
                                @if ($line['optional']) <span class="text-sm text-stone-500">(facultatif)</span> @endif</span>
                        </label>
                    </li>
                @endforeach
            </ul>
        @endforeach
    </section>

    <section class="mt-4">
        <h2 class="font-display mb-2 text-lg font-semibold text-stone-900">Préparation</h2>
        <ol class="space-y-3">
            @foreach ($steps as $step)
                <li class="card p-4">
                    @if ($step['group']) <p class="text-sm font-medium tracking-wide text-stone-500 uppercase">{{ $step['group'] }}</p> @endif
                    <div class="flex gap-3">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white">{{ $step['number'] }}</span>
                        <p class="text-lg leading-relaxed whitespace-pre-line text-stone-800">{{ $step['text'] }}</p>
                    </div>
                    @if ($step['timers'] !== [])
                        <div class="mt-3 flex flex-wrap gap-2 pl-11">
                            @foreach ($step['timers'] as $timer)
                                <button type="button" class="btn btn-secondary min-h-11 text-base" data-offline-timer
                                        data-minutes="{{ $timer['minutes'] }}" data-label="{{ $recipe->title }} · étape {{ $step['number'] }} · {{ $timer['label'] }}">
                                    <x-icon name="clock" class="size-5" /> Minuteur {{ $timer['label'] }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
</x-offline-page>
