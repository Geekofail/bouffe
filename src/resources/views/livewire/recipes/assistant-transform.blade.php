<div>
    <x-modal :show="$show" title="Adapter avec l'assistant" close="close" max-width="max-w-2xl">
        @if (! $result)
            <form wire:submit="propose" class="space-y-4 text-sm text-stone-700" data-assistant-form>
                <fieldset>
                    <legend class="form-label">Je voudrais…</legend>
                    <div class="flex flex-wrap gap-2">
                        @foreach ([...$presets->all(), 'libre' => 'Autre chose…'] as $key => $label)
                            <label wire:key="preset-{{ $key }}" @class([
                                'cursor-pointer rounded-full px-3 py-1.5 font-medium ring-1 ring-inset transition',
                                'bg-violet-100 text-violet-900 ring-violet-300' => $preset === $key,
                                'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => $preset !== $key,
                            ])>
                                <input type="radio" wire:model.live="preset" value="{{ $key }}" class="sr-only"> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                @if ($preset === 'libre')
                    <x-field label="En quelques mots" for="assistant-free" error="free">
                        <input id="assistant-free" type="text" wire:model="free" maxlength="{{ \App\Services\Assistant\RecipeAssistant::MAX_FREE_TEXT }}"
                               placeholder="sans four, avec des lentilles plutôt que du bœuf…" class="form-input">
                    </x-field>
                @endif

                <p class="rounded-lg bg-stone-50 p-3 text-xs text-stone-600">
                    Envoyé à l'assistant : le titre, les portions, les temps, les ingrédients et les étapes de cette recette.
                    Jamais vos notes, vos invités, votre stock ni vos dépenses. La réponse est un brouillon : rien n'est
                    enregistré sans votre accord.
                </p>

                @error('assistant') <p class="form-error">{{ $message }}</p> @enderror
            </form>
        @else
            <div class="space-y-4 text-sm text-stone-700" data-assistant-result>
                <div class="flex flex-wrap items-center gap-2">
                    <x-assistant-mark />
                    <h3 class="font-semibold text-stone-900">{{ $result['name'] }}</h3>
                </div>

                @if ($result['summary'])
                    <p>{{ $result['summary'] }}</p>
                @endif

                @if ($result['warning'])
                    <p class="flex gap-2 rounded-lg bg-amber-50 p-3 text-amber-900 ring-1 ring-amber-200">
                        <x-icon name="warning" class="size-5 shrink-0" /> {{ $result['warning'] }}
                    </p>
                @endif

                <div>
                    <h4 class="form-label">Ingrédients</h4>
                    <ul class="space-y-1">
                        @foreach ($result['lines'] as $line)
                            <li wire:key="line-{{ $loop->index }}">
                                @if ($line['action'] === 'keep')
                                    {{ $line['original'] }}
                                @elseif ($line['action'] === 'replace')
                                    <span class="text-stone-400 line-through">{{ $line['original'] }}</span>
                                    <span class="rounded bg-violet-100 px-1 text-violet-900">→ {{ $line['replacement'] }}</span>
                                @else
                                    <span class="text-stone-400 line-through">{{ $line['original'] }}</span>
                                    <span class="text-xs text-stone-500">(retiré)</span>
                                @endif
                            </li>
                        @endforeach
                        @foreach ($result['added'] as $added)
                            <li wire:key="added-{{ $loop->index }}"><span class="rounded bg-violet-100 px-1 text-violet-900">+ {{ $added }}</span></li>
                        @endforeach
                    </ul>
                </div>

                <div>
                    <h4 class="form-label">Étapes</h4>
                    <ol class="space-y-2">
                        @foreach ($result['steps'] as $step)
                            <li wire:key="step-{{ $loop->index }}" @class(['flex gap-2 rounded-lg p-2', 'bg-violet-50 ring-1 ring-violet-200' => $step['changed']])>
                                <span class="font-semibold text-stone-500">{{ $loop->iteration }}.</span>
                                <span class="flex-1">{{ $step['text'] }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>

                @unless ($fitsVariant)
                    <p class="text-xs text-stone-500">
                        {{ $result['added'] !== [] ? 'Cette proposition ajoute des ingrédients' : 'Cette proposition ne change aucun ingrédient' }} :
                        elle ne peut pas devenir une variante, seulement une nouvelle recette.
                    </p>
                @else
                    <p class="text-xs text-stone-500">Une variante ne garde que les changements d'ingrédients ; les étapes réécrites restent dans « Nouvelle recette ».</p>
                @endunless

                @error('assistant') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        @endif

        <x-slot:footer>
            @if (! $result)
                <button type="button" wire:click="close" class="btn btn-ghost">Annuler</button>
                <button type="button" wire:click="propose" class="btn btn-primary" wire:loading.attr="disabled" wire:target="propose">
                    <x-icon name="sparkles" class="size-4" />
                    <span wire:loading.remove wire:target="propose">Proposer</span>
                    <span wire:loading wire:target="propose">L'assistant réfléchit…</span>
                </button>
            @else
                <button type="button" wire:click="discard" class="btn btn-ghost">Jeter</button>
                <button type="button" wire:click="asNewRecipe" class="btn btn-secondary">Nouvelle recette…</button>
                @if ($fitsVariant)
                    <button type="button" wire:click="saveAsVariant" class="btn btn-primary">
                        <x-icon name="shuffle" class="size-4" /> Enregistrer comme variante
                    </button>
                @endif
            @endif
        </x-slot:footer>
    </x-modal>
</div>
