<div @class(['space-y-6', 'card p-6 sm:p-8' => $guest])>
    @if ($guest)
        <h1 class="text-xl font-semibold text-stone-900">Vos données</h1>
        <p class="text-sm text-stone-600">Ce que Bouffe enregistre, où, qui le voit, et comment tout reprendre ou tout effacer.</p>
    @else
        <x-page-header title="Vos données" subtitle="Ce que Bouffe enregistre, où, qui le voit, et comment tout reprendre ou tout effacer." />
        <x-settings-nav />
    @endif

    <section @class(['rounded-xl bg-brand-50 p-4 text-sm text-stone-800', 'card' => ! $guest])>
        <h2 class="font-display mb-2 font-semibold text-stone-900">En bref</h2>
        <ul class="space-y-1.5">
            <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-brand-700" /> Bouffe est une application privée, installée par {{ $admins->join(', ', ' et ') ?: 'son administrateur' }} pour la famille et les proches. Pas de publicité, pas de revente, pas de mesure d'audience.</li>
            <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-brand-700" /> Chaque foyer ne voit que ses propres recettes, menus, stocks, courses et dépenses.</li>
            <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-brand-700" /> Vous pouvez exporter vos données, quitter un foyer ou supprimer votre compte à tout moment.</li>
        </ul>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card space-y-3 p-4 text-sm text-stone-700 sm:p-5">
            <h2 class="font-display font-semibold text-stone-900">Ce qui est enregistré</h2>
            <dl class="space-y-3">
                <div>
                    <dt class="font-medium text-stone-900">Votre compte</dt>
                    <dd>Prénom, adresse e-mail, mot de passe (chiffré de façon irréversible), réglages d'affichage et de notifications, secret de double authentification (chiffré).</dd>
                </div>
                <div>
                    <dt class="font-medium text-stone-900">Le foyer</dt>
                    <dd>Recettes et photos, planning, réceptions et invités, stock, listes de courses, magasins, dépenses et budget, contraintes alimentaires de chaque membre (ce que vous ne mangez pas, allergies comprises).</dd>
                </div>
                <div>
                    <dt class="font-medium text-stone-900">Tickets de caisse</dt>
                    <dd>
                        Photo du ticket (numéro de carte masqué) et lignes lues.
                        @if ($receiptMonths === 0)
                            Photos effacées dès la validation du ticket.
                        @else
                            Photos effacées au bout de {{ $receiptMonths }} mois ; les montants restent dans le budget.
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-stone-900">Journal des connexions</dt>
                    <dd>Date, adresse IP et navigateur de chaque connexion ou tentative, gardés {{ $journalDays }} jours, visibles dans « Mon compte ». Ils servent à repérer un accès qui ne serait pas le vôtre.</dd>
                </div>
                <div>
                    <dt class="font-medium text-stone-900">Sauvegardes</dt>
                    <dd>Une copie complète de l'installation, automatique, gardée environ {{ $backupDays }} jours ; l'administrateur peut en garder une copie hors de l'hébergement, en cas de panne.</dd>
                </div>
                <div>
                    <dt class="font-medium text-stone-900">Sur votre appareil</dt>
                    <dd>Cookies de session et « rester connecté », cookie « appareil de confiance » si vous le choisissez, thème, minuteurs de cuisine et copie de la liste de courses pour le magasin sans réseau. Aucun cookie publicitaire ou de suivi.</dd>
                </div>
            </dl>
        </section>

        <div class="space-y-6">
            <section class="card space-y-2 p-4 text-sm text-stone-700 sm:p-5">
                <h2 class="font-display font-semibold text-stone-900">Où</h2>
                <p>Les données sont hébergées {{ $hosting }}.</p>
            </section>

            <section class="card space-y-2 p-4 text-sm text-stone-700 sm:p-5">
                <h2 class="font-display font-semibold text-stone-900">Qui y a accès</h2>
                <ul class="list-disc space-y-1 pl-5">
                    <li><strong>Les membres de votre foyer</strong>, selon leur rôle : les responsables et les comptes complets voient et modifient tout ; les comptes « consultation et courses » voient et cochent les courses.</li>
                    <li><strong>L'administrateur</strong> ({{ $admins->join(', ', ' et ') ?: '—' }}) : son écran d'administration n'affiche que des nombres (foyers, membres, recettes, place occupée), jamais le contenu. Comme pour toute application hébergée, il a techniquement accès au serveur et aux sauvegardes.</li>
                    <li><strong>Les foyers reliés au vôtre</strong> (Proches), seulement ce que votre foyer leur ouvre : les recettes marquées partagées, le planning s'il est ouvert, les repas où vous les invitez, les surplus proposés. Vos contraintes alimentaires, seulement si vous l'avez accepté dans « Mon compte ».</li>
                    <li>Personne d'autre : pas de partage avec des entreprises, pas de statistiques de fréquentation.</li>
                </ul>
            </section>
        </div>

        <section class="card space-y-3 p-4 text-sm text-stone-700 sm:p-5 lg:col-span-2">
            <h2 class="font-display font-semibold text-stone-900">Services extérieurs appelés</h2>
            <p>Bouffe n'envoie rien à l'extérieur de lui-même, sauf dans ces cas, et seulement ce qui est indiqué :</p>
            <ul class="grid gap-3 md:grid-cols-2">
                <li class="rounded-lg bg-stone-50 p-3">
                    <p class="font-medium text-stone-900">Lecture des tickets de caisse</p>
                    @if ($ocrLabel)
                        <p>Quand vous photographiez un ticket (ou une recette), l'image recadrée est envoyée à <strong>{{ $ocrLabel }}</strong>{{ $ocrProvider === 'mistral' ? ' (entreprise française)' : '' }} pour être lue. Rien d'autre du foyer n'est envoyé.</p>
                    @else
                        <p>Désactivée sur cette installation : les tickets se saisissent à la main, rien n'est envoyé.</p>
                    @endif
                </li>
                <li class="rounded-lg bg-stone-50 p-3">
                    <p class="font-medium text-stone-900">Notifications sur le téléphone</p>
                    <p>Si vous les activez, le texte du rappel passe, chiffré, par le service de notification de votre navigateur (Google, Mozilla ou Apple), qui ne peut pas le lire.</p>
                </li>
                <li class="rounded-lg bg-stone-50 p-3">
                    <p class="font-medium text-stone-900">E-mails</p>
                    @if ($mailHost)
                        <p>Récapitulatifs, alertes de connexion et liens de mot de passe partent par le serveur d'envoi {{ $mailHost }}.</p>
                    @else
                        <p>Aucun serveur d'envoi n'est réglé sur cette installation : aucun e-mail ne part.</p>
                    @endif
                </li>
                <li class="rounded-lg bg-stone-50 p-3">
                    <p class="font-medium text-stone-900">Code-barres et import de recettes</p>
                    <p>Un code-barres scanné est cherché dans la base libre Open Food Facts ; une recette importée est lue sur le site dont vous donnez l'adresse. Seuls le code ou l'adresse sont transmis.</p>
                </li>
            </ul>
        </section>

        <section class="card space-y-3 p-4 text-sm text-stone-700 sm:p-5 lg:col-span-2">
            <h2 class="font-display font-semibold text-stone-900">Reprendre ou effacer vos données</h2>
            <ul class="grid gap-3 md:grid-cols-3">
                <li>
                    <p class="font-medium text-stone-900">Exporter</p>
                    <p>Les responsables d'un foyer téléchargent tout le foyer (fichier JSON et photos) depuis Paramètres → Foyer.</p>
                    @if ($owner) <a href="{{ route('settings.export') }}" class="mt-1 inline-flex items-center gap-1 font-medium text-brand-700 hover:underline"><x-icon name="download" class="size-4" /> Exporter le foyer</a> @endif
                </li>
                <li>
                    <p class="font-medium text-stone-900">Quitter un foyer</p>
                    <p>Depuis Paramètres → Foyer. Un foyer supprimé par ses responsables disparaît définitivement 30 jours plus tard.</p>
                </li>
                <li>
                    <p class="font-medium text-stone-900">Supprimer votre compte</p>
                    <p>Depuis « Mon compte ». Vos saisies restent au foyer ; vos données personnelles sont effacées, puis disparaissent des sauvegardes à mesure qu'elles expirent (environ {{ $backupDays }} jours).</p>
                    @unless ($guest) <a href="{{ route('account.show') }}" wire:navigate class="mt-1 inline-flex items-center gap-1 font-medium text-brand-700 hover:underline"><x-icon name="user" class="size-4" /> Mon compte</a> @endunless
                </li>
            </ul>
            <p class="text-stone-500">Une question, une demande ? Adressez-vous à {{ $admins->join(', ', ' ou ') ?: 'l\'administrateur' }}.</p>
        </section>
    </div>

    @if ($guest)
        <p class="text-center text-sm"><a href="{{ route('login') }}" class="font-medium text-brand-700 hover:underline">← Connexion</a></p>
    @endif
</div>
