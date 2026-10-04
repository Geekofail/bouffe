<div class="max-w-3xl">
    <x-page-header title="Raccourcis et Siri" subtitle="Ajouter aux courses à la voix, entendre le menu, envoyer une recette depuis Safari." />

    <a href="{{ route('account.show') }}" wire:navigate class="mb-4 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Mon compte
    </a>

    @unless ($secure)
        <div class="mb-4 flex gap-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
            <x-icon name="warning" class="size-5 shrink-0" />
            <p>Les raccourcis appellent Bouffe depuis l'iPhone, <strong>même hors de la maison</strong> : ils ont besoin de l'adresse en
                https de Bouffe (voir « Accès depuis l'extérieur »). À la maison, sur le Wi-Fi, ils fonctionnent avec l'adresse actuelle.</p>
        </div>
    @endunless

    {{-- ============================================================ Jeton --}}
    <section class="card space-y-3 p-4 sm:p-5" data-shortcut-token>
        <h2 class="font-display text-lg font-semibold text-stone-900">Votre jeton personnel</h2>
        <p class="text-sm text-stone-600">
            Il remplace votre mot de passe dans les raccourcis, et ne permet que
            {{ $canEdit ? 'ajouter aux courses ou au stock, lire le menu du jour et du lendemain, et déposer une recette dans « À trier »' : 'ajouter aux courses et lire le menu du jour et du lendemain' }}.
            Jamais de suppression, ni de dépenses. 60 demandes par heure au plus ; chaque usage est noté au journal du foyer.
        </p>

        @if ($plain !== '')
            <div class="space-y-2 rounded-xl bg-herb-50 p-3 ring-1 ring-herb-100" x-data="{ copied: false }">
                <p class="text-sm font-medium text-herb-900">Recopiez-le maintenant : il ne sera plus affiché.</p>
                <div class="flex flex-wrap gap-2">
                    <input type="text" readonly value="Bearer {{ $plain }}" class="form-input min-w-0 flex-1 basis-64 font-mono text-xs" x-on:focus="$el.select()" aria-label="Valeur de l'en-tête Authorization">
                    <button type="button" class="btn btn-secondary" x-on:click="navigator.clipboard?.writeText(@js('Bearer '.$plain)); copied = true"><span x-text="copied ? 'Copié' : 'Copier'">Copier</span></button>
                </div>
            </div>
        @endif

        @if ($token)
            <p class="text-sm text-stone-600">
                Jeton actif, se terminant par <span class="font-mono">…{{ $token->hint }}</span>, créé {{ $token->created_at->locale('fr')->diffForHumans() }}
                · {{ $token->uses > 0 ? 'utilisé '.$token->uses.' fois, la dernière '.$token->last_used_at?->locale('fr')->diffForHumans() : 'jamais utilisé' }}.
            </p>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="create" wire:confirm="Le jeton actuel cessera de fonctionner : il faudra mettre le nouveau dans chaque raccourci. Continuer ?" class="btn btn-secondary">Nouveau jeton</button>
                <button type="button" wire:click="revoke" wire:confirm="Révoquer le jeton ? Les raccourcis ne fonctionneront plus." class="btn btn-ghost text-red-700 hover:bg-red-50">Révoquer</button>
            </div>
        @else
            <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="lock" class="size-4" /> Créer mon jeton</button>
        @endif
    </section>

    {{-- ============================================================ Mode d'emploi --}}
    <section class="mt-6 space-y-4">
        <h2 class="font-display text-lg font-semibold text-stone-900">Créer les raccourcis sur l'iPhone</h2>
        <p class="text-sm text-stone-600">
            Dans l'application <strong>Raccourcis</strong>, touchez <strong>+</strong>, donnez au raccourci le nom indiqué (c'est ce que vous
            direz à Siri), puis ajoutez les actions dans l'ordre. Dans « Obtenir le contenu de l'URL », touchez la flèche pour voir
            <em>Méthode</em>, <em>En-têtes</em> et <em>Corps de la requête</em>. Les noms des actions peuvent légèrement varier selon la version d'iOS.
        </p>

        @php
            $header = 'En-tête : Authorization = Bearer + votre jeton (« Bearer bouffe_… »).';
            $guides = [
                ['Ajouter aux courses', '« Dis Siri, ajouter aux courses » → « deux baguettes et du beurre »', true, [
                    'Dicter du texte (langue : français).',
                    'Obtenir le contenu de l\'URL : '.$urls['courses'].' · Méthode POST · '.$header.' · Corps : Formulaire, champ texte = Texte dicté.',
                    'Énoncer le texte : Contenu de l\'URL (Siri répond « Ajouté aux courses : 2 baguettes et beurre »).',
                ]],
                ['On mange quoi', '« Dis Siri, on mange quoi » → « Ce soir : chili con carne. Demain… »', true, [
                    'Obtenir le contenu de l\'URL : '.$urls['menu'].' · Méthode GET · '.$header.' (ajoutez ?quand=demain pour le seul lendemain).',
                    'Énoncer le texte : Contenu de l\'URL.',
                ]],
                ['Ajouter au stock', '« Dis Siri, ajouter au stock » → « six œufs et un litre de lait »', $canEdit, [
                    'Dicter du texte.',
                    'Obtenir le contenu de l\'URL : '.$urls['stock'].' · Méthode POST · '.$header.' · Corps : Formulaire, champ texte = Texte dicté.',
                    'Énoncer le texte : Contenu de l\'URL.',
                ]],
                ['Envoyer à Bouffe', 'Dans Safari (ou Instagram, Messages, Photos) : Partager › Envoyer à Bouffe', $canEdit, [
                    'Dans les réglages du raccourci (ⓘ) : activer « Afficher dans la feuille de partage », types URL, pages Safari, texte et images.',
                    'Obtenir le contenu de l\'URL : '.$urls['inbox'].' · Méthode POST · '.$header.' · Corps : Formulaire, champ url = Entrée du raccourci (pour une photo : champ photo, de type Fichier).',
                    'Afficher la notification : Contenu de l\'URL (« Tarte tatin est dans À trier »).',
                ]],
            ];
        @endphp

        @foreach ($guides as [$name, $example, $allowed, $steps])
            @continue (! $allowed)
            <article class="card p-4 sm:p-5" wire:key="guide-{{ \Illuminate\Support\Str::slug($name) }}">
                <h3 class="font-semibold text-stone-900">{{ $name }}</h3>
                <p class="mb-2 text-sm text-stone-500">{{ $example }}</p>
                <ol class="list-decimal space-y-1.5 pl-5 text-sm text-stone-700">
                    @foreach ($steps as $step)
                        <li class="break-words">{{ $step }}</li>
                    @endforeach
                </ol>
            </article>
        @endforeach

        <p class="text-sm text-stone-600">
            <strong>Sur Android</strong> : une fois Bouffe installé sur l'écran d'accueil (menu du navigateur › Installer), il apparaît
            directement dans le menu <em>Partager</em> ; rien à créer.
        </p>
    </section>
</div>
