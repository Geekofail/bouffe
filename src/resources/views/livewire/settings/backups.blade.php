<div>
    <x-page-header title="Paramètres" subtitle="Les listes de référence utilisées par les recettes et la liste de courses." />
    <x-settings-nav />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            {{-- État --}}
            <section class="card flex flex-wrap items-center gap-4 p-5">
                <div @class(['rounded-full p-3', 'bg-herb-50 text-herb-600' => ! $isOld, 'bg-amber-50 text-amber-600' => $isOld])>
                    <x-icon :name="$isOld ? 'warning' : 'success'" class="size-7" />
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="font-display font-semibold text-stone-900">
                        @if ($latest)
                            Dernière sauvegarde {{ $latest->createdAt->locale('fr')->diffForHumans() }}
                        @else
                            Aucune sauvegarde pour l'instant
                        @endif
                    </h2>
                    <p class="text-sm text-stone-500">
                        @if ($latest)
                            {{ ucfirst($latest->createdAt->locale('fr')->isoFormat('dddd D MMMM YYYY [à] HH:mm')) }} · {{ $latest->summary() }}
                        @else
                            Recettes, planning, listes de courses, comptes et photos sont enregistrés dans une seule archive.
                        @endif
                    </p>
                </div>
                <button type="button" wire:click="create" wire:loading.attr="disabled" class="btn btn-primary w-full sm:w-auto" @disabled(! $zipAvailable)>
                    <x-icon name="archive" class="size-4" wire:loading.remove wire:target="create" />
                    <span wire:loading wire:target="create" class="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                    Sauvegarder maintenant
                </button>
            </section>

            @unless ($zipAvailable)
                <div class="flex gap-3 rounded-xl bg-red-50 p-4 text-sm text-red-900">
                    <x-icon name="warning" class="size-5 shrink-0" />
                    <p>L'extension PHP <strong>zip</strong> est désactivée : clic gauche sur Wamp → PHP → Extensions PHP → cocher <code class="rounded bg-white px-1">zip</code>, puis redémarrer Wamp.</p>
                </div>
            @endunless

            {{-- Liste --}}
            <section class="card min-w-0">
                <div class="border-b border-stone-200 px-4 py-3">
                    <h2 class="font-display font-semibold text-stone-900">Sauvegardes disponibles</h2>
                </div>

                @if ($this->backups->isEmpty())
                    <x-empty-state icon="archive" title="Aucune sauvegarde">Cliquez sur « Sauvegarder maintenant » pour créer la première.</x-empty-state>
                @else
                    <ul class="divide-y divide-stone-100">
                        @foreach ($this->backups as $backup)
                            <li wire:key="backup-{{ $backup->filename }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                                <div class="min-w-0 flex-1">
                                    <p class="flex flex-wrap items-center gap-2 font-medium text-stone-900">
                                        {{ ucfirst($backup->createdAt->locale('fr')->isoFormat('ddd D MMM YYYY, HH:mm')) }}
                                        <x-badge :color="match ($backup->type) { 'manual' => 'orange', 'auto' => 'stone', default => 'amber' }">{{ $backup->typeLabel() }}</x-badge>
                                    </p>
                                    <p class="truncate text-xs text-stone-500">{{ $backup->humanSize() }} @if ($backup->summary()) · {{ $backup->summary() }} @endif</p>
                                </div>
                                <div class="flex items-center gap-1">
                                    <a href="{{ route('settings.backups.download', $backup->filename) }}" class="btn btn-secondary px-3 py-1.5 text-sm" download>Télécharger</a>
                                    <button type="button" wire:click="confirmDelete('{{ $backup->filename }}')" class="btn btn-ghost px-2 hover:text-red-600" title="Supprimer">
                                        <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer</span>
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        {{-- Aide --}}
        <aside class="space-y-4">
            <section class="card space-y-3 p-4 text-sm text-stone-600">
                <h2 class="font-display font-semibold text-stone-900">Automatique</h2>
                @if ($autoDays > 0)
                    <p>Quand l'application est utilisée et que la dernière sauvegarde date de plus de <strong>{{ $autoDays }} jours</strong>, une sauvegarde automatique est créée. Les <strong>{{ $keep }}</strong> plus récentes sont gardées ; les sauvegardes manuelles ne sont jamais supprimées.</p>
                @else
                    <p>Sauvegarde automatique désactivée (<code class="rounded bg-stone-100 px-1">BOUFFE_BACKUP_AUTO_DAYS=0</code>).</p>
                @endif
                <p class="text-xs text-stone-500">Dossier : <code class="break-all rounded bg-stone-100 px-1">{{ $directory }}</code></p>
            </section>

            {{-- Copie hors du PC (20.4) : une panne de disque emporte les sauvegardes avec le reste. --}}
            <section class="card space-y-3 p-4 text-sm text-stone-600">
                <h2 class="font-display flex items-center gap-2 font-semibold text-stone-900">
                    <x-icon :name="$mirror['configured'] ? ($mirror['available'] ? 'success' : 'warning') : 'info'"
                            @class(['size-5', 'text-herb-600' => $mirror['configured'] && $mirror['available'], 'text-amber-600' => $mirror['configured'] && ! $mirror['available'], 'text-stone-500' => ! $mirror['configured']]) />
                    Copie hors du PC
                </h2>

                @if (! $mirror['configured'])
                    <p>Les sauvegardes sont sur le même disque que Bouffe : une panne emporterait tout. Indiquez une clé USB, un disque externe ou un dossier OneDrive dans <code class="rounded bg-stone-100 px-1">BOUFFE_BACKUP_MIRROR</code> (fichier <code>.env</code>) : chaque sauvegarde y sera recopiée.</p>
                @elseif ($mirror['available'])
                    <p><strong>{{ $mirror['copies'] }}</strong> copie(s) dans <code class="break-all rounded bg-stone-100 px-1">{{ $mirror['path'] }}</code>. Les {{ $mirrorKeep ?: 'toutes les' }} plus récentes y sont gardées.</p>
                @else
                    <p class="text-amber-800">{{ $mirror['error'] }} Les sauvegardes du PC continuent normalement ; la copie reprendra une fois <code class="break-all rounded bg-stone-100 px-1">{{ $mirror['path'] }}</code> de nouveau accessible.</p>
                @endif
            </section>

            <section class="card space-y-3 p-4 text-sm text-stone-600">
                <h2 class="font-display font-semibold text-stone-900">Restaurer</h2>
                <p>Par sécurité, la restauration se fait dans un terminal, dans le dossier <code class="rounded bg-stone-100 px-1">src</code> :</p>
                <pre class="overflow-x-auto rounded-lg bg-stone-900 px-3 py-2 text-xs text-stone-100">php artisan bouffe:restore</pre>
                <p>Toutes les données actuelles sont remplacées. L'état actuel est d'abord sauvegardé (« Avant restauration ») pour pouvoir revenir en arrière.</p>
            </section>
        </aside>
    </div>

    <x-modal :show="$deleting !== null" title="Supprimer la sauvegarde ?" close="closeDelete">
        <p class="text-sm text-stone-600">Le fichier <code class="break-all rounded bg-stone-100 px-1">{{ $deleting }}</code> sera définitivement supprimé.</p>
        <x-slot:footer>
            <button type="button" wire:click="closeDelete" class="btn btn-secondary">Annuler</button>
            <button type="button" wire:click="delete" class="btn btn-danger">Supprimer</button>
        </x-slot:footer>
    </x-modal>
</div>
