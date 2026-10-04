<x-offline-page :title="'Sans réseau · '.$title" :back="$back">
    <h1 class="page-title">Recettes sans réseau</h1>
    <p class="mt-1 text-stone-600">{{ $title }}</p>

    {{-- Garder sur cet appareil : la liste et chaque recette, aux portions prévues. --}}
    <section class="card mt-4 p-4" data-keep-offline-box>
        <p class="text-sm text-stone-600">
            Pour cuisiner là où il n'y a pas de réseau (chalet, cave, train) : enregistrez ces
            {{ count($links) }} recette{{ count($links) > 1 ? 's' : '' }} dans ce téléphone avant de partir.
        </p>
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button type="button" class="btn btn-primary min-h-11" data-keep-offline data-urls='@json($links)' @disabled($links === [])>
                <x-icon name="download" class="size-4" /> Garder sur cet appareil
            </button>
            <span class="text-sm text-stone-500" data-offline-status aria-live="polite"></span>
        </div>
    </section>

    @if ($previous || $next)
        <nav class="mt-4 flex items-center justify-between gap-3 text-sm" aria-label="Semaines">
            <a href="{{ $previous }}" class="btn btn-ghost min-h-11 px-2"><x-icon name="chevron-left" class="size-4" /> Semaine précédente</a>
            <a href="{{ $next }}" class="btn btn-ghost min-h-11 px-2">Semaine suivante <x-icon name="chevron-right" class="size-4" /></a>
        </nav>
    @endif

    <div class="mt-2 space-y-3">
        @foreach ($days as $day)
            <section class="card overflow-hidden">
                <h2 class="bg-stone-100 px-4 py-2 font-semibold text-stone-700">{{ ucfirst($day['date']->locale('fr')->isoFormat('dddd D MMMM')) }}</h2>
                @if ($day['rows']->isEmpty())
                    <p class="px-4 py-3 text-sm text-stone-500">Rien de prévu.</p>
                @else
                    <ul class="divide-y divide-stone-100">
                        @foreach ($day['rows'] as $row)
                            <li class="flex items-center gap-3 px-4 py-2.5">
                                <span class="w-20 shrink-0 text-xs font-semibold tracking-wide text-stone-500 uppercase">{{ $row['slot'] }}</span>
                                @if ($row['recipe'])
                                    <a href="{{ \App\Http\Controllers\OfflineRecipesController::recipeUrl($row['recipe'], $row['servings']) }}"
                                       class="min-h-11 min-w-0 flex-1 content-center font-medium text-brand-700 hover:underline">
                                        {{ $row['label'] }}
                                        <span class="text-sm font-normal text-stone-500">· {{ \App\Services\Planning\Appetites::label($row['servings']) }}</span>
                                    </a>
                                @else
                                    <span class="min-w-0 flex-1 text-stone-700">{{ $row['label'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>

    @if ($stays->isNotEmpty())
        <section class="mt-5">
            <h2 class="section-title mb-2">Séjours</h2>
            <ul class="space-y-2">
                @foreach ($stays as $stay)
                    <li><a href="{{ route('offline.stay', $stay) }}" class="card flex min-h-11 items-center gap-3 px-4 py-2.5 font-medium text-brand-700 hover:underline">
                        <x-icon name="sun" class="size-5 text-sky-600" /> {{ $stay->name }} <span class="text-sm font-normal text-stone-500">{{ $stay->period() }}</span>
                    </a></li>
                @endforeach
            </ul>
        </section>
    @endif
</x-offline-page>
