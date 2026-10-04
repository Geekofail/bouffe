<x-print-page :title="'Menu du '.$weekStart->locale('fr')->isoFormat('D MMM').' au '.$weekEnd->locale('fr')->isoFormat('D MMM')"
                 :back="route('planner.week', ['semaine' => $weekStart->toDateString()])">
    <x-slot:options>
        <form method="GET" class="flex flex-wrap items-center gap-3 text-sm text-stone-600">
            <input type="hidden" name="semaine" value="{{ $weekStart->toDateString() }}">
            <label class="flex items-center gap-1.5">
                <input type="hidden" name="courses" value="0">
                <input type="checkbox" name="courses" value="1" @checked($list !== null) class="form-checkbox"> Liste de courses
            </label>
            <button type="submit" class="btn btn-secondary py-1.5">Appliquer</button>
        </form>
    </x-slot:options>

    <header class="mb-4">
        <h1 class="text-2xl font-bold text-stone-900">Menu de la semaine</h1>
        <p class="text-sm text-stone-500">Du {{ $weekStart->locale('fr')->isoFormat('dddd D MMMM') }} au {{ $weekEnd->locale('fr')->isoFormat('dddd D MMMM YYYY') }}</p>
    </header>

    <table class="w-full border-collapse text-sm">
        <thead>
            <tr>
                <th class="w-28 border border-stone-300 bg-stone-100 p-2 text-left">Jour</th>
                @foreach ($slots as $slot)
                    <th class="border border-stone-300 bg-stone-100 p-2 text-left">{{ $slot->name }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($days as $day)
                <tr class="break-inside-avoid">
                    <th class="border border-stone-300 p-2 text-left align-top">
                        {{ ucfirst($day->locale('fr')->isoFormat('dddd')) }}
                        <span class="block text-xs font-normal text-stone-500">{{ $day->locale('fr')->isoFormat('D MMM') }}</span>
                    </th>
                    @foreach ($slots as $slot)
                        @php
                            $cell = $meals->get($day->toDateString().'|'.$slot->id, collect());
                            $occasion = $occasions->get($day->toDateString().'|'.$slot->id);
                        @endphp
                        <td class="border border-stone-300 p-2 align-top">
                            @foreach ($cell as $meal)
                                <p>{{ $meal->label() }} <span class="text-xs text-stone-500">· {{ $meal->servings }} p.</span></p>
                            @endforeach
                            @if ($occasion)
                                <p class="text-xs text-stone-500">{{ app(\App\Services\Planning\OccasionService::class)->summary($occasion) }}
                                    @if ($occasion->title) · {{ $occasion->title }} @endif
                                </p>
                            @endif
                            @if ($cell->isEmpty() && ! $occasion) <span class="text-stone-300">—</span> @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($grouped)
        <section class="mt-8 break-before-page">
            <h2 class="font-display mb-3 text-xl font-semibold text-stone-900">{{ $list->name }}</h2>
            <div class="columns-2 gap-8">
                @foreach ($grouped['aisles'] as $group)
                    <div class="mb-3 break-inside-avoid">
                        <h3 class="border-b border-stone-300 pb-0.5 text-sm font-bold">{{ $group['aisle']?->name ?? 'Divers' }}</h3>
                        <ul class="text-sm">
                            @foreach ($group['items'] as $item)
                                <li class="flex gap-2 py-0.5">
                                    <span class="mt-0.5 inline-block size-3 shrink-0 border border-stone-400"></span>
                                    <span @class(['line-through text-stone-500' => $item->is_checked])>{{ app(\App\Services\Shopping\ShoppingItemPresenter::class)->text($item) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
                @if ($grouped['staples']->isNotEmpty())
                    <div class="mb-3 break-inside-avoid">
                        <h3 class="border-b border-stone-300 pb-0.5 text-sm font-bold">À vérifier dans le placard</h3>
                        <ul class="text-sm">
                            @foreach ($grouped['staples'] as $item)
                                <li class="flex gap-2 py-0.5"><span class="mt-0.5 inline-block size-3 shrink-0 border border-stone-400"></span> {{ $item->label }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </section>
    @endif

    <footer class="mt-6 border-t border-stone-200 pt-2 text-xs text-stone-500">Bouffe · imprimé le {{ now()->locale('fr')->isoFormat('D MMMM YYYY') }}</footer>
</x-print-page>
