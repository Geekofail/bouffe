{{--
    Mode cuisine (lot 12) : plein écran, gros texte, pas de navigation.
    L'écran reste allumé tant que la page est ouverte (HTTPS uniquement).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['h-full bg-stone-50', 'text-large' => auth()->user()?->preference('text_size') === 'grand'])>
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-full font-sans text-stone-800 antialiased" data-cook-mode
          x-data="{ wake: false }"
          x-init="window.bouffeWakeLock.request().then((ok) => wake = ok)">
        <main class="mx-auto max-w-3xl px-4 py-4 pb-28 sm:px-6">
            {{ $slot }}
        </main>

        <x-flash />

        @include('partials.livewire-hooks')
        @livewireScripts
        <script @nonce>document.addEventListener('livewire:navigated', () => window.bouffeWakeLock.release());</script>
    </body>
</html>
