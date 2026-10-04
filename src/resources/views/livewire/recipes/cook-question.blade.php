<div class="mt-3" data-cook-question>
    @if (! $open && ! $answer)
        <button type="button" wire:click="$set('open', true)" class="btn btn-ghost text-base text-violet-800">
            <x-icon name="sparkles" class="size-5" /> Une question ?
        </button>
    @else
        <div class="card space-y-3 p-4">
            @if ($answer)
                <div class="space-y-1">
                    <p class="text-sm text-stone-500">« {{ $asked }} »</p>
                    <div class="rounded-lg bg-violet-50 p-3 text-base text-stone-800 ring-1 ring-violet-200">
                        <x-assistant-mark text="L'assistant" class="mb-1" />
                        <p class="whitespace-pre-line">{{ $answer }}</p>
                    </div>
                    <p class="text-xs text-stone-500">Réponse indicative, non enregistrée dans la recette.</p>
                </div>
            @endif

            <form wire:submit="ask" class="flex flex-wrap items-end gap-2">
                <x-field label="Votre question sur l'étape {{ $step }}" for="cook-question-{{ $step }}" error="question" class="min-w-56 flex-1">
                    <input id="cook-question-{{ $step }}" type="text" wire:model="question" maxlength="200"
                           placeholder="Pas de crème : par quoi la remplacer ?" class="form-input text-base">
                </x-field>
                <button type="submit" class="btn btn-secondary text-base" wire:loading.attr="disabled" wire:target="ask">
                    <span wire:loading.remove wire:target="ask">Demander</span>
                    <span wire:loading wire:target="ask">L'assistant réfléchit…</span>
                </button>
            </form>
            <p class="text-xs text-stone-500">Envoyé : cette recette et votre question, rien d'autre.</p>
        </div>
    @endif
</div>
