<!DOCTYPE html>
{{-- Page servie par le service worker quand la page demandée n'a jamais été ouverte et qu'il n'y a pas de réseau (20.2). --}}
<html lang="fr" class="h-full">
<head>
    @include('partials.head', ['title' => 'Hors ligne'])
</head>
<body class="flex h-full items-center bg-stone-50 font-sans text-stone-800 antialiased">
    <main class="mx-auto max-w-md px-6 text-center">
        <x-app-logo class="mx-auto size-12 text-brand-600" />
        <h1 class="mt-4 text-xl font-bold text-stone-900">Pas de réseau</h1>
        <p class="mt-2 text-stone-600">
            Cette page a besoin d'une connexion à Bouffe.
        </p>
        <p class="mt-4 rounded-xl bg-white p-4 text-sm text-stone-600 shadow-sm ring-1 ring-stone-200">
            La liste de courses, elle, fonctionne en magasin même sans réseau : ouvrez-la
            <strong>avant de partir</strong> avec le bouton « Mode magasin ». Les articles cochés
            sont gardés sur le téléphone et remontent tout seuls dès que le réseau revient.
        </p>
        {{-- Lot 41 (41.2) : les recettes gardées dans le téléphone s'ouvrent sans réseau. --}}
        <p class="mt-3 rounded-xl bg-white p-4 text-sm text-stone-600 shadow-sm ring-1 ring-stone-200">
            Pour cuisiner : <a href="{{ route('offline.week') }}" class="font-medium text-brand-700 underline">les recettes gardées sur cet appareil</a>
            (Planning › Plus › Recettes sans réseau, avant de partir).
        </p>
        <button type="button" data-action="reload" class="btn btn-primary mt-6">Réessayer</button>
    </main>
</body>
</html>
