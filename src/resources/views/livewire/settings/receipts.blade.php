<div>
    <x-page-header title="Tickets de caisse" subtitle="Lecture automatique des tickets et de leurs lignes." />
    <x-settings-nav />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            @unless (auth()->user()->isAdmin())
                <section class="card space-y-2 p-4 sm:p-5 text-sm text-stone-600">
                    <h2 class="font-display font-semibold text-stone-900">Service de lecture</h2>
                    <p>Choisi par l'administrateur de l'installation : {{ $status['label'] ?? 'aucun (saisie à la main)' }}.</p>
                    @unless ($status['available']) <p class="text-amber-800">{{ $status['reason'] }}</p> @endunless
                </section>
            @else
            <form wire:submit="save" class="card space-y-5 p-4 sm:p-5">
                <div>
                    <h2 class="font-display font-semibold text-stone-900">Service de lecture</h2>
                    <p class="text-sm text-stone-500">Sans service, les tickets se saisissent à la main : rien d'autre ne change.</p>
                </div>

                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach (['mistral' => ['Mistral', 'Document AI · environ 0,5 centime par page'], 'azure' => ['Azure', 'Document Intelligence · 500 pages gratuites par mois'], 'none' => ['Aucun', 'Saisie à la main']] as $key => [$label, $help])
                        <label @class(['flex cursor-pointer flex-col gap-0.5 rounded-xl p-3 ring-1', 'bg-brand-50 ring-brand-300' => $provider === $key, 'ring-stone-200 hover:bg-stone-50' => $provider !== $key])>
                            <span class="flex items-center gap-2 font-medium text-stone-900">
                                <input type="radio" wire:model.live="provider" value="{{ $key }}" class="text-brand-600"> {{ $label }}
                            </span>
                            <span class="text-xs text-stone-500">{{ $help }}</span>
                        </label>
                    @endforeach
                </div>

                @if ($provider === 'mistral')
                    <x-field label="Clé d'API Mistral" for="mistral-key" error="mistralKey"
                             help="console.mistral.ai → API Keys. Laissez vide pour garder la clé actuelle.">
                        <input id="mistral-key" type="password" autocomplete="off" wire:model="mistralKey" class="form-input" placeholder="{{ $mistralSource ? '••••••••••••' : 'Collez la clé ici' }}">
                    </x-field>
                    <p class="-mt-3 flex flex-wrap items-center gap-2 text-sm">
                        @if ($mistralSource)
                            <span class="flex items-center gap-1 text-herb-700"><x-icon name="success" class="size-4" /> {{ $mistralSource }}</span>
                            @if (str_starts_with($mistralSource, 'Enregistrée') || str_starts_with($mistralSource, 'Illisible'))
                                <button type="button" wire:click="forgetKey('mistral')" class="text-stone-500 underline">Retirer</button>
                            @endif
                        @else
                            <span class="flex items-center gap-1 text-amber-800"><x-icon name="warning" class="size-4" /> Aucune clé</span>
                        @endif
                    </p>
                @elseif ($provider === 'azure')
                    <x-field label="Point de terminaison" for="azure-endpoint" error="azureEndpoint" help="Ex. https://mon-bouffe.cognitiveservices.azure.com">
                        <input id="azure-endpoint" type="url" wire:model="azureEndpoint" class="form-input" placeholder="https://…cognitiveservices.azure.com">
                    </x-field>
                    <x-field label="Clé Azure" for="azure-key" error="azureKey" help="Ressource « Document Intelligence », niveau gratuit F0. Laissez vide pour garder la clé actuelle.">
                        <input id="azure-key" type="password" autocomplete="off" wire:model="azureKey" class="form-input" placeholder="{{ $azureSource ? '••••••••••••' : 'Collez la clé ici' }}">
                    </x-field>
                    <p class="-mt-3 flex flex-wrap items-center gap-2 text-sm">
                        @if ($azureSource)
                            <span class="flex items-center gap-1 text-herb-700"><x-icon name="success" class="size-4" /> {{ $azureSource }}</span>
                            @if (str_starts_with($azureSource, 'Enregistrée') || str_starts_with($azureSource, 'Illisible'))
                                <button type="button" wire:click="forgetKey('azure')" class="text-stone-500 underline">Retirer</button>
                            @endif
                        @else
                            <span class="flex items-center gap-1 text-amber-800"><x-icon name="warning" class="size-4" /> Aucune clé</span>
                        @endif
                    </p>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Plafond de lectures par mois" for="cap" error="monthlyCap" help="Au-delà : saisie à la main jusqu'au mois suivant. 0 = aucune lecture.">
                        <input id="cap" type="number" min="0" max="1000" wire:model="monthlyCap" class="form-input">
                    </x-field>
                    <x-field label="Garder les photos (mois)" for="keep" error="keepMonths" help="Puis supprimées ; les lignes et montants restent. 0 = supprimées à la validation.">
                        <input id="keep" type="number" min="0" max="60" wire:model="keepMonths" class="form-input">
                    </x-field>
                </div>

                <div class="flex justify-end"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
            </form>
            @endunless

            {{-- Libellés appris (24.5) --}}
            <section class="card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-stone-200 px-4 py-3">
                    <h2 class="font-display font-semibold text-stone-900">Libellés appris <span class="font-normal text-stone-500">({{ $mappingCount }})</span></h2>
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Chercher un libellé…" class="form-input w-full py-1.5 text-sm sm:w-56" aria-label="Chercher un libellé">
                </div>
                @if ($mappings->isEmpty())
                    <p class="px-4 py-6 text-sm text-stone-500">Chaque ticket validé apprend ses libellés : « LAIT DEMI ECR UHT » chez Cactus = Lait demi-écrémé. Ils seront reconnus d'office la fois suivante.</p>
                @else
                    <ul class="divide-y divide-stone-100">
                        @foreach ($mappings as $mapping)
                            <li wire:key="m-{{ $mapping->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-sm">
                                <span class="min-w-0 flex-1 truncate font-mono text-stone-800">{{ $mapping->normalized_label }}</span>
                                <span class="text-stone-600">→ {{ $mapping->kind === 'article' ? $mapping->ingredient?->name : \App\Models\ReceiptLine::KINDS[$mapping->kind] ?? $mapping->kind }}</span>
                                <span class="text-xs text-stone-500">{{ $mapping->store?->name ?? 'tous magasins' }} · ×{{ $mapping->confirmations }}</span>
                                <button type="button" wire:click="forgetMapping({{ $mapping->id }})" class="btn btn-ghost px-1.5 text-stone-500" title="Oublier">
                                    <x-icon name="delete" class="size-4" /><span class="sr-only">Oublier</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <aside class="space-y-4">
            <section class="card p-4 text-sm">
                <h2 class="font-display mb-2 font-semibold text-stone-900">Lectures par mois</h2>
                <table class="w-full">
                    <thead><tr class="text-left text-xs text-stone-500"><th class="py-1 font-medium">Mois</th><th class="py-1 text-right font-medium">Lectures</th><th class="py-1 text-right font-medium">Coût estimé</th></tr></thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($months as $month)
                            <tr>
                                <td class="py-1 text-stone-700">{{ $month['label'] }}</td>
                                <td class="py-1 text-right tabular-nums">{{ $month['count'] }}@if ($month['failed']) <span class="text-xs text-stone-500">(+{{ $month['failed'] }} échec{{ $month['failed'] > 1 ? 's' : '' }})</span>@endif</td>
                                <td class="py-1 text-right tabular-nums">{{ number_format($month['cost'], 2, ',', ' ') }} $</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-stone-500">Estimation d'après les tarifs publiés (septembre 2026) ; la facture du service fait foi.</p>
            </section>

            <section class="card space-y-2 p-4 text-sm text-stone-600">
                <h2 class="font-display font-semibold text-stone-900">Ce qui est envoyé</h2>
                <p>Uniquement la photo (ou le PDF) du ticket, recadrée et réduite, sans aucune autre donnée du foyer.</p>
                <p>Un numéro de carte bancaire éventuellement lu n'est jamais enregistré, même partiellement.</p>
                <p>Les photos restent sur votre serveur, accessibles seulement une fois connecté.</p>
            </section>
        </aside>
    </div>
</div>
