{{-- Séjour — frais partagés (34.3, R37). --}}
@php
    $costs = $this->costs;
    $money = fn ($amount) => app(\App\Services\Pricing\PriceBook::class)->money((float) $amount);
    $payers = collect($costs['groups'])->pluck('label')->merge($stay->participants->pluck('group_label'))->unique()->values();
    $expenses = $stay->payments->where('kind', \App\Models\StayPayment::EXPENSE);
    $refunds = $stay->payments->where('kind', \App\Models\StayPayment::REFUND);
    // Lot 42 (R45) : chacun note ses dépenses ; celles d'un foyer parti restent, marquées.
    $coorganizers = app(\App\Services\Stays\StayCoorganizers::class);
    $gone = $coorganizers->goneIds($stay);
    $viewer = (int) \App\Support\CurrentHousehold::id();
    $organizer = $role === \App\Services\Stays\StayCoorganizers::ORGANIZER;
@endphp
<div class="grid gap-6 lg:grid-cols-3" data-stay-costs>
    <div class="space-y-6 lg:col-span-2">
        {{-- ============================================================ Bilan --}}
        <section class="card p-4 sm:p-5">
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <h2 class="font-display flex-1 text-lg font-semibold text-stone-900">Qui doit combien</h2>
                <div class="flex gap-1 rounded-lg bg-stone-100 p-1" role="group" aria-label="Partage des frais">
                    @foreach (\App\Models\Stay::SPLIT_MODES as $mode => $label)
                        <button type="button" wire:click="setSplitMode('{{ $mode }}')" aria-pressed="{{ $stay->split_mode === $mode ? 'true' : 'false' }}" @disabled(! $canEdit || ! $organizer)
                                @class(['min-h-9 rounded-md px-3 text-sm font-medium', 'bg-white text-stone-900 shadow-sm' => $stay->split_mode === $mode, 'text-stone-600' => $stay->split_mode !== $mode])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            @if ($costs['total'] <= 0)
                <p class="text-sm text-stone-500">Aucune dépense notée pour l'instant.</p>
            @else
                <p class="mb-3 text-sm text-stone-600">
                    Total : <strong class="text-stone-900">{{ $money($costs['total']) }}</strong>, partagé
                    {{ $stay->split_mode === 'person' ? 'par personne' : 'selon l\'appétit de chacun' }}, au prorata des jours de présence.
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[32rem] text-sm">
                        <thead>
                            <tr class="text-left text-xs text-stone-500">
                                <th class="py-1.5 font-medium">Groupe</th>
                                <th class="py-1.5 text-right font-medium">Part</th>
                                <th class="py-1.5 text-right font-medium">A avancé</th>
                                <th class="py-1.5 text-right font-medium">Solde</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($costs['groups'] as $group)
                                <tr wire:key="cost-{{ md5($group['label']) }}">
                                    <td class="py-2">
                                        <span class="font-medium text-stone-900">{{ $group['label'] }}</span>
                                        @if ($group['people'] !== [])
                                            <span class="block text-xs text-stone-500">{{ implode(', ', $group['people']) }} · poids {{ number_format($group['weight'], 2, ',', ' ') }} sur {{ number_format($costs['weight'], 2, ',', ' ') }}</span>
                                        @else
                                            <span class="block text-xs text-stone-500">ne participe pas</span>
                                        @endif
                                    </td>
                                    <td class="py-2 text-right tabular-nums">{{ $money($group['share']) }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ $money($group['paid']) }}@if ($group['sent'] > 0 || $group['received'] > 0)<span class="block text-xs text-stone-500">{{ $group['sent'] > 0 ? '+ '.$money($group['sent']).' remboursés' : '' }}{{ $group['received'] > 0 ? '− '.$money($group['received']).' reçus' : '' }}</span>@endif</td>
                                    <td @class(['py-2 text-right font-semibold tabular-nums', 'text-herb-700' => $group['balance'] > 0.004, 'text-red-700' => $group['balance'] < -0.004, 'text-stone-500' => abs($group['balance']) <= 0.004])>
                                        {{ $group['balance'] > 0.004 ? '+' : '' }}{{ $money($group['balance']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-stone-500">Solde positif : on lui doit ; négatif : il doit. Le poids est l'appétit (ou 1 par personne) multiplié par les jours de présence.</p>
            @endif
        </section>

        {{-- ============================================================ Remboursements --}}
        @if ($costs['total'] > 0)
            <section class="card p-4 sm:p-5" data-stay-transfers>
                <h2 class="font-display mb-1 text-lg font-semibold text-stone-900">Pour tout équilibrer</h2>
                @if ($costs['transfers'] === [])
                    <p class="flex items-center gap-2 text-sm text-herb-700"><x-icon name="success" class="size-5" /> Les comptes sont équilibrés.</p>
                @else
                    <p class="mb-3 text-sm text-stone-500">Le moins de remboursements possible. Bouffe ne fait aucun paiement : cochez quand c'est fait.</p>
                    <ul class="divide-y divide-stone-100">
                        @foreach ($costs['transfers'] as $transfer)
                            <li class="flex flex-wrap items-center gap-2 py-2.5" wire:key="transfer-{{ md5($transfer['from'].$transfer['to']) }}">
                                <span class="min-w-0 flex-1 text-stone-800"><strong>{{ $transfer['from'] }}</strong> rembourse <strong>{{ $money($transfer['amount']) }}</strong> à <strong>{{ $transfer['to'] }}</strong></span>
                                @if ($canEdit)
                                    <button type="button" wire:click="settle(@js($transfer['from']), @js($transfer['to']), '{{ $transfer['amount'] }}')" class="btn btn-secondary min-h-10 px-3 text-sm">
                                        <x-icon name="check" class="size-4" /> Remboursé
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        {{-- ============================================================ Dépenses --}}
        <section class="card p-4 sm:p-5">
            <h2 class="font-display mb-2 text-lg font-semibold text-stone-900">Dépenses et remboursements</h2>
            @if ($stay->payments->isEmpty())
                <p class="text-sm text-stone-500">Rien pour l'instant.</p>
            @else
                <ul class="divide-y divide-stone-100 text-sm">
                    @foreach ($stay->payments as $payment)
                        <li class="flex flex-wrap items-center gap-2 py-2" wire:key="payment-{{ $payment->id }}">
                            <span class="w-20 text-stone-500">{{ $payment->paid_on->locale('fr')->isoFormat('D MMM') }}</span>
                            <span class="min-w-0 flex-1">
                                @if ($payment->isRefund())
                                    <span class="text-stone-700">{{ $payment->group_label }} → {{ $payment->to_group_label }}</span> <x-badge color="green">remboursé</x-badge>
                                @else
                                    <span class="font-medium text-stone-900">{{ $payment->label }}</span> <span class="text-stone-500">· payé par {{ $payment->group_label }}</span>
                                @endif
                                @php $by = $coorganizers->ownerId($payment->household_id, $stay); @endphp
                                @if (in_array($by, $gone, true))
                                    <x-badge color="stone">noté par « {{ $householdNames[$by] ?? '' }} », qui ne fait plus partie du séjour</x-badge>
                                @elseif (count($householdNames) > 1 && $by !== $viewer)
                                    <span class="text-xs text-stone-500">· noté par « {{ $householdNames[$by] ?? '' }} »</span>
                                @endif
                            </span>
                            <span class="font-medium tabular-nums">{{ $money($payment->amount) }}</span>
                            @if ($canEdit && $coorganizers->canManage($payment->household_id, $stay))
                                <button type="button" wire:click="removePayment({{ $payment->id }})" wire:confirm="Supprimer cette ligne ?" class="btn btn-ghost min-h-10 px-2 hover:text-red-600" title="Supprimer">
                                    <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer</span>
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    @if ($canEdit)
        <aside>
            <form wire:submit="addExpense" class="card space-y-3 p-4" data-stay-expense>
                <h2 class="font-display text-lg font-semibold text-stone-900">Noter une dépense</h2>
                <x-field label="Payé par" for="expense-group" error="expenseGroup">
                    <input id="expense-group" type="text" wire:model="expenseGroup" list="stay-payers" maxlength="60" placeholder="{{ $payers->first() ?? 'Groupe' }}" class="form-input">
                    <datalist id="stay-payers">@foreach ($payers as $payer)<option value="{{ $payer }}"></option>@endforeach</datalist>
                </x-field>
                <x-field label="Pour" for="expense-label" error="expenseLabel">
                    <input id="expense-label" type="text" wire:model="expenseLabel" maxlength="150" placeholder="Courses Cactus, location, essence…" class="form-input">
                </x-field>
                <div class="grid grid-cols-2 gap-2">
                    <x-field label="Montant (€)" for="expense-amount" error="expenseAmount">
                        <input id="expense-amount" type="text" inputmode="decimal" wire:model="expenseAmount" placeholder="84,20" class="form-input">
                    </x-field>
                    <x-field label="Le" for="expense-date" error="expenseDate">
                        <input id="expense-date" type="date" wire:model="expenseDate" placeholder="{{ $this->defaultExpenseDate() }}" class="form-input">
                    </x-field>
                </div>
                <button type="submit" class="btn btn-primary w-full justify-center"><x-icon name="plus" class="size-4" /> Ajouter</button>
                <p class="text-xs text-stone-500">Les courses, mais aussi la location, l'essence… tout ce qui se partage. Rien ne va dans le budget de la maison.</p>
            </form>
        </aside>
    @endif
</div>
