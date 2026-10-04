@php
    // Un compte « consultation et courses » ne voit que les sections qu'il peut ouvrir (18.5).
    $canEdit = auth()->user()?->canEdit() ?? false;
    $viewable = ['settings.display', 'settings.household', 'journal', 'settings.notifications', 'account.show', 'privacy'];

    $sections = [
        ['route' => 'account.show', 'label' => 'Mon compte'],
        ['route' => 'settings.ingredients', 'label' => 'Ingrédients'],
        ['route' => 'settings.aisles', 'label' => 'Rayons'],
        ['route' => 'settings.stores', 'label' => 'Magasins'],
        ['route' => 'settings.units', 'label' => 'Unités'],
        ['route' => 'settings.tags', 'label' => 'Catégories'],
        ['route' => 'settings.slots', 'label' => 'Créneaux'],
        ['route' => 'settings.planning', 'label' => 'Planning'],
        ['route' => 'settings.equipment', 'label' => 'Équipement'],
        ['route' => 'settings.substitutions', 'label' => 'Remplacements'],
        ['route' => 'settings.household', 'label' => 'Foyer'],
        ['route' => 'journal', 'label' => 'Journal'],
        ['route' => 'settings.recurring', 'label' => 'Articles récurrents'],
        ['route' => 'settings.locations', 'label' => 'Emplacements'],
        ['route' => 'settings.stock', 'label' => 'Alertes stock'],
        ['route' => 'settings.seasons', 'label' => 'Saisons'],
        ['route' => 'settings.nutrition', 'label' => 'Nutrition'],
        ['route' => 'settings.budget', 'label' => 'Budget'],
        ['route' => 'settings.receipts', 'label' => 'Tickets'],
        ['route' => 'settings.assistant', 'label' => 'Assistant'],
        ['route' => 'settings.backups', 'label' => 'Sauvegardes'],
        ['route' => 'settings.phone', 'label' => 'Accès téléphone'],
        ['route' => 'settings.display', 'label' => 'Affichage'],
        ['route' => 'settings.notifications', 'label' => 'Notifications'],
        ['route' => 'settings.diagnostic', 'label' => 'Diagnostic'],
        ['route' => 'settings.online', 'label' => 'Mise en ligne'],
        ['route' => 'privacy', 'label' => 'Vos données'],
    ];

    // Lot 24 : sauvegardes et diagnostic concernent toute l'installation → administrateur seulement.
    $adminOnly = ['settings.backups', 'settings.diagnostic', 'settings.online'];
    $isAdmin = auth()->user()?->isAdmin() ?? false;

    $sections = array_values(array_filter($sections, fn ($s) => ($canEdit || in_array($s['route'], $viewable, true)) && ($isAdmin || ! in_array($s['route'], $adminOnly, true))));
@endphp

<nav class="-mx-4 mb-6 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Sections des paramètres">
    <div class="flex min-w-max gap-1 rounded-xl bg-stone-100 p-1">
        @foreach ($sections as $section)
            @php $active = request()->routeIs($section['route']); @endphp
            <a href="{{ route($section['route']) }}" wire:navigate
               @class([
                   'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                   'bg-white text-stone-900 shadow-sm' => $active,
                   'text-stone-600 hover:text-stone-900' => ! $active,
               ])
               @if ($active) aria-current="page" @endif>
                {{ $section['label'] }}
            </a>
        @endforeach
    </div>
</nav>
