{{--
    Écran de cuisine (lot 35, 35.1) : pour une tablette posée en cuisine. Plein écran, sans
    navigation, écran maintenu allumé (HTTPS uniquement), sombre de 19 h à 7 h.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-stone-50">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-full font-sans text-stone-800 antialiased" data-cook-mode data-kitchen
          x-data="bouffeKitchen()">
        <main class="mx-auto max-w-7xl px-4 py-4 sm:px-6">
            {{ $slot }}
        </main>

        <x-flash />

        @include('partials.livewire-hooks')
        @livewireScripts
    </body>
</html>
