<div>
    <x-page-header title="Paramètres" subtitle="Les listes de référence utilisées par les recettes et la liste de courses." />
    <x-settings-nav />

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($sections as $section)
            @continue(! $isAdmin && in_array($section['route'], ['settings.backups', 'settings.diagnostic', 'settings.online'], true))
            <a href="{{ route($section['route']) }}" wire:navigate class="card group flex items-start gap-4 p-5 transition hover:ring-brand-300">
                <div class="rounded-lg bg-brand-50 p-2.5 text-brand-600 transition group-hover:bg-brand-600 group-hover:text-white">
                    <x-icon :name="$section['icon']" class="size-6" />
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="font-display flex items-center justify-between font-semibold text-stone-900">
                        {{ $section['title'] }}
                        @if ($section['count'] !== null)
                            <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-600 tabular-nums">{{ $section['count'] }}</span>
                        @endif
                    </h2>
                    <p class="mt-1 text-sm text-stone-500">{{ $section['text'] }}</p>
                </div>
            </a>
        @endforeach
    </div>

    @if ($sections[0]['count'] === 0)
        <div class="mt-6 flex gap-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
            <x-icon name="info" class="size-5 shrink-0" />
            <p>Les listes sont vides. Lancez <code class="rounded bg-white px-1">php artisan db:seed</code> dans le dossier <code class="rounded bg-white px-1">src</code> pour charger les unités, rayons, catégories, créneaux et ~150 ingrédients courants.</p>
        </div>
    @endif
    <p class="mt-8 text-center text-xs text-stone-500">Bouffe {{ $version }} · Laravel {{ app()->version() }} · PHP {{ PHP_VERSION }}</p>
</div>
