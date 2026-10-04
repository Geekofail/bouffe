{{-- 33.3 · Compléter l'import avec l'assistant : temps, difficulté, catégories — à cocher. --}}
<div class="rounded-lg bg-violet-50 p-3 text-sm ring-1 ring-violet-200" data-assistant-complete>
    @if (! $suggestion)
        <div class="flex flex-wrap items-center gap-3">
            <p class="flex-1 text-violet-900">Des temps, la difficulté ou les catégories manquent ?</p>
            <button type="button" wire:click="complete" class="btn btn-secondary text-sm" wire:loading.attr="disabled" wire:target="complete">
                <x-icon name="sparkles" class="size-4" />
                <span wire:loading.remove wire:target="complete">Compléter avec l'assistant</span>
                <span wire:loading wire:target="complete">L'assistant réfléchit…</span>
            </button>
        </div>
        <p class="mt-1 text-xs text-violet-800">Seuls le titre, les ingrédients et les étapes lui sont envoyés.</p>
        @error('assistant') <p class="form-error mt-2">{{ $message }}</p> @enderror
    @else
        @php
            $labels = ['prep_minutes' => 'Préparation', 'cook_minutes' => 'Cuisson', 'rest_minutes' => 'Repos', 'difficulty' => 'Difficulté'];
            $rows = collect($labels)->filter(fn ($label, $key) => $suggestion[$key] !== null);
        @endphp
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <x-assistant-mark />
            <span class="text-violet-900">Cochez ce que vous gardez.</span>
        </div>

        @if ($rows->isEmpty() && $suggestion['tags'] === [])
            <p class="text-violet-900">L'assistant n'a rien trouvé à ajouter.</p>
        @else
            <ul class="grid gap-1 sm:grid-cols-2">
                @foreach ($rows as $key => $label)
                    @php
                        $value = $key === 'difficulty'
                            ? \App\Enums\Difficulty::from($suggestion[$key])->label()
                            : \App\Support\Duration::format((int) $suggestion[$key]);
                        $current = $key === 'difficulty'
                            ? ($form->difficulty ? \App\Enums\Difficulty::tryFrom($form->difficulty)?->label() : null)
                            : (filled($form->{$key}) ? \App\Support\Duration::format((int) $form->{$key}) : null);
                    @endphp
                    <li wire:key="suggest-{{ $key }}">
                        <label class="flex items-center gap-2 text-stone-800">
                            <input type="checkbox" wire:model="accepted" value="{{ $key }}" class="form-checkbox">
                            <span>{{ $label }} : <strong>{{ $value }}</strong>
                                @if ($current) <span class="text-xs text-stone-500">(actuellement {{ $current }})</span> @endif
                            </span>
                        </label>
                    </li>
                @endforeach
                @foreach ($suggestion['tags'] as $name)
                    <li wire:key="suggest-tag-{{ $loop->index }}">
                        <label class="flex items-center gap-2 text-stone-800">
                            <input type="checkbox" wire:model="accepted" value="tag:{{ $name }}" class="form-checkbox">
                            <span>Catégorie : <strong>{{ $name }}</strong></span>
                        </label>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-3 flex flex-wrap gap-2">
            @if ($rows->isNotEmpty() || $suggestion['tags'] !== [])
                <button type="button" wire:click="applySuggestion" class="btn btn-primary text-sm">
                    <x-icon name="check" class="size-4" /> Appliquer
                </button>
            @endif
            <button type="button" wire:click="dismissSuggestion" class="btn btn-ghost text-sm">Jeter</button>
        </div>
    @endif
</div>
