{{--
    Pages à imprimer (lot 12) : fiche recette et menu de la semaine.
    À l'écran : barre d'options en haut. À l'impression : seul le contenu sort.
--}}
@props(['title' => '', 'back' => null, 'landscape' => false])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-stone-100 print:bg-white">
    <head>
        @include('partials.head')
        <style @nonce>
            @media print {
                @page { size: A4 {{ $landscape ? 'landscape' : 'portrait' }}; margin: 12mm; }
                body { background: #fff !important; }
            }
        </style>
    </head>
    <body class="min-h-full font-sans text-stone-800 antialiased print:text-black">
        <div class="sticky top-0 z-10 border-b border-stone-200 bg-white px-4 py-2 print:hidden">
            <div class="mx-auto flex max-w-[210mm] flex-wrap items-center gap-3">
                @if ($back)
                    <a href="{{ $back }}" class="inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
                        <x-icon name="chevron-left" class="size-4" /> Retour
                    </a>
                @endif
                <span class="min-w-0 flex-1 truncate text-sm font-medium text-stone-700">{{ $title }}</span>
                {{ $options ?? '' }}
                <button type="button" data-action="print" class="btn btn-primary py-1.5">
                    <x-icon name="printer" class="size-4" /> Imprimer
                </button>
            </div>
        </div>

        <main class="mx-auto my-4 max-w-[210mm] bg-white p-8 shadow-sm print:my-0 print:max-w-none print:p-0 print:shadow-none">
            {{ $slot }}
        </main>
    </body>
</html>
