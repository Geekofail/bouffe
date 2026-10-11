{{-- Fiche recette : ingrédients, portions, variantes, stock, nutrition (lot 36). --}}
<section class="card h-fit p-5 lg:col-span-2">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 class="font-display text-lg font-semibold text-stone-900">Ingrédients</h2>

        <div class="flex items-center gap-1 rounded-full bg-stone-100 p-1" aria-label="Nombre de portions">
            <button type="button" wire:click="decrement" class="rounded-full bg-white p-1.5 shadow-sm hover:bg-stone-50 disabled:opacity-40"
                    @disabled($servings <= 0.5) title="Moins de portions">
                <x-icon name="minus" class="size-4" /><span class="sr-only">Moins</span>
            </button>
            <span class="flex min-w-24 items-center justify-center gap-1 text-sm font-semibold text-stone-800 tabular-nums">
                <x-icon name="users" class="size-4 text-stone-500" />
                {{ \App\Services\Planning\Appetites::label($servings) }}
            </span>
            <button type="button" wire:click="increment" class="rounded-full bg-white p-1.5 shadow-sm hover:bg-stone-50"
                    title="Plus de portions">
                <x-icon name="plus" class="size-4" /><span class="sr-only">Plus</span>
            </button>
        </div>
    </div>

    {{-- Lot 32 (32.2) : « pour 3,5 portions », d'après l'appétit de chacun. --}}
    @php $householdServings = $this->householdServings(); @endphp
    @if (! $mealId && $householdServings !== (float) $servings)
        <p class="-mt-2 mb-3 text-right text-xs">
            <button type="button" wire:click="useHouseholdServings" class="font-medium text-brand-700 hover:underline">
                Pour le foyer : {{ \App\Services\Planning\Appetites::label($householdServings) }}
            </button>
        </p>
    @endif

    @if ($context = $this->mealContext)
        <div class="mb-3 rounded-lg bg-violet-50 px-3 py-2 text-xs text-violet-900">
            <p>
                Prévue {{ $context['meal']->date->locale('fr')->isoFormat('dddd D MMMM') }} · {{ mb_strtolower($context['meal']->slot->name) }}
                @if ($context['occasion'])
                    · {{ app(\App\Services\Planning\OccasionService::class)->summary($context['occasion']) }}
                    @if ($context['occasion']->guests->isNotEmpty()) ({{ $context['occasion']->guests->pluck('name')->join(', ') }}) @endif
                @endif
            </p>
            @foreach ($context['conflicts'] as $conflict)
                <p @class(['mt-1 font-medium', 'text-red-700' => $conflict['level'] === 'danger', 'text-amber-800' => $conflict['level'] !== 'danger'])>⚠ {{ $conflict['message'] }}</p>
            @endforeach
        </div>
    @endif

    {{-- Variantes (13.7) : la recette ne change pas, seuls quelques ingrédients sont remplacés. --}}
    @if ($this->variants->isNotEmpty())
        <div class="mb-3 flex flex-wrap items-center gap-1.5">
            <button type="button" wire:click="showVariant(null)"
                    @class(['rounded-full px-3 py-1 text-xs font-medium ring-1 transition', 'bg-brand-600 text-white ring-brand-600' => $variantId === null, 'text-stone-600 ring-stone-200 hover:ring-stone-300' => $variantId !== null])>
                Recette d'origine
            </button>
            @foreach ($this->variants as $variant)
                <button type="button" wire:key="variant-{{ $variant->id }}" wire:click="showVariant({{ $variant->id }})"
                        @class(['rounded-full px-3 py-1 text-xs font-medium ring-1 transition', 'bg-brand-600 text-white ring-brand-600' => $variantId === $variant->id, 'text-stone-600 ring-stone-200 hover:ring-stone-300' => $variantId !== $variant->id])
                        title="{{ $variant->note }}">
                    {{ $variant->name }}
                </button>
            @endforeach
        </div>

        @if ($this->variant?->note)
            <p class="mb-3 rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600">{{ $this->variant->note }}</p>
        @endif
    @endif

    {{-- Proposition automatique quand un convive a une contrainte (13.7). --}}
    @if ($this->suggestedVariant && $variantId !== $this->suggestedVariant['variant']->id)
        <div class="mb-3 flex flex-wrap items-center gap-2 rounded-lg bg-violet-50 px-3 py-2 text-xs text-violet-900">
            <x-icon name="info" class="size-4 shrink-0" />
            <span class="flex-1">{{ $this->suggestedVariant['reason'] }}</span>
            <button type="button" wire:click="showVariant({{ $this->suggestedVariant['variant']->id }})" class="font-semibold underline">
                Voir « {{ $this->suggestedVariant['variant']->name }} »
            </button>
        </div>
    @endif

    {{-- Coût de la recette pour le nombre de portions affiché (R17). --}}
    @if ($this->cost->isKnown())
        <p class="mb-3 flex flex-wrap items-center gap-x-2 rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600">
            <x-icon name="euro" class="size-4 text-stone-400" />
            <span><strong class="text-stone-900">{{ $this->cost->label($prices) }}</strong> pour {{ \App\Services\Planning\Appetites::label($servings) }}</span>
            @if ($this->cost->missingLabel())
                <span class="text-stone-500">· {{ $this->cost->missingLabel() }}, le total est un minimum</span>
            @endif
        </p>
    @endif

    @if ((float) $servings !== (float) $recipe->servings)
        <p class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Quantités recalculées pour {{ \App\Services\Planning\Appetites::label($servings) }} (recette prévue pour {{ $recipe->servings }}).
            <button type="button" wire:click="resetServings" class="font-semibold underline">Revenir</button>
        </p>
    @endif

    @php $feedback = $this->feedback; @endphp
    @if ($feedback['likes'] + $feedback['dislikes'] > 0)
        <p class="mb-3 flex flex-wrap items-center gap-3 rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600 ring-1 ring-stone-200">
            <span class="font-semibold text-stone-800">À la maison</span>
            @if ($feedback['likes'] > 0)
                <span class="text-herb-700">{{ $feedback['likes'] }} × on a aimé</span>
            @endif
            @if ($feedback['dislikes'] > 0)
                <span class="text-red-700">{{ $feedback['dislikes'] }} × bof</span>
            @endif
        </p>
    @endif

    @if ($this->cookNotes->isNotEmpty())
        <div class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-100">
            <p class="mb-1 font-semibold">Notes de cuisine</p>
            <ul class="space-y-1">
                @foreach ($this->cookNotes as $note)
                    <li wire:key="cook-note-{{ $note->id }}" class="flex items-start gap-2">
                        <span class="flex-1">« {{ $note->note }} » <span class="text-xs text-amber-700">— {{ $note->user?->name }}, {{ $note->created_at->locale('fr')->isoFormat('D MMM YYYY') }}@if ($note->servings) · {{ $note->servings }} portions @endif</span></span>
                        @if ($note->user_id === auth()->id())
                            <button type="button" wire:click="deleteCookNote({{ $note->id }})" class="shrink-0 text-amber-500 hover:text-red-600" title="Supprimer cette note"><x-icon name="close" class="size-4" /></button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($stock = $this->stockAvailability)
        @php $inStock = (int) collect($stock['lines'])->where('optional', false)->whereIn('status', ['ok', 'check', 'assumed', 'substitute'])->count(); @endphp
        <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600 ring-1 ring-stone-200">
            <span class="flex-1">
                <strong class="text-stone-800">Stock : {{ $inStock }}/{{ $stock['counted'] }} ingrédients</strong>
                @if ($stock['missing'] === []) · tout est disponible @endif
                @if ($stock['urgent'] > 0) · <span class="text-green-700">♻ utilise {{ $stock['urgent'] }} produit{{ $stock['urgent'] > 1 ? 's' : '' }} à consommer vite</span> @endif
            </span>
            @if ($stock['missing'] !== [])
                <button type="button" wire:click="addMissingToList" class="font-semibold text-brand-700 hover:underline">
                    Ajouter les manquants aux courses
                </button>
            @endif
        </div>
    @endif

    @forelse ($this->ingredientGroups as $group => $lines)
        @if ($group !== '')
            <h3 class="mt-4 mb-1 text-sm font-semibold tracking-wide text-brand-700 uppercase">{{ $group }}</h3>
        @endif
        <ul class="grid grid-cols-[auto_1fr] divide-y divide-stone-100" wire:loading.class="opacity-60" wire:target="increment,decrement,resetServings">
            @foreach ($lines as $line)
                @php $lineConflict = $this->mealContext['byIngredient'][$line['ingredient_id']] ?? null; @endphp
                <li wire:key="line-{{ $line['id'] }}" @class([
                    'col-span-2 grid grid-cols-subgrid items-baseline gap-x-3 py-2',
                    '-mx-2 rounded-md bg-red-50 px-2 ring-1 ring-red-200' => $lineConflict === 'danger',
                    '-mx-2 rounded-md bg-amber-50 px-2 ring-1 ring-amber-200' => $lineConflict === 'warning',
                ])>
                    <span class="text-right font-semibold whitespace-nowrap text-stone-800 tabular-nums">{{ $line['parts']['quantity'] }}</span>
                    <span @class(['text-stone-700', 'font-medium text-brand-800' => $line['swapped'] ?? false])>{{ $line['parts']['name'] }}@if ($line['preparation'])<span class="text-stone-500">, {{ $line['preparation'] }}</span>@endif
                        @if ($line['swapped'] ?? false)
                            <span class="text-xs text-brand-600" title="Remplacé par la variante">(variante)</span>
                        @endif
                        @if ($line['optional'])
                            <span class="text-xs text-stone-500">(facultatif)</span>
                        @endif
                        @if ($stock && ($availability = $stock['lines'][$line['ingredient_id']] ?? null) && $availability['status'] !== 'assumed')
                            @php
                                [$badgeColor, $badgeText] = match ($availability['status']) {
                                    'ok' => ['green', '✓ en stock'],
                                    'partial' => ['orange', '◐ '.$availability['text']],
                                    'check' => ['yellow', '? à vérifier'],
                                    'substitute' => ['green', '↻ '.$availability['text']],
                                    default => ['stone', '✗ manquant'],
                                };
                            @endphp
                            <x-badge :color="$badgeColor" class="ml-1 align-middle whitespace-nowrap">{{ $badgeText }}</x-badge>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @empty
        @if ($this->subRecipes->isEmpty())
            <p class="text-sm text-stone-500">Aucun ingrédient renseigné.</p>
        @endif
    @endforelse

    {{-- Sous-recettes (13.8) : leurs ingrédients comptent dans les courses, le coût et le stock. --}}
    @if ($this->subRecipes->isNotEmpty())
        <h3 class="mt-4 mb-1 text-sm font-semibold tracking-wide text-brand-700 uppercase">Sous-recettes</h3>
        <ul class="divide-y divide-stone-100">
            @foreach ($this->subRecipes as $sub)
                <li wire:key="sub-{{ $sub['recipe']->id }}" class="py-2">
                    <details>
                        <summary class="flex cursor-pointer items-baseline gap-3">
                            <span class="font-semibold whitespace-nowrap text-stone-800 tabular-nums">{{ $sub['factor'] }} ×</span>
                            <span class="min-w-0 flex-1">
                                <a href="{{ route('recipes.show', $sub['recipe']) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $sub['recipe']->title }}</a>@if ($sub['note'])<span class="text-stone-500">, {{ $sub['note'] }}</span>@endif
                                <span class="block text-xs text-stone-500">{{ count($sub['lines']) }} ingrédient{{ count($sub['lines']) > 1 ? 's' : '' }} — toucher pour le détail</span>
                            </span>
                        </summary>
                        <ul class="mt-1 ml-8 space-y-0.5 text-sm text-stone-600">
                            @foreach ($sub['lines'] as $subLine)
                                <li><span class="font-medium tabular-nums">{{ $subLine['parts']['quantity'] }}</span> {{ $subLine['parts']['name'] }}@if ($subLine['optional']) <span class="text-xs text-stone-500">(facultatif)</span>@endif</li>
                            @endforeach
                        </ul>
                    </details>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Valeurs nutritionnelles indicatives (17.4, R19), Nutri-Score estimé et repères (lot 43). --}}
    @php
        $nutrition = $this->nutrition;
        $summary = $this->nutritionSummary;
        $calculator = app(\App\Services\Nutrition\NutritionCalculator::class);
        $benchmarks = app(\App\Services\Nutrition\MealBenchmarks::class);
        $score = $summary['score'] ?? null;
        $tones = ['good' => 'bg-herb-50 text-herb-800 ring-herb-200', 'warn' => 'bg-amber-50 text-amber-800 ring-amber-200', 'info' => 'bg-stone-100 text-stone-700 ring-stone-200'];
    @endphp
    @if ($calculator->hasTable())
        <details id="nutrition" class="mt-5 scroll-mt-24 border-t border-stone-100 pt-4 text-sm" data-nutrition @if ($score) open @endif>
            <summary class="flex cursor-pointer flex-wrap items-center gap-2 font-medium text-stone-700">
                Nutrition <span class="font-normal text-stone-500">(indicative)</span>
                @if ($score) <x-nutri-score :letter="$score['letter']" /> @endif
            </summary>

            @if ($nutrition['known'])
                @if ($score)
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="text-stone-700">{{ \App\Services\Nutrition\NutriScore::LETTERS[$score['letter']] }}</span>
                        @foreach ($summary['flags'] as $flag)
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $tones[$flag['tone']] }}" data-flag="{{ $flag['key'] }}">{{ $flag['label'] }}</span>
                        @endforeach
                    </div>
                @endif

                <p class="mt-3 text-xs text-stone-500">
                    Par portion, pour {{ \App\Services\Planning\Appetites::label($nutrition['servings']) }} ·
                    couverture {{ $nutrition['coverage'] }} % du poids de la recette ·
                    « repas type » = un tiers des apports de référence d'un adulte.
                </p>
                <table class="mt-2 w-full text-left">
                    <thead class="sr-only"><tr><th>Constituant</th><th>Par portion</th><th>Part d'un repas type</th></tr></thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach (\App\Models\NutritionFood::NUTRIENTS as $key => [$label, $unit])
                            @php $share = in_array($key, ['carbs', 'fat'], true) ? null : $benchmarks->share($key, $nutrition['values'][$key]); @endphp
                            <tr>
                                <td @class(['py-1 text-stone-500', 'pl-3 text-xs' => in_array($key, ['sugars', 'saturated_fat'], true)])>{{ $label }}</td>
                                <td class="py-1 text-right font-medium text-stone-800 tabular-nums">{{ $calculator->format($key, $nutrition['values'][$key]) }}</td>
                                <td class="w-28 py-1 pl-3 sm:w-40">
                                    @if ($share !== null)
                                        <span class="flex items-center gap-2" title="{{ $share }} % d'un repas type">
                                            <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-stone-100">
                                                <span @class(['block h-full rounded-full', 'bg-amber-500' => $share > 100 && ! in_array($key, ['fibres', 'proteins'], true), 'bg-herb-500' => in_array($key, ['fibres', 'proteins'], true) || $share <= 100]) style="width: {{ min(100, $share) }}%"></span>
                                            </span>
                                            <span class="w-10 text-right text-xs text-stone-500 tabular-nums">{{ $share }} %</span>
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($score)
                    <details class="mt-3 rounded-lg bg-stone-50 px-3 py-2" data-nutri-score-detail>
                        <summary class="cursor-pointer text-xs font-medium text-stone-700">Pourquoi « {{ $score['letter'] }} » ?</summary>
                        <p class="mt-2 text-xs text-stone-500">
                            Calcul de Grignott.in avec l'algorithme du Nutri-Score, pour 100 g de recette (ingrédients pesés crus) :
                            {{ $score['negative'] }} point(s) à limiter − {{ $score['positive'] }} point(s) favorables = {{ $score['score'] }}.
                            Ce n'est pas le Nutri-Score officiel d'un produit.
                        </p>
                        <table class="mt-2 w-full text-xs">
                            <tbody class="divide-y divide-stone-200/70">
                                @foreach (app(\App\Services\Nutrition\NutriScore::class)->breakdown($summary['per_100g'], $summary['fruit_vegetable'], $score) as $row)
                                    <tr @class(['opacity-50' => ! $row['counted']])>
                                        <td class="py-1 text-stone-600">{{ $row['label'] }}</td>
                                        <td class="py-1 text-right text-stone-500 tabular-nums">{{ $row['value'] }}</td>
                                        <td @class(['py-1 pl-3 text-right font-medium tabular-nums', 'text-amber-700' => $row['kind'] === 'negative' && $row['points'] > 0, 'text-herb-700' => $row['kind'] === 'positive' && $row['points'] > 0, 'text-stone-500' => $row['points'] === 0])>
                                            {{ $row['kind'] === 'negative' ? '+' : '−' }}{{ $row['points'] }} <span class="text-stone-500">/ {{ $row['max'] }}</span>
                                            @unless ($row['counted']) <span class="sr-only">(non compté)</span> @endunless
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @unless ($score['proteins_counted'])
                            <p class="mt-1 text-xs text-stone-500">Les protéines ne comptent pas : trop de points à limiter et peu de fruits et légumes (règle du Nutri-Score).</p>
                        @endunless
                    </details>
                @endif

                @if ($nutrition['uncounted'] !== [])
                    <p class="mt-2 text-xs text-stone-500">Non compté (sans quantité) : {{ collect($nutrition['uncounted'])->map(fn ($name) => mb_strtolower($name))->join(', ', ' et ') }}.</p>
                @endif
                <p class="mt-2 text-xs text-stone-500">
                    {{ \App\Models\NutritionFood::ATTRIBUTION }} Valeurs indicatives, calculées d'après les quantités
                    saisies — ce n'est pas un conseil nutritionnel.
                </p>
            @else
                <p class="mt-2 text-stone-500">{{ $calculator->explain($nutrition) }}</p>
                <a href="{{ route('settings.nutrition') }}" wire:navigate class="mt-1 inline-block text-xs font-medium text-brand-700">Compléter les correspondances</a>
            @endif
        </details>
    @endif
</section>
