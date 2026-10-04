{{-- Gamelles du midi (lot 32, 32.3) : quoi préparer chaque veille, puis les étiquettes. --}}
<x-print-page title="Gamelles du midi" :back="route('planner.week', ['semaine' => $from->toDateString()])">
    <x-slot:options>
        <form method="GET" class="flex flex-wrap items-center gap-3 text-sm text-stone-600">
            <label class="flex items-center gap-1">Du <input type="date" name="du" value="{{ $from->toDateString() }}" class="form-input py-1"></label>
            <label class="flex items-center gap-1">au <input type="date" name="au" value="{{ $to->toDateString() }}" class="form-input py-1"></label>
            <button type="submit" class="btn btn-secondary py-1.5">Afficher</button>
        </form>
    </x-slot:options>

    <h1 class="text-2xl font-bold text-stone-900">Gamelles du midi</h1>
    <p class="mb-5 text-sm text-stone-600">
        Du {{ $from->locale('fr')->isoFormat('dddd D MMMM') }} au {{ $to->locale('fr')->isoFormat('dddd D MMMM YYYY') }}
        · {{ $meals->count() }} gamelle{{ $meals->count() > 1 ? 's' : '' }}
    </p>

    @if ($meals->isEmpty())
        <p class="text-stone-600">
            Aucune gamelle sur ces dates. Dans le planning, placez des restes et choisissez « Pour qui ? » :
            la gamelle apparaît ici et sur l'accueil, la veille au soir.
        </p>
    @else
        <section class="mb-8">
            <h2 class="mb-2 border-b border-stone-300 pb-1 text-sm font-bold tracking-wide text-stone-700 uppercase">À préparer la veille</h2>
            <ul class="space-y-3">
                @foreach ($evenings as $evening => $boxes)
                    <li class="break-inside-avoid">
                        <p class="font-semibold text-stone-900">{{ ucfirst(\Illuminate\Support\Carbon::parse($evening)->locale('fr')->isoFormat('dddd D MMMM')) }} soir</p>
                        <ul class="mt-1 space-y-1 text-sm">
                            @foreach ($boxes as $box)
                                <li class="flex gap-2">
                                    <span class="inline-block size-4 shrink-0 rounded border border-stone-400" aria-hidden="true"></span>
                                    <span>
                                        <strong>{{ $box->lunchboxLabel() }}</strong> :
                                        {{ $box->eatenRecipe()?->title ?? $box->leftoverOf?->free_text ?? 'restes' }},
                                        {{ \App\Services\Planning\Appetites::label($box->servings) }}
                                        <span class="text-stone-500">— restes du {{ $lunchboxes->origin($box) }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        </section>

        <section>
            <h2 class="mb-2 border-b border-stone-300 pb-1 text-sm font-bold tracking-wide text-stone-700 uppercase print:break-before-auto">Étiquettes</h2>
            <x-box-labels>
                @foreach ($meals as $box)
                    <x-box-label :title="$box->lunchboxLabel()" :qr="route('planner.week', ['repas' => $box->id])" :qr-label="'QR code vers '.$box->lunchboxLabel().' dans le planning'">
                        <p class="mt-1 truncate text-sm font-semibold text-stone-800">{{ $box->eatenRecipe()?->title ?? $box->leftoverOf?->free_text }}</p>
                        <p class="text-sm text-stone-700">
                            {{ ucfirst($box->date->locale('fr')->isoFormat('dddd D/MM')) }}{{ $box->slot ? ' · '.mb_strtolower($box->slot->name) : '' }}
                            · <strong>{{ \App\Services\Planning\Appetites::label($box->servings) }}</strong>
                        </p>
                        <p class="text-sm text-stone-700">Cuisiné le <strong>{{ $box->leftoverOf?->date->locale('fr')->isoFormat('D/MM/YYYY') }}</strong></p>
                    </x-box-label>
                @endforeach
            </x-box-labels>
        </section>
    @endif
</x-print-page>
