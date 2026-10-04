<div wire:poll.30s>
    <x-page-header :title="'Liste groupée — '.$list->household?->name" :subtitle="$list->name" />
    <x-linked-nav />

    <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <section class="card p-4 sm:p-5">
            <h2 class="font-display mb-3 font-semibold text-stone-900">Nos articles</h2>

            @if ($canEdit)
                <form wire:submit="add" class="mb-4 flex flex-wrap gap-2">
                    <input type="text" wire:model="label" maxlength="150" class="form-input min-w-0 flex-1 basis-48" placeholder="« 2 kg de pommes », « Lessive »" aria-label="Article à ajouter">
                    <button type="submit" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Ajouter</button>
                    @error('label') <p class="form-error w-full">{{ $message }}</p> @enderror
                </form>
            @endif

            <ul class="divide-y divide-stone-100">
                @forelse ($items as $item)
                    <li wire:key="gi-{{ $item->id }}" class="flex items-center gap-3 py-2.5">
                        <span @class(['shrink-0', 'text-emerald-700' => $item->is_checked, 'text-stone-300' => ! $item->is_checked])>
                            <x-icon :name="$item->is_checked ? 'success' : 'cart'" class="size-5" />
                            <span class="sr-only">{{ $item->is_checked ? 'Acheté' : 'À acheter' }}</span>
                        </span>
                        <span @class(['min-w-0 flex-1', 'text-stone-500 line-through' => $item->is_checked, 'text-stone-900' => ! $item->is_checked])>{{ $item->label }}</span>
                        @if ($item->paid_price !== null)
                            <span class="text-sm tabular-nums text-stone-700">{{ number_format((float) $item->paid_price, 2, ',', ' ') }} €</span>
                        @elseif ($canEdit && ! $item->is_checked)
                            <button type="button" wire:click="remove({{ $item->id }})" class="text-stone-400 hover:text-red-700" title="Retirer"><x-icon name="close" class="size-4" /><span class="sr-only">Retirer</span></button>
                        @endif
                    </li>
                @empty
                    <li class="py-2 text-sm text-stone-500">Rien pour l'instant : ajoutez ce qu'il vous faut, {{ $list->household?->name }} le prendra avec ses courses.</li>
                @endforelse
            </ul>
        </section>

        <aside class="card h-fit space-y-2 p-4 text-sm sm:p-5">
            <h2 class="font-display font-semibold text-stone-900">À rembourser</h2>
            <p class="text-2xl font-bold tabular-nums text-stone-900">{{ number_format($total, 2, ',', ' ') }} €</p>
            <p class="text-stone-600">À {{ $list->household?->name }}, d'après les prix saisis à la caisse.</p>
            @if ($missing > 0)
                <p class="flex gap-2 text-amber-800"><x-icon name="warning" class="mt-0.5 size-4 shrink-0" /> {{ $missing }} article(s) acheté(s) sans prix saisi : le total n'est pas complet.</p>
            @endif
        </aside>
    </div>
</div>
