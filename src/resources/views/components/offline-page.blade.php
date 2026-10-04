{{--
    Pages « sans réseau » (lot 41, 41.2) : autonomes (ni Livewire ni Alpine), lisibles sur téléphone,
    gardées dans l'appareil par le service web. Les minuteurs y fonctionnent même sans réseau.
--}}
@props(['title' => '', 'back' => null])

<!DOCTYPE html>
<html lang="fr" class="bg-stone-50">
    <head>
        @include('partials.head', ['title' => $title])
    </head>
    <body class="min-h-screen bg-stone-50 font-sans text-stone-800 antialiased" data-offline-page>
        <header class="sticky top-0 z-10 border-b border-stone-200 bg-white/95 px-4 py-2 backdrop-blur">
            <div class="mx-auto flex max-w-3xl items-center gap-3">
                @if ($back)
                    <a href="{{ $back }}" class="btn btn-ghost min-h-11 px-2" title="Retour">
                        <x-icon name="chevron-left" class="size-5" /><span class="sr-only">Retour</span>
                    </a>
                @endif
                <p class="min-w-0 flex-1 truncate text-sm font-medium text-stone-700">{{ $title }}</p>
                <span class="flex items-center gap-1.5 text-xs text-stone-500" data-network-state>
                    <span class="size-2 rounded-full bg-herb-500" data-network-dot></span>
                    <span data-network-label>En ligne</span>
                </span>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-5 pb-[max(1.5rem,env(safe-area-inset-bottom))]">
            {{ $slot }}
        </main>
    </body>
</html>
