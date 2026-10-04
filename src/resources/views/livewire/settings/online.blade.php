<div>
    <x-page-header title="Mise en ligne" subtitle="Tâches planifiées, surveillance, sécurité et sauvegardes de l'installation en ligne." />
    <x-settings-nav />

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- ============================================================ Tâches planifiées (27.3) --}}
        <section class="card space-y-4 p-4 sm:p-5 lg:col-span-2">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-display font-semibold text-stone-900">Tâches planifiées</h2>
                    <p class="mt-1 text-sm text-stone-600">Rappels, notifications, sauvegarde du jour et e-mail de la semaine. Chez OVH, la tâche planifiée ne tourne qu'une fois par heure : un service externe gratuit appelle cette adresse toutes les 5 minutes.</p>
                </div>
                @if ($last)
                    @php $late = $lastMinutes > $maxDelay; @endphp
                    <span @class(['inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1',
                        'bg-emerald-50 text-emerald-800 ring-emerald-200' => ! $late && ! $last['errors'],
                        'bg-amber-50 text-amber-800 ring-amber-200' => ! $late && $last['errors'],
                        'bg-red-50 text-red-800 ring-red-200' => $late])>
                        <x-icon :name="$late || $last['errors'] ? 'warning' : 'check'" class="size-4" />
                        {{ $late ? 'En retard' : ($last['errors'] ? 'Erreurs' : 'Tourne') }}
                    </span>
                @else
                    <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-stone-100 px-2.5 py-1 text-xs font-medium text-stone-700"><x-icon name="info" class="size-4" /> Jamais lancées</span>
                @endif
            </div>

            <div>
                <p class="form-label">Adresse à appeler (garder secrète)</p>
                <div class="flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                    <code class="min-w-0 flex-1 rounded-lg bg-stone-100 px-3 py-2 font-mono text-xs break-all text-stone-900">{{ $showToken ? $taskUrl : $maskedUrl }}</code>
                    @if ($showToken)
                        <button type="button" class="btn btn-secondary" x-on:click="navigator.clipboard?.writeText(@js($taskUrl)).then(() => copied = true)">
                            <x-icon name="duplicate" class="size-4" /> <span x-text="copied ? 'Copiée' : 'Copier'">Copier</span>
                        </button>
                    @else
                        <button type="button" wire:click="revealToken" class="btn btn-secondary"><x-icon name="eye" class="size-4" /> Afficher</button>
                    @endif
                    <button type="button" wire:click="regenerateToken" wire:confirm="L'adresse actuelle cessera de fonctionner. Continuer ?" class="btn btn-ghost">Changer</button>
                </div>
            </div>

            <div class="grid gap-4 text-sm text-stone-600 md:grid-cols-2">
                <div class="rounded-lg bg-stone-50 p-3">
                    <p class="font-medium text-stone-900">1. Service externe (toutes les 5 minutes)</p>
                    <p>Sur cron-job.org (gratuit) : « Create cronjob », collez l'adresse, « Every 5 minutes ». Il prévient aussi par e-mail si l'appel échoue.</p>
                </div>
                <div class="rounded-lg bg-stone-50 p-3">
                    <p class="font-medium text-stone-900">2. Secours : tâche planifiée OVH (toutes les heures)</p>
                    <p>Espace client OVH → Tâches planifiées - Cron → Ajouter : script <code class="rounded bg-white px-1 break-words">{{ str_contains($cronPath, '/www/') ? 'www/'.\Illuminate\Support\Str::after($cronPath, '/www/') : 'www/…/src/cron.php' }}</code>, PHP 8.4, toutes les heures.</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-stone-100 pt-3 text-sm">
                <p class="text-stone-600">
                    @if ($last)
                        Dernier passage {{ $last['at']->locale('fr')->diffForHumans() }} ({{ $last['source'] }}{{ $last['seconds'] ? ', '.number_format($last['seconds'], 1, ',', '').' s' : '' }}).
                    @else
                        Aucun passage enregistré pour l'instant.
                    @endif
                </p>
                <button type="button" wire:click="runNow" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="runNow">Lancer maintenant</button>
            </div>
            @if ($last && $last['errors'])
                <ul class="space-y-1 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                    @foreach ($last['errors'] as $error)
                        <li class="flex gap-2"><x-icon name="warning" class="mt-0.5 size-4 shrink-0" /> {{ $error }}</li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- ============================================================ Sécurité (27.4 à 27.7) --}}
        <section class="card p-4 sm:p-5">
            <h2 class="font-display mb-3 font-semibold text-stone-900">Sécurité</h2>
            <ul class="divide-y divide-stone-100">
                @foreach ($security as $item)
                    <li class="flex items-start gap-3 py-2.5">
                        <span @class(['mt-0.5 shrink-0', 'text-emerald-700' => $item['ok'], 'text-amber-700' => ! $item['ok']])>
                            <x-icon :name="$item['ok'] ? 'check' : 'warning'" class="size-5" />
                            <span class="sr-only">{{ $item['ok'] ? 'En place' : 'À faire' }}</span>
                        </span>
                        <div class="min-w-0 flex-1 text-sm">
                            <p class="font-medium text-stone-900">{{ $item['label'] }}</p>
                            <p class="break-words text-stone-600">{{ $item['value'] }}</p>
                            @unless ($item['ok']) <p class="text-xs text-stone-500">{{ $item['help'] }}</p> @endunless
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <div class="space-y-6">
            {{-- ============================================================ Surveillance (27.11) --}}
            <section class="card space-y-3 p-4 text-sm sm:p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-display font-semibold text-stone-900">Surveillance</h2>
                    <span @class(['inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1',
                        'bg-emerald-50 text-emerald-800 ring-emerald-200' => $health['status'] === 'ok',
                        'bg-red-50 text-red-800 ring-red-200' => $health['status'] !== 'ok'])>
                        <x-icon :name="$health['status'] === 'ok' ? 'check' : 'warning'" class="size-4" /> {{ $health['status'] === 'ok' ? 'Tout va bien' : 'En panne' }}
                    </span>
                </div>
                <p class="text-stone-600">Un service gratuit (UptimeRobot, Better Stack…) peut ouvrir cette adresse toutes les 5 minutes et vous prévenir si elle ne répond plus « ok » :</p>
                <code class="block rounded-lg bg-stone-100 px-3 py-2 font-mono text-xs break-all text-stone-900">{{ $healthUrl }}</code>
                <ul class="space-y-1 text-stone-600">
                    @foreach (['base' => 'Base de données', 'stockage' => 'Stockage', 'taches' => 'Tâches'] as $key => $label)
                        <li class="flex items-center gap-2">
                            <x-icon :name="$health['checks'][$key]['status'] === 'ok' ? 'check' : 'warning'" class="size-4 {{ $health['checks'][$key]['status'] === 'ok' ? 'text-emerald-700' : 'text-red-700' }}" />
                            {{ $label }}{{ isset($health['checks'][$key]['detail']) ? ' — '.$health['checks'][$key]['detail'] : '' }}
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- ============================================================ Sauvegardes en ligne (27.9) --}}
            <section class="card space-y-2 p-4 text-sm text-stone-600 sm:p-5">
                <h2 class="font-display font-semibold text-stone-900">Sauvegardes en ligne</h2>
                <p>
                    Automatique tous les {{ $backup['autoDays'] }} jour(s), {{ $backup['keep'] }} gardées.
                    @if ($backup['latest']) Dernière : {{ $backup['latest']->createdAt->locale('fr')->isoFormat('D MMMM [à] HH:mm') }}. @endif
                </p>
                <p>
                    @if ($backup['weekly'])
                        E-mail avec le lien de téléchargement chaque {{ $backup['weeklyDay'] }}{{ $backup['lastEmail'] ? ' — dernier envoi '.$backup['lastEmail']->locale('fr')->diffForHumans() : '' }}.
                    @else
                        Pas d'e-mail hebdomadaire : pensez à télécharger une sauvegarde de temps en temps (BOUFFE_BACKUP_WEEKLY_EMAIL=true pour le recevoir).
                    @endif
                </p>
                <a href="{{ route('settings.backups') }}" wire:navigate class="inline-flex items-center gap-1 font-medium text-brand-700 hover:underline"><x-icon name="archive" class="size-4" /> Sauvegardes</a>
            </section>
        </div>
    </div>

    <p class="mt-6 text-sm text-stone-500">Mode d'emploi complet : <code class="rounded bg-stone-100 px-1">docs/10-mise-en-ligne-ovh.md</code>.</p>
</div>
