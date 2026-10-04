{{-- Pagination Livewire simple : « Précédent · page X / Y · Suivant » --}}
@if ($paginator->hasPages())
    <nav class="flex items-center gap-2" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="btn btn-secondary pointer-events-none px-3 opacity-50">
                <x-icon name="chevron-left" class="size-4" /><span class="sr-only">Page précédente</span>
            </span>
        @else
            <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" class="btn btn-secondary px-3">
                <x-icon name="chevron-left" class="size-4" /><span class="sr-only">Page précédente</span>
            </button>
        @endif

        <span class="text-sm text-stone-600">Page {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>

        @if ($paginator->hasMorePages())
            <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" class="btn btn-secondary px-3">
                <x-icon name="chevron-right" class="size-4" /><span class="sr-only">Page suivante</span>
            </button>
        @else
            <span class="btn btn-secondary pointer-events-none px-3 opacity-50">
                <x-icon name="chevron-right" class="size-4" /><span class="sr-only">Page suivante</span>
            </span>
        @endif
    </nav>
@endif
