<div>
    <x-page-header title="Équipement" subtitle="Ce que permet la cuisine de la maison : les idées écartent les recettes impossibles." />
    <x-settings-nav />

    <form wire:submit="save" class="card space-y-4 p-4 sm:p-5" data-equipment-form>
        <fieldset>
            <legend class="form-label">Dans notre cuisine</legend>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($labels as $key => [$label])
                    <label wire:key="eq-{{ $key }}" class="flex min-h-11 cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm ring-1 ring-stone-200 has-checked:bg-herb-50 has-checked:ring-herb-300">
                        <input type="checkbox" wire:model="items" value="{{ $key }}" class="form-checkbox"> {{ $label }}
                    </label>
                @endforeach
            </div>
        </fieldset>
        <p class="text-xs text-stone-500">
            Ce que demande une recette est deviné d'après ses étapes (« enfourner », « mixer »…) et se corrige dans sa fiche
            (Modifier). Un séjour a son propre équipement, sur sa page.
        </p>
        @if (auth()->user()->canEdit()) <div class="flex justify-end"><button type="submit" class="btn btn-primary">Enregistrer</button></div> @endif
    </form>

    @if ($this->impossible->isNotEmpty())
        <section class="mt-6" aria-labelledby="impossible-title">
            <h2 id="impossible-title" class="mb-2 font-display text-lg font-semibold text-stone-900">Pas faisables ici ({{ $this->impossible->count() }})</h2>
            <ul class="card divide-y divide-stone-100">
                @foreach ($this->impossible as $row)
                    <li wire:key="imp-{{ $row['recipe']->id }}" class="flex flex-wrap items-center gap-2 px-4 py-2.5 text-sm">
                        <a href="{{ route('recipes.show', $row['recipe']) }}" wire:navigate class="min-w-0 flex-1 font-medium text-stone-800 hover:underline">{{ $row['recipe']->title }}</a>
                        <span class="text-stone-500">demande : {{ app(\App\Services\Recipes\KitchenEquipment::class)->labels($row['missing']) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
