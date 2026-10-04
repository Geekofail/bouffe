<div>
    <x-page-header title="Administration" subtitle="Les foyers de cette installation. Leur contenu reste privé : cet écran n'y donne pas accès." />

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="card min-w-0 overflow-hidden lg:col-span-2">
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-stone-200 px-4 py-3">
                <h2 class="font-display font-semibold text-stone-900">{{ $households->count() }} foyer{{ $households->count() > 1 ? 's' : '' }}</h2>
                <p class="text-sm text-stone-500">{{ $accounts }} compte{{ $accounts > 1 ? 's' : '' }}@if ($without) · {{ $without }} sans foyer @endif</p>
            </div>
            <ul class="divide-y divide-stone-100">
                @foreach ($households as $row)
                    @php $h = $row['household']; @endphp
                    <li wire:key="h-{{ $h->id }}" @class(['px-4 py-3', 'opacity-60' => ! $h->isActive()])>
                        <div class="flex flex-wrap items-start gap-x-3 gap-y-1">
                            <div class="min-w-0 basis-full sm:flex-1 sm:basis-0">
                                <p class="font-medium text-stone-900">
                                    {{ $h->name }}
                                    @if (! $h->isActive()) <span class="ml-1 rounded-full bg-stone-200 px-2 py-0.5 text-xs font-medium text-stone-700">désactivé</span> @endif
                                    @if ($h->deletion_requested_at) <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">suppression le {{ $h->deletionDate()->locale('fr')->isoFormat('D MMM') }}</span> @endif
                                </p>
                                <p class="text-sm text-stone-600">
                                    {{ $h->members_count }} membre{{ $h->members_count > 1 ? 's' : '' }}
                                    @if ($row['owners']) · responsable{{ count($row['owners']) > 1 ? 's' : '' }} : {{ implode(', ', $row['owners']) }} @endif
                                    @if ($row['pending']) · {{ $row['pending'] }} invitation{{ $row['pending'] > 1 ? 's' : '' }} en attente @endif
                                </p>
                                <p class="text-xs text-stone-500">
                                    {{ $row['recipes'] }} recette{{ $row['recipes'] > 1 ? 's' : '' }} ·
                                    fichiers : {{ $row['bytes'] >= 1048576 ? number_format($row['bytes'] / 1048576, 1, ',', ' ').' Mo' : number_format($row['bytes'] / 1024, 0, ',', ' ').' Ko' }} ·
                                    dernier passage : {{ $row['last_active'] ? \Illuminate\Support\Carbon::parse($row['last_active'])->locale('fr')->diffForHumans() : 'jamais' }}
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2 pt-1 sm:pt-0">
                                <button type="button" wire:click="ownerInvite({{ $h->id }})" class="btn btn-ghost px-2 text-sm"><x-icon name="link" class="size-4" /> Lien responsable</button>
                                @if ($h->deletion_requested_at)
                                    <button type="button" wire:click="cancelDeletion({{ $h->id }})" class="btn btn-secondary text-sm">Annuler la suppression</button>
                                @endif
                                <button type="button" wire:click="toggle({{ $h->id }})" wire:confirm="{{ $h->isActive() ? 'Désactiver' : 'Réactiver' }} « {{ $h->name }} » ?" class="btn btn-secondary text-sm">{{ $h->isActive() ? 'Désactiver' : 'Réactiver' }}</button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <aside class="space-y-4">
            <form wire:submit="create" class="card space-y-4 p-4">
                <h2 class="font-display font-semibold text-stone-900">Nouveau foyer</h2>
                <x-field label="Nom" for="a-name" error="newName">
                    <input id="a-name" type="text" wire:model="newName" maxlength="100" class="form-input" placeholder="ex. Léo et Clara">
                </x-field>
                <x-field label="Adresse du responsable" for="a-owner" error="ownerEmail" optional help="Le lien ne servira qu'à cette adresse.">
                    <input id="a-owner" type="email" wire:model="ownerEmail" class="form-input">
                </x-field>
                <button type="submit" class="btn btn-primary w-full">Créer le foyer et son lien</button>
                <p class="text-xs text-stone-500">Le foyer reçoit les créneaux, emplacements, catégories et postes de budget de départ. Son responsable invitera ensuite les autres membres.</p>
            </form>

            @if ($inviteUrl)
                <section x-data="{ copied: false }" class="card space-y-2 p-4 text-sm ring-2 ring-herb-200">
                    <h2 class="font-display font-semibold text-stone-900">Lien de responsable — {{ $inviteFor }}</h2>
                    <p class="text-stone-600">À envoyer maintenant : il ne sera plus affiché. Valable {{ \App\Models\Invitation::VALID_DAYS }} jours, une fois.</p>
                    <input type="text" readonly value="{{ $inviteUrl }}" class="form-input font-mono text-xs" x-on:focus="$el.select()" aria-label="Lien d'invitation">
                    <button type="button" class="btn btn-secondary w-full" x-on:click="navigator.clipboard?.writeText(@js($inviteUrl)); copied = true">
                        <x-icon name="duplicate" class="size-4" /> <span x-text="copied ? 'Copié' : 'Copier le lien'"></span>
                    </button>
                </section>
            @endif

            <section class="card space-y-2 p-4 text-sm text-stone-600">
                <h2 class="font-display font-semibold text-stone-900">Installation</h2>
                <p>Le catalogue (ingrédients, unités, rayons, saisons, nutrition) est commun à tous les foyers : vous seul le modifiez dès qu'il y a plusieurs foyers.</p>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('settings.backups') }}" wire:navigate class="btn btn-ghost px-2 text-sm"><x-icon name="archive" class="size-4" /> Sauvegardes</a>
                    <a href="{{ route('settings.diagnostic') }}" wire:navigate class="btn btn-ghost px-2 text-sm"><x-icon name="info" class="size-4" /> Diagnostic</a>
                    <a href="{{ route('settings.receipts') }}" wire:navigate class="btn btn-ghost px-2 text-sm"><x-icon name="receipt" class="size-4" /> Lecture des tickets</a>
                    <a href="{{ route('settings.assistant') }}" wire:navigate class="btn btn-ghost px-2 text-sm"><x-icon name="sparkles" class="size-4" /> Assistant</a>
                </div>
            </section>
        </aside>
    </div>
</div>
