<div>
    <x-page-header title="Stock" :subtitle="$totalCount === 0 ? 'Ce qu\'il y a au frigo, au congélateur et au placard.' : $totalCount.' article'.($totalCount > 1 ? 's' : '').' en stock'">
        <x-slot:actions>
            {{-- Lot 28 (E6) : chaque bouton garde son libellé ; sur téléphone, les moins fréquents passent dans « Plus ». --}}
            <a href="{{ route('suggestions') }}" wire:navigate class="btn btn-secondary"><x-icon name="sparkles" class="size-4" /> Que cuisiner ?</a>
            <a href="{{ route('stock.scan') }}" wire:navigate class="btn btn-secondary" title="Scanner un code-barres">
                <x-icon name="photo" class="size-4" /> Scanner
            </a>
            <a href="{{ route('stock.freezer') }}" wire:navigate class="btn btn-secondary hidden sm:inline-flex" title="Le congélateur, du plus ancien au plus récent">
                <x-icon name="snow" class="size-4" /> Congélateur
            </a>
            <a href="{{ route('stock.history') }}" wire:navigate class="btn btn-ghost hidden sm:inline-flex" title="Historique et gaspillage">
                <x-icon name="chart" class="size-4" /> Historique
            </a>
            @if (auth()->user()->canEdit())
                <a href="{{ route('stock.review') }}" wire:navigate class="btn btn-ghost hidden sm:inline-flex" title="Les articles qui n'ont pas bougé depuis 2 mois">
                    <x-icon name="clock" class="size-4" /> Revue
                </a>
            @endif
            <button type="button" wire:click="newPrepared" class="btn btn-secondary hidden sm:inline-flex"><x-icon name="plus" class="size-4" /> Plat préparé</button>
            <x-action-sheet label="Plus" title="Stock" class="sm:hidden">
                <a href="{{ route('stock.freezer') }}" wire:navigate class="menu-item"><x-icon name="snow" class="size-5 text-stone-400" /> Congélateur</a>
                <a href="{{ route('stock.history') }}" wire:navigate class="menu-item"><x-icon name="chart" class="size-5 text-stone-400" /> Historique et gaspillage</a>
                @if (auth()->user()->canEdit())
                    <a href="{{ route('stock.review') }}" wire:navigate class="menu-item"><x-icon name="clock" class="size-5 text-stone-400" /> Revue par ancienneté</a>
                @endif
                <button type="button" wire:click="newPrepared" x-on:click="open = false" class="menu-item"><x-icon name="plus" class="size-5 text-stone-400" /> Ajouter un plat préparé</button>
            </x-action-sheet>
        </x-slot:actions>
    </x-page-header>

    {{-- Aide contextuelle (lot 30, 30.3) --}}
    <x-hint key="stock">
        Pour le sel, l'huile ou les épices, inutile de compter : réglez l'ingrédient sur « Présence » (Paramètres › Ingrédients).
        Il suffit alors de dire s'il y en a ou non.
    </x-hint>

    @include('livewire.stock.index.toolbar')

    @include('livewire.stock.index.banners')

    @include('livewire.stock.index.items')

    @include('livewire.stock.index.add-modal')

    @include('livewire.stock.index.item-modal')
</div>
