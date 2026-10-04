<div>
    <x-page-header title="Paramètres" subtitle="Les listes de référence utilisées par les recettes et la liste de courses." />
    <x-settings-nav />

    <div class="grid gap-6 lg:grid-cols-5">
        {{-- Adresses + QR codes --}}
        <section class="card min-w-0 space-y-4 p-5 lg:col-span-2">
            <div>
                <h2 class="font-display font-semibold text-stone-900">Ouvrir Bouffe sur un téléphone</h2>
                <p class="text-sm text-stone-500">Le téléphone doit être connecté au même Wi-Fi que ce PC, et Wamp démarré.</p>
            </div>

            @if ($fromLan)
                <div class="flex gap-3 rounded-xl bg-herb-50 p-3 text-sm text-herb-800">
                    <x-icon name="success" class="size-5 shrink-0" />
                    <p>Cet appareil utilise déjà Bouffe par le réseau local ({{ $clientIp }}) : l'accès téléphone fonctionne.</p>
                </div>
            @endif

            @forelse ($urls as $i => $url)
                <div wire:key="url-{{ $i }}" x-data="{ copied: false }" class="rounded-xl border border-stone-200 p-4 text-center">
                    @if ($i === 0)
                        <canvas x-init="window.bouffeQrCode($el, @js($url))" class="mx-auto size-48" aria-label="QR code vers {{ $url }}"></canvas>
                        <p class="mt-1 text-xs text-stone-500">Scanner avec l'appareil photo du téléphone</p>
                    @endif
                    <p class="mt-2 font-mono text-lg font-semibold break-all text-stone-900">{{ $url }}</p>
                    <button type="button" class="btn btn-ghost mt-1 px-2 py-1 text-sm"
                            x-on:click="navigator.clipboard?.writeText(@js($url)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                        <span x-show="! copied">Copier l'adresse</span><span x-show="copied" x-cloak>Copiée ✓</span>
                    </button>
                    @if ($i === 0 && count($urls) > 1)
                        <p class="text-xs text-stone-500">Adresse la plus probable. Autres adresses de ce PC ci-dessous.</p>
                    @endif
                </div>
            @empty
                <div class="flex gap-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                    <x-icon name="warning" class="size-5 shrink-0" />
                    <p>Adresse réseau non détectée. Dans un terminal Windows, tapez <code class="rounded bg-white px-1">ipconfig</code> et relevez l'« Adresse IPv4 » de la carte Wi-Fi ou Ethernet (ex. 192.168.1.20).</p>
                </div>
            @endforelse

            <div class="rounded-xl bg-stone-50 p-4 text-sm text-stone-600">
                <h3 class="mb-2 font-semibold text-stone-900">Comme une application</h3>
                <ul class="space-y-1.5">
                    <li><strong>iPhone</strong> (Safari) : bouton Partager → « Sur l'écran d'accueil ».</li>
                    <li><strong>Android</strong> (Chrome) : menu ⋮ → « Ajouter à l'écran d'accueil ».</li>
                </ul>
                <p class="mt-2 text-xs text-stone-500">Connectez-vous une fois avec « Rester connecté » coché : la session est gardée.</p>
            </div>
        </section>

        {{-- Configuration --}}
        <section class="card min-w-0 space-y-5 p-5 text-sm text-stone-600 lg:col-span-3">
            <div>
                <h2 class="font-display font-semibold text-stone-900">Configuration à faire une fois sur le PC</h2>
                <p class="text-stone-500">Par défaut, Wamp n'accepte que les connexions venant du PC lui-même.</p>
            </div>

            <ol class="space-y-5">
                <li class="flex gap-3">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">1</span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <p><strong class="text-stone-900">Autoriser le réseau local dans le VirtualHost.</strong> Clic gauche sur Wamp → Apache → <code class="rounded bg-stone-100 px-1">httpd-vhosts.conf</code>, puis compléter le bloc <code class="rounded bg-stone-100 px-1">{{ $hostName }}</code> (lignes <code>ServerAlias</code> et <code>Require ip</code>) :</p>
                        <div x-data="{ copied: false }" class="relative">
                            <pre x-ref="vhost" class="overflow-x-auto rounded-lg bg-stone-900 px-3 py-2 text-xs leading-relaxed text-stone-100">&lt;VirtualHost *:80&gt;
    ServerName {{ $hostName }}
    <span class="text-herb-300">ServerAlias {{ $exampleIp }}</span>
    DocumentRoot "{{ $publicPath }}"
    &lt;Directory "{{ $publicPath }}/"&gt;
        Options +Indexes +Includes +FollowSymLinks +MultiViews
        AllowOverride All
        Require local
        <span class="text-herb-300">Require ip {{ $ipPrefix }}</span>
    &lt;/Directory&gt;
&lt;/VirtualHost&gt;</pre>
                            <button type="button" class="btn absolute top-1.5 right-1.5 bg-white/10 px-2 py-1 text-xs text-white hover:bg-white/20"
                                    x-on:click="navigator.clipboard?.writeText($refs.vhost.innerText).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                                <span x-show="! copied">Copier</span><span x-show="copied" x-cloak>Copié ✓</span>
                            </button>
                        </div>
                        <p class="text-xs text-stone-500"><code>Require ip {{ $ipPrefix }}</code> autorise tous les appareils en {{ $ipPrefix }}.x, c'est-à-dire ceux de la maison ; les autres restent refusés.</p>
                    </div>
                </li>

                <li class="flex gap-3">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">2</span>
                    <div class="space-y-1">
                        <p><strong class="text-stone-900">Ouvrir le pare-feu Windows.</strong> Démarrer → « Autoriser une application via le Pare-feu Windows » → Modifier les paramètres → cocher <strong>Apache HTTP Server</strong> dans la colonne <strong>Privé</strong>.</p>
                        <p class="text-xs text-stone-500">Le Wi-Fi de la maison doit être déclaré en réseau <strong>privé</strong> : Paramètres → Réseau et Internet → Wi-Fi → propriétés du réseau.</p>
                    </div>
                </li>

                <li class="flex gap-3">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">3</span>
                    <p><strong class="text-stone-900">Redémarrer Wamp</strong> (clic gauche → Redémarrer les services), puis ouvrir l'adresse sur le téléphone.</p>
                </li>

                <li class="flex gap-3">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">4</span>
                    <p><strong class="text-stone-900">Garder la même adresse.</strong> La box Internet peut donner une autre adresse au PC après un redémarrage. Dans l'interface de la box, réservez l'adresse {{ $exampleIp }} pour ce PC (« bail statique » ou « réservation DHCP »).</p>
                </li>
            </ol>

            <div class="flex gap-3 rounded-xl bg-stone-50 p-3 text-xs text-stone-500">
                <x-icon name="info" class="size-5 shrink-0" />
                <p>En magasin, sans le Wi-Fi de la maison, l'application n'est pas joignable : ouvrez la liste de courses avant de partir (elle reste affichée), ou utilisez « Copier le texte » / « Imprimer ».</p>
            </div>
        </section>
    </div>
</div>
