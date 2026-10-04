{{-- Carte de menu d'une réception (21.3) : une page A5 centrée, à imprimer ou à enregistrer en PDF. --}}
<x-print-page :title="'Carte de menu — '.$name" :back="route('receptions.show', $occasion)">
    <x-slot:options>
        <form method="GET" class="flex flex-wrap items-center gap-3 text-sm">
            <select name="style" data-submit-on-change class="form-input w-auto py-1 text-sm" aria-label="Style">
                <option value="classique" @selected($style === 'classique')>Classique</option>
                <option value="moderne" @selected($style === 'moderne')>Moderne</option>
            </select>
            <label class="flex items-center gap-1.5">
                <input type="checkbox" name="invites" value="1" @checked($withGuests) data-submit-on-change class="form-checkbox"> Prénoms des invités
            </label>
        </form>
    </x-slot:options>

    <div @class([
        'mx-auto flex min-h-[180mm] max-w-[148mm] flex-col items-center justify-center px-6 py-10 text-center',
        'border-4 border-double border-stone-300 font-serif' => $style === 'classique',
        'font-sans' => $style === 'moderne',
    ])>
        <p @class(['text-sm tracking-[0.3em] text-stone-500 uppercase', 'italic tracking-normal normal-case text-base' => $style === 'classique'])>
            {{ ucfirst($occasion->serveAt()->locale('fr')->isoFormat('dddd D MMMM YYYY')) }}
        </p>
        <h1 @class(['mt-3 text-4xl text-stone-900', 'font-bold tracking-tight' => $style === 'moderne'])>{{ $name }}</h1>

        @if ($withGuests && $occasion->guests->isNotEmpty())
            <p class="mt-2 text-stone-500">avec {{ $occasion->guests->pluck('name')->join(', ', ' et ') }}</p>
        @endif

        <div @class(['my-8 h-px w-24', 'bg-stone-400' => $style === 'classique', 'bg-brand-500' => $style === 'moderne'])></div>

        <div class="space-y-7">
            @forelse ($courses as $group)
                <section>
                    <h2 @class([
                        'text-xs font-semibold tracking-[0.25em] uppercase',
                        'text-stone-500' => $style === 'classique',
                        'text-brand-700' => $style === 'moderne',
                    ])>{{ $group['label'] }}</h2>
                    @foreach ($group['meals'] as $meal)
                        <p class="mt-1.5 text-xl text-stone-800">{{ $meal->eatenRecipe()?->title ?? $meal->label() }}</p>
                    @endforeach
                </section>
            @empty
                <p class="text-stone-500">Le menu est encore vide.</p>
            @endforelse
        </div>

        @if ($occasion->menu_message)
            <div @class(['my-8 h-px w-24', 'bg-stone-400' => $style === 'classique', 'bg-brand-500' => $style === 'moderne'])></div>
            <p @class(['max-w-sm text-stone-600', 'italic' => $style === 'classique'])>{{ $occasion->menu_message }}</p>
        @endif
    </div>
</x-print-page>
