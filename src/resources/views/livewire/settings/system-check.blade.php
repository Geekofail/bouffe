<div>
    <x-page-header title="Diagnostic" subtitle="L'état de l'installation, et quoi faire quand quelque chose cloche." />
    <x-settings-nav />

    {{-- ============================================================ Bilan --}}
    <div class="card mb-6 flex flex-wrap items-center gap-4 p-5">
        <div @class([
            'flex size-12 shrink-0 items-center justify-center rounded-full',
            'bg-red-100 text-red-700' => $summary['error'] > 0,
            'bg-amber-100 text-amber-700' => $summary['error'] === 0 && $summary['warn'] > 0,
            'bg-herb-100 text-herb-700' => $summary['error'] === 0 && $summary['warn'] === 0,
        ])>
            <x-icon :name="$summary['error'] > 0 ? 'warning' : ($summary['warn'] > 0 ? 'info' : 'success')" class="size-6" />
        </div>

        <div class="min-w-0 flex-1">
            <h2 class="font-display font-semibold text-stone-900">
                @if ($summary['error'] > 0)
                    {{ $summary['error'] }} point(s) à corriger
                @elseif ($summary['warn'] > 0)
                    Tout fonctionne, {{ $summary['warn'] }} point(s) à surveiller
                @else
                    Tout va bien
                @endif
            </h2>
            <p class="mt-0.5 text-sm text-stone-500">
                {{ $summary['ok'] }} vérification(s) au vert sur {{ $summary['ok'] + $summary['warn'] + $summary['error'] }}.
            </p>
        </div>

        <button type="button" wire:click="refresh" class="btn btn-secondary">
            <x-icon name="sparkles" class="size-4" wire:loading.class="animate-spin" wire:target="refresh" /> Relancer
        </button>
    </div>

    {{-- ============================================================ Vérifications --}}
    <div class="card divide-y divide-stone-100 overflow-hidden">
        @foreach ($checks as $check)
            <div class="flex items-start gap-3 p-4">
                <span @class([
                    'mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full',
                    'bg-red-100 text-red-700' => $check['status'] === 'error',
                    'bg-amber-100 text-amber-700' => $check['status'] === 'warn',
                    'bg-herb-100 text-herb-700' => $check['status'] === 'ok',
                ])>
                    <x-icon :name="$check['status'] === 'error' ? 'warning' : ($check['status'] === 'warn' ? 'info' : 'success')" class="size-4" />
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                        <h3 class="font-medium text-stone-900">{{ $check['label'] }}</h3>
                        <span @class([
                            'text-sm',
                            'font-medium text-red-700' => $check['status'] === 'error',
                            'text-stone-600' => $check['status'] !== 'error',
                        ])>{{ $check['value'] }}</span>
                    </div>
                    @if ($check['help'])
                        <p class="mt-1 text-sm text-stone-500">{{ $check['help'] }}</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- ============================================================ Emporter ses données (20.8) --}}
    <div class="card mt-6 p-5">
        <h2 class="font-display flex items-center gap-2 font-semibold text-stone-900">
            <x-icon name="archive" class="size-5 text-brand-600" /> Emporter mes données
        </h2>
        <p class="mt-1 text-sm text-stone-500">
            Une archive avec les recettes, le planning, les listes, le stock et les photos, lisible sans Bouffe
            (du texte et du JSON). Ce n'est pas une sauvegarde : pour remettre Bouffe en état après une panne,
            passez par <a href="{{ route('settings.backups') }}" wire:navigate class="font-medium text-brand-700">Sauvegardes</a>.
        </p>
        <a href="{{ route('settings.export') }}" class="btn btn-primary mt-4">
            <x-icon name="download" class="size-4" /> Télécharger l'export
        </a>
    </div>

    {{-- ============================================================ À propos et mise à jour (20.6) --}}
    <div class="card mt-6 p-5">
        <h2 class="font-display flex items-center gap-2 font-semibold text-stone-900">
            <x-icon name="info" class="size-5 text-brand-600" /> À propos
        </h2>

        <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            @foreach ([
                'Version de Bouffe' => $about['version'],
                'Laravel' => $about['laravel'],
                'PHP' => $about['php'],
                'Base de données' => $about['database'],
                'Adresse' => $about['url'],
                'Dossier' => $about['path'],
                'Recettes' => $about['recipes'],
                'Comptes' => $about['users'],
            ] as $label => $value)
                <div class="flex justify-between gap-3 border-b border-stone-100 pb-1">
                    <dt class="text-stone-500">{{ $label }}</dt>
                    <dd class="truncate text-right font-medium text-stone-800">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        <h3 class="mt-5 font-medium text-stone-900">Mettre Bouffe à jour</h3>
        <p class="mt-1 text-sm text-stone-500">
            Fermez l'éditeur, remplacez le dossier <code class="rounded bg-stone-100 px-1">src</code> par la nouvelle version,
            puis, dans une invite de commande ouverte dans ce dossier :
        </p>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-stone-900 p-3 text-xs text-stone-100">php artisan bouffe:deploy</pre>
        <p class="mt-2 text-sm text-stone-500">
            La commande fait une sauvegarde, met la base de données à jour, vide les caches et affiche ce diagnostic.
        </p>
    </div>
</div>
