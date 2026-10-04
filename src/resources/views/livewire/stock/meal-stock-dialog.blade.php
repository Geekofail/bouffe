<div>
    @if ($mode === 'revert')
        <x-modal :show="$show" title="Remettre dans le stock ?" close="close" max-width="max-w-md">
            <p class="text-sm text-stone-700">
                « {{ $mealLabel }} » n'est plus marqué mangé. Les produits retirés du stock pour ce repas
                (et les restes rangés) peuvent être remis comme avant.
            </p>
            <p class="mt-2 text-xs text-stone-500">Un article modifié depuis n'est pas touché.</p>

            <x-slot:footer>
                <button type="button" wire:click="close" class="btn btn-secondary">Laisser le stock</button>
                <button type="button" wire:click="confirmRevert" class="btn btn-primary">Remettre</button>
            </x-slot:footer>
        </x-modal>
    @else
        <x-modal :show="$show" title="Mettre à jour le stock" close="close" max-width="max-w-xl">
            <p class="mb-4 text-sm text-stone-600">« {{ $mealLabel }} » est mangé. Cochez ce qui a été utilisé.</p>

            @if ($rows !== [])
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500">Retirer du stock</h3>
                <ul class="mb-4 divide-y divide-stone-100 rounded-lg ring-1 ring-stone-200">
                    @foreach ($rows as $row)
                        @php
                            $hasQuantity = collect($row['allocations'])->whereNotNull('take')->isNotEmpty();
                            $hasUnknown = collect($row['allocations'])->whereNull('take')->isNotEmpty();
                        @endphp
                        <li wire:key="meal-stock-{{ $row['key'] }}" class="px-3 py-2.5">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="font-medium text-stone-900">{{ $row['name'] }}@if ($row['optional']) <span class="text-xs font-normal text-stone-500">(facultatif)</span>@endif</span>
                                <span class="shrink-0 text-xs text-stone-500">{{ $row['need'] }}</span>
                            </div>

                            @if ($hasQuantity)
                                <label class="mt-1.5 flex items-start gap-2 text-sm">
                                    <input type="checkbox" wire:model="include.{{ $row['key'] }}" class="form-checkbox mt-0.5">
                                    <span class="text-stone-700">
                                        {{ collect($row['allocations'])->whereNotNull('take')->pluck('text')->join(' · ') }}
                                        @if ($row['status'] === 'partial') <span class="text-amber-700">— pas assez en stock</span> @endif
                                    </span>
                                </label>
                            @endif

                            @if ($hasUnknown)
                                <label class="mt-1.5 flex items-start gap-2 text-sm">
                                    <input type="checkbox" wire:model="finishUnknown.{{ $row['key'] }}" class="form-checkbox mt-0.5">
                                    <span class="text-stone-700">
                                        @if ($row['mode'] === 'presence')
                                            Il n'en reste plus <span class="text-xs text-stone-500">(suivi en présence)</span>
                                        @else
                                            {{ collect($row['allocations'])->whereNull('take')->pluck('text')->join(' · ') }}
                                        @endif
                                    </span>
                                </label>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($consumption)
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500">Restes utilisés</h3>
                <label class="mb-4 flex items-start gap-2 rounded-lg px-3 py-2.5 text-sm ring-1 ring-stone-200">
                    <input type="checkbox" wire:model="consume" class="form-checkbox mt-0.5">
                    <span class="text-stone-700">
                        {{ $consumption['label'] }} :
                        @if ($consumption['left'] === null || $consumption['left'] <= 0)
                            terminé
                        @else
                            −{{ $consumption['take'] }} portion{{ $consumption['take'] > 1 ? 's' : '' }} (reste {{ rtrim(rtrim(number_format($consumption['left'], 1, ',', ''), '0'), ',') }})
                        @endif
                    </span>
                </label>
            @endif

            @if ($leftovers)
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500">Restes</h3>
                <div class="rounded-lg px-3 py-2.5 ring-1 ring-stone-200">
                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" wire:model.live="storeLeftovers" class="form-checkbox mt-0.5">
                        <span class="text-stone-700">Ranger « {{ $leftovers['label'] }} » au réfrigérateur</span>
                    </label>
                    @if ($storeLeftovers)
                        <div class="mt-2 flex flex-wrap items-center gap-2 pl-6 text-sm text-stone-600">
                            <input type="number" min="0.5" step="0.5" max="{{ $leftovers['portions'] }}" wire:model="leftoverPortions"
                                   class="form-input w-20 py-1" aria-label="Portions de restes">
                            <span>portion(s) · à consommer avant le {{ \Illuminate\Support\Carbon::parse($leftovers['expires_on'])->locale('fr')->isoFormat('dddd D MMMM') }}</span>
                        </div>
                    @endif
                </div>
            @endif

            <x-slot:footer>
                <button type="button" wire:click="ignore" class="btn btn-secondary">Ne rien retirer</button>
                <button type="button" wire:click="confirm" class="btn btn-primary">Valider</button>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
