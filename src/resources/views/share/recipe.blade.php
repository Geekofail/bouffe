{{-- Recette partagée par lien (lot 31, 31.4) : page publique, lecture seule, sans notes ni prix. --}}
<!DOCTYPE html>
<html lang="fr" class="h-full bg-stone-100 print:bg-white">
    <head>
        @include('partials.head', ['title' => $recipe->title])
        <meta name="robots" content="noindex, nofollow">
        <style @nonce>
            @media print {
                @page { size: A4 portrait; margin: 12mm; }
                body { background: #fff !important; }
            }
        </style>
    </head>
    <body class="min-h-full font-sans text-stone-800 antialiased print:text-black">
        <div class="border-b border-stone-200 bg-white px-4 py-2 print:hidden">
            <div class="mx-auto flex max-w-3xl flex-wrap items-center gap-3">
                <span class="flex min-w-0 basis-full items-center gap-2 text-sm text-stone-600 sm:flex-1 sm:basis-auto">
                    <x-app-logo class="size-6 text-brand-600" /> Recette partagée avec Bouffe
                </span>
                <form method="GET" class="flex items-center gap-2 text-sm text-stone-600">
                    <label for="share-portions">Portions</label>
                    <input id="share-portions" type="number" name="portions" min="0.5" step="0.5" max="50" value="{{ $servings }}" class="form-input w-20 py-1">
                    <button type="submit" class="btn btn-secondary py-1.5">Appliquer</button>
                </form>
                <button type="button" data-action="print" class="btn btn-primary py-1.5">
                    <x-icon name="printer" class="size-4" /> Imprimer
                </button>
            </div>
        </div>

        <main class="mx-auto my-4 max-w-3xl bg-white p-5 shadow-sm sm:p-8 print:my-0 print:max-w-none print:p-0 print:shadow-none">
            <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start">
                <div class="min-w-0 flex-1">
                    <h1 class="font-display text-3xl font-bold text-stone-900">{{ $recipe->title }}</h1>
                    @if ($recipe->description)
                        <p class="mt-1 text-stone-600">{{ $recipe->description }}</p>
                    @endif
                    <p class="mt-2 text-sm text-stone-600">
                        {{ \App\Services\Planning\Appetites::label($servings) }}
                        @if ($recipe->prep_minutes) · préparation {{ \App\Support\Duration::format($recipe->prep_minutes) }} @endif
                        @if ($recipe->cook_minutes) · cuisson {{ \App\Support\Duration::format($recipe->cook_minutes) }} @endif
                        @if ($recipe->rest_minutes) · repos {{ \App\Support\Duration::format($recipe->rest_minutes) }} @endif
                    </p>
                </div>
                @if ($recipe->photo_path)
                    <img src="{{ route('share.recipe.photo', ['token' => $token, 'size' => 'thumb']) }}" alt="{{ $recipe->title }}" class="w-full rounded-lg object-cover sm:size-40">
                @endif
            </header>

            <div class="grid gap-8 sm:grid-cols-3">
                <section class="sm:col-span-1">
                    <h2 class="mb-2 border-b border-stone-300 pb-1 text-sm font-bold tracking-wide text-stone-700 uppercase">Ingrédients</h2>
                    @forelse ($groups as $group => $lines)
                        @if ($group !== '')
                            <h3 class="mt-3 mb-1 text-sm font-semibold text-stone-700">{{ $group }}</h3>
                        @endif
                        <ul class="space-y-1 text-sm">
                            @foreach ($lines as $line)
                                <li class="flex gap-2">
                                    <span class="font-semibold tabular-nums">{{ $line['parts']['quantity'] }}</span>
                                    <span>{{ $line['parts']['name'] }}@if ($line['preparation']), {{ $line['preparation'] }} @endif
                                        @if ($line['optional']) (facultatif) @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @empty
                        <p class="text-sm text-stone-600">Aucun ingrédient.</p>
                    @endforelse
                </section>

                <section class="sm:col-span-2">
                    <h2 class="mb-2 border-b border-stone-300 pb-1 text-sm font-bold tracking-wide text-stone-700 uppercase">Préparation</h2>
                    @if ($recipe->steps->isEmpty())
                        <p class="text-sm text-stone-600">Aucune étape.</p>
                    @else
                        <ol class="space-y-3">
                            @foreach ($recipe->steps as $step)
                                @if ($step->group_name && ($loop->first || $step->group_name !== $recipe->steps[$loop->index - 1]->group_name))
                                    <li class="pt-1 text-xs font-bold tracking-wide uppercase">{{ $step->group_name }}</li>
                                @endif
                                <li class="flex gap-3 break-inside-avoid">
                                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full border border-stone-400 text-xs font-bold">{{ $loop->iteration }}</span>
                                    <p class="whitespace-pre-line">{{ $step->instruction }}</p>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            </div>

            <footer class="mt-8 border-t border-stone-200 pt-2 text-xs text-stone-600">
                Recette partagée avec Bouffe · lien valable jusqu'au {{ $expiresAt->locale('fr')->isoFormat('D MMMM YYYY') }}
                @if ($recipe->source && ! $recipe->sourceIsUrl()) · {{ $recipe->source }} @endif
            </footer>
        </main>
    </body>
</html>
