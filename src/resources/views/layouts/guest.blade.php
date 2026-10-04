<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-stone-50">
    <head>
        @include('partials.head')
    </head>
    <body class="flex min-h-full flex-col items-center justify-center bg-gradient-to-b from-brand-50 to-stone-50 px-4 py-12 font-sans text-stone-800 antialiased">
        <div class="mb-8 flex flex-col items-center gap-2 text-brand-700">
            <x-app-logo class="size-14" />
            <span class="text-3xl font-bold tracking-tight">Bouffe</span>
            <span class="text-sm text-stone-500">Les repas de la semaine, sans prise de tête</span>
        </div>

        <div @class(['w-full', 'max-w-3xl' => $wide ?? false, 'max-w-sm' => ! ($wide ?? false)])>
            {{ $slot }}
        </div>

        <p class="mt-8 text-center text-xs text-stone-500">
            <a href="{{ route('privacy') }}" class="hover:underline">Vos données</a>
        </p>

        @include('partials.livewire-hooks')
        @livewireScripts
    </body>
</html>
