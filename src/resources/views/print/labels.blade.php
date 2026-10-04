<x-print-page title="Étiquettes du congélateur" :back="route('stock.freezer')">
    <x-slot:options>
        <p class="text-sm text-stone-600">
            {{ $items->count() }} étiquette{{ $items->count() > 1 ? 's' : '' }} ·
            à découper et à coller sur les boîtes. Le QR code ouvre la fiche de l'article dans Bouffe.
        </p>
    </x-slot:options>

    @if ($items->isEmpty())
        <p class="text-stone-600">Aucun article sélectionné. Revenez au congélateur et cochez les boîtes à étiqueter.</p>
    @else
        <x-box-labels>
            @foreach ($items as $item)
                <x-box-label :title="$item->name()" :qr="route('stock.index', ['article' => $item->id])" :qr-label="'QR code vers la fiche de '.$item->name()">
                    <p class="mt-1 text-sm text-stone-700">
                        @if ($item->servings)
                            <strong>{{ \App\Services\Planning\Appetites::label($item->servings) }}</strong>
                        @elseif ($item->quantity)
                            <strong>{{ app(\App\Services\QuantityFormatter::class)->number((float) $item->quantity) }} {{ $item->unit?->label }}</strong>
                        @endif
                    </p>

                    <p class="text-sm text-stone-700">
                        Congelé le
                        <strong>{{ ($item->frozen_on ?? $item->created_at)->locale('fr')->isoFormat('D/MM/YYYY') }}</strong>
                    </p>

                    @if ($item->expires_on)
                        <p class="text-sm text-stone-700">À consommer avant le <strong>{{ $item->expires_on->locale('fr')->isoFormat('D/MM/YYYY') }}</strong></p>
                    @endif

                    @if ($item->note)
                        <p class="truncate text-xs text-stone-500">{{ $item->note }}</p>
                    @endif
                </x-box-label>
            @endforeach
        </x-box-labels>
    @endif
</x-print-page>
