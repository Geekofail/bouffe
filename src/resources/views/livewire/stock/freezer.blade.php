<div class="mx-auto max-w-4xl">
    <a href="{{ route('stock.index') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800 print:hidden">
        <x-icon name="chevron-left" class="size-4" /> Stock
    </a>

    <x-page-header title="Congélateur" subtitle="Ce qui y dort depuis le plus longtemps passe en premier.">
        <x-slot:actions>
            <a href="{{ route('stock.scan') }}" wire:navigate class="btn btn-secondary">
                <x-icon name="photo" class="size-4" /> <span class="sr-only sm:not-sr-only">Scanner</span>
            </a>
            <a href="{{ route('stock.labels', ['ids' => implode(',', $selected)]) }}" target="_blank"
               @class(['btn btn-primary', 'pointer-events-none opacity-40' => $selected === []])>
                <x-icon name="printer" class="size-4" /> Étiquettes ({{ count($selected) }})
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- ============================================================ Bilan --}}
    <div class="card mb-6 flex flex-wrap items-center gap-x-8 gap-y-3 p-5">
        <div>
            <p class="text-2xl font-bold text-stone-900">{{ $homemadeCount }}</p>
            <p class="text-sm text-stone-500">plat{{ $homemadeCount > 1 ? 's' : '' }} maison</p>
        </div>
        @if ($servings > 0)
            <div>
                <p class="text-2xl font-bold text-stone-900">{{ \App\Services\Planning\Appetites::format($servings) }}</p>
                <p class="text-sm text-stone-500">portion{{ $servings >= 2 ? 's' : '' }} d'avance</p>
            </div>
        @endif
        <div>
            <p class="text-2xl font-bold text-stone-900">{{ $totalCount }}</p>
            <p class="text-sm text-stone-500">article{{ $totalCount > 1 ? 's' : '' }} au total</p>
        </div>

        <div class="ml-auto flex items-center gap-1 rounded-lg bg-stone-100 p-1">
            @foreach (['plats' => 'Plats maison', 'tout' => 'Tout'] as $value => $label)
                <button type="button" wire:click="$set('view', '{{ $value }}')"
                        @class(['rounded-md px-3 py-1.5 text-sm font-medium transition', 'bg-white text-stone-900 shadow-sm' => $view === $value, 'text-stone-600' => $view !== $value])>
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- ============================================================ Liste --}}
    @if ($this->rows->isEmpty())
        <div class="card">
            <x-empty-state icon="pantry" title="Rien au congélateur">
                Les plats cuisinés en trop (mode cuisine) et les produits rangés au congélateur apparaîtront ici,
                du plus ancien au plus récent.
            </x-empty-state>
        </div>
    @else
        @if ($selected !== [])
            <div class="mb-3 flex flex-wrap items-center gap-3 rounded-xl bg-brand-50 px-4 py-2 text-sm text-brand-900">
                <span>{{ count($selected) }} article{{ count($selected) > 1 ? 's' : '' }} sélectionné{{ count($selected) > 1 ? 's' : '' }} pour l'impression d'étiquettes.</span>
                <button type="button" wire:click="clearSelection" class="ml-auto font-medium underline">Tout décocher</button>
            </div>
        @else
            <div class="mb-3 text-right">
                <button type="button" wire:click="selectAll" class="text-sm text-stone-500 underline hover:text-stone-800">Tout sélectionner pour les étiquettes</button>
            </div>
        @endif

        <ul class="space-y-3">
            @foreach ($this->rows as $row)
                @php $item = $row['item']; @endphp
                <li wire:key="frozen-{{ $item->id }}"
                    @class([
                        'card flex flex-wrap items-start gap-x-3 gap-y-3 p-4',
                        'ring-amber-200' => $row['level'] === 'ageing',
                        'ring-red-200' => $row['level'] === 'old',
                    ])>
                    <label class="flex cursor-pointer items-center pt-0.5" title="Sélectionner pour l'étiquette">
                        <input type="checkbox" class="form-checkbox" wire:click="toggle({{ $item->id }})" @checked(in_array($item->id, $selected, true))>
                        <span class="sr-only">Étiquette pour {{ $item->name() }}</span>
                    </label>

                    <div class="min-w-[12rem] flex-1">
                        <p class="font-semibold text-stone-900">
                            {{ $item->name() }}
                            @if ($item->servings)
                                <span class="ml-1 text-sm font-normal text-stone-500">· {{ \App\Services\Planning\Appetites::label($item->servings) }}</span>
                            @elseif ($item->quantity)
                                <span class="ml-1 text-sm font-normal text-stone-500">· {{ app(\App\Services\QuantityFormatter::class)->number((float) $item->quantity) }} {{ $item->unit?->label }}</span>
                            @endif
                        </p>
                        <p class="text-sm text-stone-500">
                            Congelé {{ $row['age'] }}
                            @if ($item->frozen_on) ({{ $item->frozen_on->locale('fr')->isoFormat('D MMM YYYY') }}) @endif
                            @if ($item->plannedMeal?->recipe) · {{ $item->plannedMeal->recipe->title }} @endif
                        </p>
                        @if ($row['level'] !== 'fresh')
                            <p @class(['mt-1 text-sm font-medium', 'text-amber-700' => $row['level'] === 'ageing', 'text-red-700' => $row['level'] === 'old'])>
                                {{ $row['level'] === 'old' ? 'Au congélateur depuis plus de 6 mois : à manger en priorité.' : 'Plus de 3 mois : pensez à le planifier.' }}
                            </p>
                        @endif
                    </div>

                    <div class="flex w-full items-center gap-1 border-t border-stone-100 pt-3 sm:w-auto sm:border-0 sm:pt-0">
                        @if ($item->plannedMeal?->recipe)
                            <a href="{{ route('recipes.show', $item->plannedMeal->recipe) }}" wire:navigate class="btn btn-ghost px-2 text-sm">Recette</a>
                        @endif
                        <a href="{{ route('planner.week') }}" wire:navigate class="btn btn-secondary flex-1 py-1.5 text-sm sm:flex-none">Planifier</a>
                        <button type="button" wire:click="thaw({{ $item->id }})" class="btn btn-ghost px-2" title="Sorti du congélateur">
                            <x-icon name="sun" class="size-4" /><span class="sr-only">Sortir</span>
                        </button>
                        <button type="button" wire:click="finish({{ $item->id }})" wire:confirm="Marquer « {{ $item->name() }} » comme terminé ?"
                                class="btn btn-ghost px-2" title="Terminé">
                            <x-icon name="check" class="size-4" /><span class="sr-only">Terminé</span>
                        </button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
