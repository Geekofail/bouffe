{{-- Qui apporte quoi, par lien (lot 42, 42.2) : pour ceux qui n'ont pas Bouffe. Ni menu, ni allergies, ni participants. --}}
<!DOCTYPE html>
<html lang="fr" class="h-full bg-stone-100">
    <head>
        @include('partials.head', ['title' => 'Qui apporte quoi · '.$title])
        <meta name="robots" content="noindex, nofollow">
    </head>
    <body class="min-h-full font-sans text-stone-800 antialiased">
        <div class="border-b border-stone-200 bg-white px-4 py-2">
            <p class="mx-auto flex max-w-2xl items-center gap-2 text-sm text-stone-600">
                <x-app-logo class="size-6 text-brand-600" /> Qui apporte quoi — avec Bouffe
            </p>
        </div>

        <main class="mx-auto max-w-2xl space-y-4 px-4 py-5" data-bring>
            <header>
                <h1 class="font-display text-2xl font-bold text-stone-900">{{ $title }}</h1>
                <p class="text-stone-600">{{ ucfirst($when) }}</p>
            </header>

            @if (session('bring_status'))
                <p class="rounded-lg bg-herb-50 p-3 text-sm text-herb-900 ring-1 ring-herb-200" role="status">{{ session('bring_status') }}</p>
            @endif
            @if (session('bring_error'))
                <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200" role="alert">{{ session('bring_error') }}</p>
            @endif
            @if ($errors->any())
                <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200" role="alert">{{ $errors->first() }}</p>
            @endif

            <section class="card p-4 sm:p-5">
                <h2 class="font-display mb-2 text-lg font-semibold text-stone-900">Ce qu'il faut, et qui l'apporte</h2>
                @if ($rows->isEmpty())
                    <p class="text-sm text-stone-500">Rien pour l'instant.</p>
                @else
                    <ul class="divide-y divide-stone-100">
                        @foreach ($rows as $row)
                            @php $own = $mine && $row->token_hash && hash_equals($row->token_hash, $mine); @endphp
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-2 py-3">
                                <span class="min-w-0 flex-1 basis-48">
                                    <span class="font-medium text-stone-900">{{ $row->label }}</span>
                                    <span class="block text-sm">
                                        @if ($row->isTaken())
                                            <span class="text-herb-800">{{ $row->by_label }}</span>
                                            @if ($own) <span class="text-stone-500">(vous)</span> @endif
                                        @else
                                            <span class="text-amber-800">Personne pour l'instant</span>
                                        @endif
                                        @if (in_array($row->id, $duplicates, true)) <x-badge color="amber">en double ?</x-badge> @endif
                                    </span>
                                </span>
                                @if (! $past && ! $row->isTaken())
                                    <form method="POST" action="{{ route('bring.update', ['token' => $token]) }}" class="flex w-full items-center gap-2 sm:w-auto">
                                        @csrf
                                        <input type="hidden" name="action" value="take">
                                        <input type="hidden" name="id" value="{{ $row->id }}">
                                        <input type="text" name="name" value="{{ $name }}" required maxlength="60" placeholder="Votre prénom" aria-label="Votre prénom, pour « {{ $row->label }} »" class="form-input min-w-0 flex-1 py-1.5 text-sm sm:w-36">
                                        <button type="submit" class="btn btn-secondary min-h-10 px-3 text-sm">Je l'apporte</button>
                                    </form>
                                @elseif ($own)
                                    <form method="POST" action="{{ route('bring.update', ['token' => $token]) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="release">
                                        <input type="hidden" name="id" value="{{ $row->id }}">
                                        <button type="submit" class="btn btn-ghost min-h-10 px-3 text-sm">Je ne l'apporte plus</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if (! $past)
                <form method="POST" action="{{ route('bring.update', ['token' => $token]) }}" class="card space-y-3 p-4 sm:p-5">
                    @csrf
                    <input type="hidden" name="action" value="add">
                    <h2 class="font-display text-lg font-semibold text-stone-900">J'apporte autre chose</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="bring-label" class="form-label">Quoi</label>
                            <input id="bring-label" type="text" name="label" value="{{ old('label') }}" required maxlength="100" placeholder="Une tarte, du vin…" class="form-input">
                        </div>
                        <div>
                            <label for="bring-name" class="form-label">Votre prénom</label>
                            <input id="bring-name" type="text" name="name" value="{{ old('name', $name) }}" required maxlength="60" class="form-input">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Ajouter</button>
                </form>
            @else
                <p class="text-sm text-stone-500">L'évènement est passé : la liste ne change plus.</p>
            @endif

            <p class="px-1 text-xs text-stone-500">
                Votre prénom est visible de ceux qui ont ce lien. Ce navigateur se souvient de vos choix pour que vous puissiez vous raviser.
            </p>
        </main>
    </body>
</html>
