<div>
    @if ($compact)
        {{-- Détail d'un repas mangé (31.2) : une photo de notre version ? --}}
        @if ($canEdit)
            <button type="button" wire:click="openForm('ours')" class="flex w-full items-center gap-3 rounded-lg bg-stone-50 px-3 py-2.5 text-left text-sm font-medium text-stone-700 ring-1 ring-stone-200 hover:bg-stone-100">
                <x-icon name="camera" class="size-5 text-stone-500" />
                <span class="flex-1">Une photo de notre version ?</span>
                @if ($ours->where('planned_meal_id', $mealId)->isNotEmpty())
                    <span class="text-xs text-herb-700">{{ $ours->where('planned_meal_id', $mealId)->count() }} ✓</span>
                @endif
            </button>
        @endif
    @elseif ($ours->isNotEmpty() || $steps->isNotEmpty() || $canEdit)
        <section class="card mt-6 p-5" aria-labelledby="photos-title">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 id="photos-title" class="font-display text-lg font-semibold text-stone-900">Photos</h2>
                @if ($canEdit)
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="openForm('ours')" class="btn btn-secondary py-1.5 text-sm"><x-icon name="camera" class="size-4" /> Notre version</button>
                        @if ($stepCount > 0)
                            <button type="button" wire:click="openForm('step')" class="btn btn-secondary py-1.5 text-sm"><x-icon name="photo" class="size-4" /> Photo d'étape</button>
                        @endif
                    </div>
                @endif
            </div>

            @if ($ours->isEmpty() && $steps->isEmpty())
                <p class="text-sm text-stone-500">
                    Ajoutez une photo de <strong>votre</strong> version après le repas, ou une photo pour une étape délicate :
                    elle s'affichera dans le mode cuisine, au bon moment.
                </p>
            @endif

            @foreach (['Notre version' => $ours, 'Étapes' => $steps] as $heading => $list)
                @if ($list->isNotEmpty())
                    <h3 class="mt-2 mb-2 text-sm font-semibold tracking-wide text-stone-500 uppercase">{{ $heading }}</h3>
                    <ul class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach ($list as $photo)
                            <li wire:key="recipe-photo-{{ $photo->id }}" class="overflow-hidden rounded-xl bg-stone-50 ring-1 ring-stone-200">
                                <a href="{{ $photo->url('large') }}" target="_blank" rel="noopener" class="block">
                                    <img src="{{ $photo->url('thumb') }}" alt="{{ $photo->caption ?: ($photo->kind === 'step' ? 'Étape '.$photo->step_number : 'Notre version') }}" loading="lazy" class="aspect-[4/3] w-full object-cover">
                                </a>
                                <div class="space-y-1 p-2 text-xs text-stone-600">
                                    <p class="font-medium text-stone-800">
                                        {{ $photo->kind === 'step' ? 'Étape '.$photo->step_number : ($photo->meal ? $photo->meal->date->locale('fr')->isoFormat('D MMM YYYY') : $photo->created_at->locale('fr')->isoFormat('D MMM YYYY')) }}
                                        @if ($photo->user) <span class="font-normal text-stone-500">· {{ $photo->user->name }}</span> @endif
                                    </p>
                                    @if ($photo->caption) <p>{{ $photo->caption }}</p> @endif
                                    @if ($canEdit)
                                        <div class="flex flex-wrap gap-x-3 gap-y-1 pt-1">
                                            <button type="button" wire:click="makeMain({{ $photo->id }})" class="font-medium text-brand-700 hover:underline">Photo principale</button>
                                            <button type="button" wire:click="delete({{ $photo->id }})" wire:confirm="Supprimer cette photo ?" class="text-stone-600 hover:text-red-700">Supprimer</button>
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
        </section>
    @endif

    <x-modal :show="$showForm" :title="$kind === 'step' ? 'Photo d\'une étape' : 'Photo de notre version'" close="closeForm">
        <form id="recipe-photo-form" wire:submit="save" class="space-y-4">
            @if ($kind === 'step')
                <x-field label="Étape" for="photo-step">
                    <select id="photo-step" wire:model="stepNumber" class="form-input">
                        @foreach ($recipe->steps as $step)
                            <option value="{{ $loop->iteration }}">Étape {{ $loop->iteration }} — {{ \Illuminate\Support\Str::limit($step->instruction, 60) }}</option>
                        @endforeach
                    </select>
                </x-field>
            @endif
            <x-field label="Photo" for="photo-upload" error="upload" help="JPEG, PNG ou WebP, 10 Mo au plus. Sur téléphone : prendre une photo ou en choisir une.">
                <input id="photo-upload" type="file" wire:model="upload" accept="image/*" class="form-input py-1.5">
            </x-field>
            <div wire:loading wire:target="upload" class="text-sm text-stone-500">Envoi de la photo…</div>
            @if ($upload && ! $errors->has('upload'))
                @php try { $preview = $upload->temporaryUrl(); } catch (\Throwable) { $preview = null; } @endphp
                @if ($preview) <img src="{{ $preview }}" alt="Aperçu" class="max-h-48 rounded-lg object-cover"> @endif
            @endif
            <x-field label="Légende" for="photo-caption" error="caption" optional>
                <input id="photo-caption" type="text" wire:model="caption" maxlength="150" placeholder="{{ $kind === 'step' ? 'ex. la pâte doit faire le ruban' : 'ex. avec des champignons en plus' }}" class="form-input">
            </x-field>
        </form>
        <x-slot:footer>
            <button type="button" wire:click="closeForm" class="btn btn-ghost">Annuler</button>
            <button type="submit" form="recipe-photo-form" class="btn btn-primary" wire:loading.attr="disabled" wire:target="upload,save">Ajouter</button>
        </x-slot:footer>
    </x-modal>
</div>
