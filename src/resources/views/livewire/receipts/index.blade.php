<div>
    <x-page-header title="Tickets de caisse" subtitle="Une photo du ticket remplit la dépense, les prix et le stock.">
        <x-slot:actions>
            <a href="{{ route('budget.index') }}" wire:navigate class="btn btn-ghost" title="Budget"><x-icon name="euro" class="size-4" /> <span class="sr-only sm:not-sr-only">Budget</span></a>
            @if (auth()->user()->canEdit())
                <a href="{{ route('receipts.create') }}" wire:navigate class="btn btn-primary"><x-icon name="camera" class="size-4" /> Nouveau ticket</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="card min-w-0 overflow-hidden lg:col-span-2">
            @if ($receipts->isEmpty())
                <x-empty-state icon="receipt" title="Aucun ticket pour l'instant">
                    Photographiez votre prochain ticket : la dépense, les prix et le stock se remplissent tout seuls, après relecture.
                    @if (auth()->user()->canEdit())
                        <x-slot:actions>
                            <a href="{{ route('receipts.create') }}" wire:navigate class="btn btn-primary"><x-icon name="camera" class="size-4" /> Photographier un ticket</a>
                        </x-slot:actions>
                    @endif
                </x-empty-state>
            @else
                <ul class="divide-y divide-stone-100">
                    @foreach ($receipts as $receipt)
                        @php
                            [$label, $class, $icon] = match ($receipt->status) {
                                'validated' => ['Validé', 'text-herb-700', 'success'],
                                'review' => ['À relire', 'text-amber-800', 'warning'],
                                default => ['Pas encore lu', 'text-stone-500', 'clock'],
                            };
                        @endphp
                        <li wire:key="r-{{ $receipt->id }}">
                            <a href="{{ route('receipts.show', $receipt) }}" wire:navigate class="flex items-center gap-3 px-4 py-3 hover:bg-stone-50">
                                <span class="w-16 shrink-0 text-sm whitespace-nowrap text-stone-500 tabular-nums">{{ ($receipt->purchased_on ?? $receipt->created_at)->locale('fr')->isoFormat('D MMM') }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium text-stone-800">{{ $receipt->storeLabel() }}</span>
                                    <span class="flex items-center gap-1 text-xs {{ $class }}"><x-icon :name="$icon" class="size-3.5" /> {{ $label }}
                                        <span class="text-stone-500">· {{ $receipt->lines_count }} ligne{{ $receipt->lines_count > 1 ? 's' : '' }}{{ $receipt->provider ? '' : ' · saisi à la main' }}</span>
                                    </span>
                                </span>
                                <span class="font-semibold text-stone-900 tabular-nums">{{ $receipt->total !== null ? $tracker->money((float) $receipt->total) : '—' }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <aside class="space-y-4">
            <section class="card p-4 text-sm">
                <h2 class="font-display font-semibold text-stone-900">Lectures ce mois-ci</h2>
                <p class="mt-1 text-2xl font-bold text-stone-900 tabular-nums">{{ $usage['count'] }} <span class="text-base font-medium text-stone-500">/ {{ $usage['cap'] }}</span></p>
                <p class="text-stone-600">{{ $usage['pages'] }} page{{ $usage['pages'] > 1 ? 's' : '' }} · coût estimé {{ number_format($usage['cost'], 2, ',', ' ') }} $</p>
                @unless ($status['available'])
                    <p class="mt-2 flex gap-1.5 text-amber-900"><x-icon name="info" class="size-4 shrink-0" /> {{ $status['reason'] }}</p>
                @endunless
                @if (auth()->user()->canEdit())
                    <a href="{{ route('settings.receipts') }}" wire:navigate class="mt-2 inline-block text-brand-700 underline">Réglages de lecture</a>
                @endif
            </section>
        </aside>
    </div>
</div>
