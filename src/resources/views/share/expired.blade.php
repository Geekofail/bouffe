{{-- Lien de partage inconnu, expiré ou révoqué (31.4). --}}
<!DOCTYPE html>
<html lang="fr" class="h-full bg-stone-100">
    <head>
        @include('partials.head', ['title' => 'Lien expiré'])
        <meta name="robots" content="noindex, nofollow">
    </head>
    <body class="flex min-h-full items-center justify-center p-6 font-sans text-stone-800 antialiased">
        <main class="card max-w-md p-6 text-center">
            <x-app-logo class="mx-auto mb-3 size-10 text-brand-600" />
            <h1 class="mb-2 font-display text-xl font-semibold text-stone-900">Ce lien n'est plus valable</h1>
            <p class="text-sm text-stone-600">
                Il a expiré ou a été retiré par la personne qui vous l'avait envoyé.
                Demandez-lui un nouveau lien si vous voulez revoir la recette.
            </p>
        </main>
    </body>
</html>
