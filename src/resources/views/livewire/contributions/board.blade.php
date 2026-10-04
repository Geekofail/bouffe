{{-- Qui apporte quoi (lot 42, 42.2) : réception ou séjour. --}}
@php $canEdit = auth()->user()->canEdit(); $host = $mode === 'host'; @endphp
<section class="card space-y-4 p-4 sm:p-5" data-contributions>
    <div>
        <h2 class="font-display text-lg font-semibold text-stone-900">Qui apporte quoi</h2>
        <p class="text-sm text-stone-600">
            @if ($host)
                Écrivez ce qu'il faut (dessert, vin, pain…) : chacun s'inscrit, ici ou par un lien. Ce qui est apporté sort de la liste de courses
                {{ $isStay ? '(un plat prévu, ou un ingrédient comme le pain)' : '(un plat prévu, à la prochaine mise à jour de la liste)' }}.
            @else
                Dites ce que vous apportez : la liste est partagée avec ceux qui reçoivent et les autres invités.
            @endif
        </p>
    </div>

    @if ($rows->isEmpty())
        <p class="text-sm text-stone-500">Rien pour l'instant.</p>
    @else
        <ul class="divide-y divide-stone-100" data-contribution-rows>
            @foreach ($rows as $row)
                @php $ours = (int) $row->by_household_id === $us; $dup = in_array($row->id, $duplicates, true); @endphp
                <li wire:key="contribution-{{ $row->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5">
                    <span class="min-w-0 flex-1 basis-48">
                        <span class="font-medium text-stone-900">{{ $row->label }}</span>
                        @if ($row->planned_meal_id || $row->stay_meal_id) <span class="text-xs text-stone-500">· plat prévu</span> @endif
                        <span class="block text-sm">
                            @if ($row->isTaken())
                                <span class="text-herb-800">{{ $row->by_label }}</span>
                                @if ($row->token_hash) <span class="text-xs text-stone-500">· par le lien</span> @endif
                            @else
                                <span class="text-amber-800">Personne pour l'instant</span>
                            @endif
                            @if ($dup) <x-badge color="amber">en double ?</x-badge> @endif
                        </span>
                    </span>
                    @if ($canEdit)
                        <span class="flex items-center gap-1">
                            @if (! $row->isTaken())
                                <button type="button" wire:click="take({{ $row->id }})" class="btn btn-secondary min-h-10 px-3 text-sm">{{ $host ? 'On s\'en occupe' : 'Nous l\'apportons' }}</button>
                            @elseif ($host || $ours)
                                <button type="button" wire:click="release({{ $row->id }})" class="btn btn-ghost min-h-10 px-3 text-sm" title="Plus personne ne l'apporte">Libérer</button>
                            @endif
                            @if ($host || ($ours && (int) $row->created_by === (int) auth()->id()))
                                <button type="button" wire:click="remove({{ $row->id }})" wire:confirm="Retirer « {{ $row->label }} » de la liste ?" class="btn btn-ghost min-h-10 px-2 hover:text-red-600" title="Retirer">
                                    <x-icon name="delete" class="size-4" /><span class="sr-only">Retirer {{ $row->label }}</span>
                                </button>
                            @endif
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canEdit && ! $past)
        <form wire:submit="add" class="space-y-2 border-t border-stone-100 pt-3" data-contribution-form>
            <div class="grid gap-2 sm:grid-cols-2">
                <x-field :label="$host ? 'À apporter' : 'Nous apportons'" for="contribution-label" error="label">
                    <input id="contribution-label" type="text" wire:model="label" maxlength="100" placeholder="{{ $host ? 'Dessert, vin, pain…' : 'Une tarte, du vin…' }}" class="form-input">
                </x-field>
                @if ($host)
                    <x-field label="Qui l'apporte ? (facultatif)" for="contribution-by" error="by">
                        <input id="contribution-by" type="text" wire:model="by" maxlength="60" placeholder="Personne pour l'instant" class="form-input">
                    </x-field>
                @endif
            </div>
            @if ($host && $dishes->isNotEmpty())
                <x-field label="Ou un plat déjà prévu" for="contribution-dish" help="Si quelqu'un l'apporte, ses ingrédients sortent de la liste de courses.">
                    <select id="contribution-dish" wire:model="dish" class="form-input">
                        <option value="">—</option>
                        @foreach ($dishes as $dishRow)
                            <option value="{{ $dishRow['key'] }}">{{ $dishRow['label'] }}</option>
                        @endforeach
                    </select>
                </x-field>
            @endif
            <button type="submit" class="btn btn-secondary"><x-icon name="plus" class="size-4" /> Ajouter</button>
        </form>
    @endif

    {{-- Le lien pour ceux qui n'ont pas Bouffe. --}}
    @if ($host && $canEdit && ! $past)
        <div class="space-y-2 rounded-lg bg-stone-50 p-3 text-sm" x-data="{ copied: false }" data-contribution-link>
            @if ($link)
                <p class="text-stone-700">Le lien à envoyer à ceux qui n'ont pas Bouffe : ils voient cette liste et s'inscrivent avec leur prénom. Valable jusqu'au {{ $link->expires_at->locale('fr')->isoFormat('D MMMM') }}.</p>
                <div class="flex flex-wrap gap-2">
                    <input type="text" readonly value="{{ $linkUrl }}" aria-label="Lien « qui apporte quoi »" class="form-input basis-full text-sm sm:min-w-0 sm:flex-1 sm:basis-0" x-on:focus="$el.select()">
                    <button type="button" class="btn btn-secondary" x-on:click="navigator.clipboard?.writeText(@js($linkUrl)); copied = true; setTimeout(() => copied = false, 2000)">
                        <span x-show="!copied">Copier</span><span x-show="copied">Copié</span>
                    </button>
                    <button type="button" wire:click="revokeLink" wire:confirm="Le lien ne marchera plus. Continuer ?" class="btn btn-ghost">Retirer le lien</button>
                </div>
                <p class="text-xs text-stone-500">La page montre le nom de l'évènement, sa date et cette liste ; ni le menu, ni les allergies, ni les participants.</p>
            @else
                <p class="text-stone-700">Des invités sans Bouffe ? Un lien leur permet de s'inscrire avec leur prénom.</p>
                <button type="button" wire:click="createLink" class="btn btn-secondary"><x-icon name="link" class="size-4" /> Créer le lien</button>
            @endif
        </div>
    @endif
</section>
