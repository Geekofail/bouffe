<!DOCTYPE html>
<html lang="fr" class="h-full bg-stone-50">
    <head>
        @include('partials.head')
    </head>
    <body class="flex min-h-full flex-col items-center justify-center bg-gradient-to-b from-brand-50 to-stone-50 px-4 py-12 font-sans text-stone-800 antialiased">
        <div class="mb-8 flex flex-col items-center gap-2 text-brand-700">
            <x-app-logo class="size-14" />
            <span class="text-3xl font-bold tracking-tight">Bouffe</span>
        </div>

        <div class="card w-full max-w-sm space-y-4 p-6">
            <h1 class="text-xl font-semibold text-stone-900">Aucun foyer pour l'instant</h1>
            <p class="text-sm text-stone-600">
                Votre compte n'appartient à aucun foyer, ou votre foyer a été désactivé. Demandez à un
                responsable de foyer de vous envoyer une invitation : il suffira d'ouvrir le lien reçu.
            </p>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-secondary w-full"><x-icon name="logout" class="size-4" /> Se déconnecter</button>
            </form>
        </div>
    </body>
</html>
