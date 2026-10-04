<!DOCTYPE html>
<html lang="fr" class="h-full bg-stone-50">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>@yield('title') · {{ config('app.name') }}</title>
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        @vite(['resources/css/app.css'])
    </head>
    <body class="flex min-h-full flex-col items-center justify-center px-4 text-center font-sans text-stone-800 antialiased">
        <x-app-logo class="mb-6 size-16 text-brand-600" />
        <p class="text-sm font-semibold tracking-wide text-brand-600 uppercase">Erreur @yield('code')</p>
        <h1 class="mt-2 text-2xl font-bold text-stone-900 sm:text-3xl">@yield('title')</h1>
        <p class="mt-3 max-w-md text-stone-600">@yield('message')</p>
        <a href="{{ url('/') }}" class="btn btn-primary mt-8">Retour à l'accueil</a>
    </body>
</html>
