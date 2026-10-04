<div>
    <x-page-header title="Assistant culinaire" subtitle="Adapter une recette, trouver des idées, compléter un import, poser une question en cuisine." />
    <x-settings-nav />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            <section @class(['flex items-start gap-3 rounded-xl p-4 text-sm ring-1', 'bg-herb-50 text-herb-900 ring-herb-100' => $status['available'], 'bg-amber-50 text-amber-900 ring-amber-200' => ! $status['available']]) data-assistant-status>
                <x-icon :name="$status['available'] ? 'success' : 'warning'" class="size-5 shrink-0" />
                <p>
                    @if ($status['available'])
                        <strong>Prêt</strong> · {{ $status['label'] }}. Ses boutons apparaissent sur les recettes, dans « Que cuisiner ? », à l'import et en mode cuisine.
                    @else
                        <strong>Indisponible</strong> · {{ $status['reason'] }} Ses boutons sont masqués ; tout le reste de Bouffe fonctionne normalement.
                    @endif
                </p>
            </section>

            @if (auth()->user()->isAdmin())
                <form wire:submit="save" class="card space-y-5 p-4 sm:p-5">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model="enabled" class="form-checkbox mt-1">
                        <span>
                            <span class="font-medium text-stone-900">Activer l'assistant</span>
                            <span class="block text-sm text-stone-500">Pour toute l'installation. Décoché, aucune demande n'est envoyée.</span>
                        </span>
                    </label>

                    <x-field label="Plafond par mois (€)" for="assistant-cap" error="monthlyCap"
                             help="Estimation d'après le tarif publié de Mistral Small. Au-delà, l'assistant se met en pause jusqu'au mois suivant. 0 = aucune demande.">
                        <input id="assistant-cap" type="text" inputmode="decimal" wire:model="monthlyCap" class="form-input sm:w-32">
                    </x-field>

                    <div>
                        <x-field label="Clé d'API Mistral" for="assistant-key" error="mistralKey"
                                 help="La même que pour les tickets de caisse. console.mistral.ai → API Keys. Laissez vide pour garder la clé actuelle.">
                            <input id="assistant-key" type="password" autocomplete="off" wire:model="mistralKey" class="form-input" placeholder="{{ $keySource ? '••••••••••••' : 'Collez la clé ici' }}">
                        </x-field>
                        <p class="mt-1 flex items-center gap-1 text-sm">
                            @if ($keySource)
                                <span class="flex items-center gap-1 text-herb-700"><x-icon name="success" class="size-4" /> {{ $keySource }}</span>
                            @else
                                <span class="flex items-center gap-1 text-amber-800"><x-icon name="warning" class="size-4" /> Aucune clé</span>
                            @endif
                        </p>
                    </div>

                    <div class="flex justify-end"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
                </form>
            @endif

            <section class="card space-y-2 p-4 text-sm text-stone-600 sm:p-5">
                <h2 class="font-display font-semibold text-stone-900">Ce qui est envoyé</h2>
                <ul class="list-inside list-disc space-y-1">
                    <li>la recette concernée (titre, portions, temps, ingrédients, étapes), ou la liste d'ingrédients que vous tapez ;</li>
                    <li>la transformation choisie (« sans lactose ») ou votre question.</li>
                </ul>
                <p><strong>Jamais</strong> : les noms des membres ou des invités, leurs contraintes, le stock, les dépenses, vos notes de cuisine.</p>
                <p>Les réponses sont des brouillons marqués <x-assistant-mark class="align-middle" /> : rien n'est enregistré sans votre accord. Le contenu des demandes n'est pas conservé, seulement leur nombre et leur coût.</p>
            </section>
        </div>

        <aside class="space-y-4">
            <section class="card p-4 text-sm">
                <h2 class="font-display mb-1 font-semibold text-stone-900">Ce mois-ci</h2>
                <p class="mb-3 text-stone-600">
                    <span class="text-lg font-semibold text-stone-900 tabular-nums">{{ \App\Services\Assistant\AssistantService::euros($usage['cost']) }}</span>
                    sur {{ \App\Services\Assistant\AssistantService::euros($usage['cap']) }}
                </p>
                <div class="mb-3 h-2 overflow-hidden rounded-full bg-stone-100" role="img" aria-label="Part du plafond utilisée">
                    <div class="h-full rounded-full bg-violet-500" style="width: {{ $usage['cap'] > 0 ? min(100, round($usage['cost'] / $usage['cap'] * 100)) : 100 }}%"></div>
                </div>
                <dl class="space-y-1">
                    @foreach ($kinds as $kind => $label)
                        <div class="flex justify-between gap-2">
                            <dt class="text-stone-600">{{ $label }}</dt>
                            <dd class="font-medium text-stone-900 tabular-nums">{{ $usage['by_kind'][$kind] ?? 0 }}</dd>
                        </div>
                    @endforeach
                    @if ($usage['failed'] > 0)
                        <div class="flex justify-between gap-2 text-amber-800"><dt>Échecs</dt><dd class="tabular-nums">{{ $usage['failed'] }}</dd></div>
                    @endif
                </dl>
            </section>

            <section class="card p-4 text-sm">
                <h2 class="font-display mb-2 font-semibold text-stone-900">Par mois</h2>
                <table class="w-full">
                    <thead><tr class="text-left text-xs text-stone-500"><th class="py-1 font-medium">Mois</th><th class="py-1 text-right font-medium">Demandes</th><th class="py-1 text-right font-medium">Coût estimé</th></tr></thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($months as $month)
                            <tr>
                                <td class="py-1.5 text-stone-700">{{ $month['label'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $month['count'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ \App\Services\Assistant\AssistantService::euros($month['cost']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        </aside>
    </div>
</div>
