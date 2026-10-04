<div>
    <x-page-header title="Mon compte" subtitle="Profil, mot de passe, double authentification et appareils connectés." />
    <x-settings-nav />

    @if ($required && ! $user->hasTwoFactor())
        <div class="mb-6 flex gap-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200" role="alert">
            <x-icon name="lock" class="size-5 shrink-0" />
            <p>
                <strong>Double authentification à activer.</strong>
                {{ $user->isAdmin() ? 'Vous administrez l\'installation' : 'Vous êtes responsable d\'un foyer' }} : un code du téléphone est demandé
                en plus du mot de passe. Le reste de l'application s'ouvrira une fois ce réglage fait (deux minutes).
            </p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- ============================================================ Profil --}}
        <form wire:submit="saveProfile" class="card space-y-4 p-4 sm:p-5">
            <h2 class="font-display font-semibold text-stone-900">Profil</h2>
            <x-field label="Prénom" for="a-name" error="name">
                <input id="a-name" type="text" wire:model="name" maxlength="100" autocomplete="given-name" class="form-input">
            </x-field>
            <x-field label="Adresse e-mail" for="a-email" error="email" help="Sert à se connecter et à recevoir les e-mails de Bouffe.">
                <input id="a-email" type="email" wire:model.live.blur="email" maxlength="191" autocomplete="email" class="form-input">
            </x-field>
            @if (mb_strtolower(trim($email)) !== mb_strtolower($user->email))
                <x-field label="Mot de passe actuel" for="a-profile-password" error="profilePassword" help="Demandé pour changer d'adresse.">
                    <input id="a-profile-password" type="password" wire:model="profilePassword" autocomplete="current-password" class="form-input">
                </x-field>
            @endif
            <div class="flex justify-end"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
        </form>

        {{-- ============================================================ Mot de passe --}}
        <form wire:submit="changePassword" class="card space-y-4 p-4 sm:p-5">
            <h2 class="font-display font-semibold text-stone-900">Mot de passe</h2>
            <x-field label="Mot de passe actuel" for="a-current" error="currentPassword">
                <input id="a-current" type="password" wire:model="currentPassword" autocomplete="current-password" class="form-input">
            </x-field>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Nouveau" for="a-new" error="newPassword" help="8 caractères au moins.">
                    <input id="a-new" type="password" wire:model="newPassword" autocomplete="new-password" class="form-input">
                </x-field>
                <x-field label="Une seconde fois" for="a-new2">
                    <input id="a-new2" type="password" wire:model="newPassword_confirmation" autocomplete="new-password" class="form-input">
                </x-field>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-stone-500">Les autres appareils seront déconnectés.</p>
                <button type="submit" class="btn btn-primary">Changer</button>
            </div>
        </form>

        {{-- ============================================================ Double authentification (27.4) --}}
        <section id="double-authentification" class="card space-y-4 p-4 sm:p-5 lg:col-span-2">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-display font-semibold text-stone-900">Double authentification</h2>
                    <p class="mt-1 text-sm text-stone-600">
                        À la connexion, un code à 6 chiffres affiché par une application du téléphone (Google Authenticator, Aegis,
                        Microsoft Authenticator…) est demandé en plus du mot de passe.
                    </p>
                </div>
                @if ($user->hasTwoFactor())
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-800 ring-1 ring-emerald-200"><x-icon name="check" class="size-4" /> Activée</span>
                @elseif ($required)
                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 ring-1 ring-amber-200"><x-icon name="warning" class="size-4" /> Obligatoire pour vous</span>
                @else
                    <span class="inline-flex items-center gap-1 rounded-full bg-stone-100 px-2.5 py-1 text-xs font-medium text-stone-700"><x-icon name="lock-open" class="size-4" /> Désactivée (facultative)</span>
                @endif
            </div>

            @if ($recoveryCodes)
                <div class="rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200">
                    <h3 class="flex items-center gap-2 font-semibold text-amber-900"><x-icon name="warning" class="size-5" /> Codes de secours — notez-les maintenant</h3>
                    <p class="mt-1 text-sm text-amber-900">Ils remplacent le téléphone s'il est perdu : chacun sert une fois. Ils ne seront plus affichés.</p>
                    <ul class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm sm:grid-cols-4">
                        @foreach ($recoveryCodes as $code)
                            <li class="rounded-lg bg-white px-2 py-1.5 text-center text-stone-900 ring-1 ring-amber-200">{{ $code }}</li>
                        @endforeach
                    </ul>
                    <div class="mt-3 flex flex-wrap gap-2" x-data="{ copied: false }">
                        <button type="button" class="btn btn-secondary" x-on:click="navigator.clipboard?.writeText(@js(implode("\n", $recoveryCodes))).then(() => copied = true)">
                            <x-icon name="duplicate" class="size-4" /> <span x-text="copied ? 'Copiés' : 'Copier'">Copier</span>
                        </button>
                        <button type="button" wire:click="hideRecoveryCodes" class="btn btn-primary">C'est noté</button>
                    </div>
                </div>
            @endif

            @if ($user->hasTwoFactor())
                <div wire:key="two-factor-active" class="grid gap-4 md:grid-cols-2">
                    <div class="space-y-2 text-sm text-stone-600">
                        <p>Activée le {{ $user->two_factor_confirmed_at->locale('fr')->isoFormat('D MMMM YYYY') }}.</p>
                        <p @class(['font-medium text-amber-800' => $remaining <= 2])>
                            {{ $remaining }} code{{ $remaining > 1 ? 's' : '' }} de secours restant{{ $remaining > 1 ? 's' : '' }}.
                        </p>
                        @if ($trusted)
                            <p class="flex flex-wrap items-center gap-2">
                                Cet appareil est de confiance : pas de code ici pendant 30 jours.
                                <button type="button" wire:click="forgetThisDevice" class="font-medium text-brand-700 hover:underline">Redemander le code</button>
                            </p>
                        @endif
                    </div>
                    <div class="space-y-3">
                        <x-field label="Mot de passe (pour confirmer)" for="a-confirm-2fa" error="confirmPassword">
                            <input id="a-confirm-2fa" type="password" wire:model="confirmPassword" autocomplete="current-password" class="form-input">
                        </x-field>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="regenerateRecoveryCodes" class="btn btn-secondary">Nouveaux codes de secours</button>
                            @unless ($required)
                                <button type="button" wire:click="disableTwoFactor" wire:confirm="Désactiver la double authentification ?" class="btn btn-ghost text-red-700">Désactiver</button>
                            @endunless
                        </div>
                    </div>
                </div>
            @elseif ($pending)
                <div wire:key="two-factor-setup" class="grid items-start gap-6 md:grid-cols-[auto_1fr]">
                    <div class="mx-auto rounded-xl bg-white p-2 ring-1 ring-stone-200" wire:ignore
                         x-data x-init="window.bouffeQrCode($refs.qr, @js($otpUri))">
                        <canvas x-ref="qr" width="192" height="192" class="size-48" aria-label="QR code à scanner avec l'application d'authentification"></canvas>
                    </div>
                    <form wire:submit="confirmTwoFactor" class="space-y-4">
                        <ol class="list-decimal space-y-1 pl-5 text-sm text-stone-600">
                            <li>Dans l'application, ajoutez un compte et scannez ce QR code.</li>
                            <li>Sans appareil photo : saisissez la clé <code class="rounded bg-stone-100 px-1 font-mono text-stone-900 break-all">{{ $secret }}</code></li>
                            <li>Recopiez ci-dessous le code à 6 chiffres affiché.</li>
                        </ol>
                        <x-field label="Code affiché" for="a-setup-code" error="setupCode">
                            <input id="a-setup-code" type="text" wire:model="setupCode" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123456" class="form-input max-w-40 text-center text-lg tracking-widest tabular-nums">
                        </x-field>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary">Activer</button>
                            <button type="button" wire:click="cancelTwoFactorSetup" class="btn btn-ghost">Annuler</button>
                        </div>
                    </form>
                </div>
            @else
                <button type="button" wire:key="two-factor-start" wire:click="startTwoFactor" class="btn btn-primary"><x-icon name="lock" class="size-4" /> Activer la double authentification</button>
            @endif
        </section>

        {{-- ============================================================ Proches et agenda (lot 26 : 26.6, C4) --}}
        <section class="card space-y-3 p-4 sm:p-5">
            <h2 class="font-display font-semibold text-stone-900">Contraintes alimentaires</h2>
            <p class="text-sm text-stone-600">Quand un foyer relié vous invite à un repas, il peut voir vos allergies et régimes (notés dans Paramètres → Foyer) pour cuisiner en conséquence.</p>
            <label class="flex items-start gap-2 text-sm text-stone-800">
                <input type="checkbox" wire:click="toggleShareRestrictions" @checked($user->share_restrictions) class="form-checkbox mt-0.5">
                <span>Partager mes contraintes avec les foyers reliés qui m'invitent</span>
            </label>
        </section>

        <section class="card space-y-3 p-4 sm:p-5">
            <h2 class="font-display font-semibold text-stone-900">Agenda du téléphone</h2>
            <p class="text-sm text-stone-600">Les repas du planning dans l'application Agenda (iPhone, Android, Outlook) : abonnez-vous à cette adresse personnelle. Elle se met à jour d'elle-même.</p>
            @if ($calendarUrl)
                <div class="flex flex-wrap gap-2" x-data="{ copied: false }">
                    <input type="text" readonly value="{{ $calendarUrl }}" class="form-input min-w-0 flex-1 basis-64 font-mono text-xs" x-on:focus="$el.select()" aria-label="Adresse de l'agenda">
                    <button type="button" class="btn btn-secondary" x-on:click="navigator.clipboard?.writeText(@js($calendarUrl)); copied = true"><span x-text="copied ? 'Copiée' : 'Copier'">Copier</span></button>
                    <a href="{{ str_replace(['https://', 'http://'], 'webcal://', $calendarUrl) }}" class="btn btn-ghost"><x-icon name="calendar" class="size-4" /> S'abonner</a>
                </div>
                <p class="text-xs text-stone-500">Gardez-la pour vous : elle donne accès au planning. <button type="button" wire:click="regenerateCalendar" wire:confirm="L'adresse actuelle cessera de fonctionner. Continuer ?" class="underline hover:text-red-700">Changer d'adresse</button></p>
            @else
                <button type="button" wire:click="$set('showCalendar', true)" class="btn btn-secondary"><x-icon name="calendar" class="size-4" /> Afficher l'adresse</button>
            @endif
        </section>

        {{-- Lot 38 (38.1) : Siri et l'application Raccourcis. --}}
        <section class="card space-y-3 p-4 sm:p-5">
            <h2 class="font-display font-semibold text-stone-900">Raccourcis et Siri</h2>
            <p class="text-sm text-stone-600">« Dis Siri, ajoute aux courses… », « On mange quoi ? », envoyer une recette depuis Safari : un jeton personnel et le mode d'emploi.</p>
            <a href="{{ route('account.shortcuts') }}" wire:navigate class="btn btn-secondary"><x-icon name="phone" class="size-4" /> Raccourcis</a>
        </section>

        {{-- ============================================================ Appareils connectés (27.5) --}}
        <section id="appareils" class="card p-4 sm:p-5">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-display font-semibold text-stone-900">Appareils connectés</h2>
                @if ($sessionsAvailable && count($this->sessions) > 1)
                    <button type="button" wire:click="revokeOtherSessions" wire:confirm="Déconnecter tous les autres appareils ?" class="btn btn-secondary py-1 text-sm">Déconnecter les autres</button>
                @endif
            </div>
            @if (! $sessionsAvailable)
                <p class="text-sm text-stone-500">Liste indisponible : les sessions ne sont pas enregistrées en base (SESSION_DRIVER=database dans .env).</p>
            @else
                <ul class="divide-y divide-stone-100">
                    @foreach ($this->sessions as $session)
                        <li wire:key="s-{{ $session['id'] }}" class="flex items-center gap-3 py-2.5">
                            <x-icon :name="$session['icon']" class="size-5 shrink-0 text-stone-400" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-stone-900">{{ $session['label'] }}</p>
                                <p class="text-xs text-stone-500">
                                    @if ($session['current'])
                                        <span class="font-medium text-emerald-700">Cet appareil</span> ·
                                    @endif
                                    {{ $session['ip'] }} · {{ $session['last_active']->locale('fr')->diffForHumans() }}
                                </p>
                            </div>
                            @unless ($session['current'])
                                <button type="button" wire:click="revokeSession(@js($session['id']))" class="btn btn-ghost px-2 py-1 text-sm" title="Déconnecter cet appareil">
                                    <x-icon name="logout" class="size-4" /> <span class="sr-only sm:not-sr-only">Déconnecter</span>
                                </button>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- ============================================================ Journal (27.5) --}}
        <section class="card p-4 sm:p-5">
            <h2 class="font-display mb-3 font-semibold text-stone-900">Dernières connexions</h2>
            @if ($events->isEmpty())
                <p class="text-sm text-stone-500">Rien pour l'instant : le journal commence avec cette version.</p>
            @else
                <ul class="max-h-80 divide-y divide-stone-100 overflow-y-auto">
                    @foreach ($events as $event)
                        <li class="flex items-start gap-3 py-2">
                            <span @class(['mt-0.5 shrink-0', 'text-emerald-700' => $event->severity() === 'ok', 'text-amber-700' => $event->severity() === 'warn', 'text-red-700' => $event->severity() === 'error'])>
                                <x-icon :name="$event->icon()" class="size-4" />
                            </span>
                            <div class="min-w-0 flex-1 text-sm">
                                <p class="text-stone-900">{{ $event->label() }}</p>
                                <p class="text-xs text-stone-500">{{ $event->created_at->locale('fr')->isoFormat('D MMM YYYY, HH:mm') }} · {{ $event->deviceLabel() }}{{ $event->ip_address ? ' · '.$event->ip_address : '' }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-stone-500">Gardé {{ config('bouffe.security.journal_days') }} jours. Une ligne que vous ne reconnaissez pas ? Changez de mot de passe.</p>
            @endif
        </section>

        {{-- ============================================================ Vos données et suppression (27.12) --}}
        <section class="card space-y-3 p-4 sm:p-5 lg:col-span-2">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-display font-semibold text-stone-900">Supprimer mon compte</h2>
                    <p class="mt-1 text-sm text-stone-600">
                        Ce que vous avez saisi dans un foyer (recettes, dépenses…) reste au foyer. Vos contraintes alimentaires, réactions,
                        notifications et votre journal de connexions sont effacés.
                    </p>
                </div>
                <a href="{{ route('privacy') }}" wire:navigate class="btn btn-secondary"><x-icon name="info" class="size-4" /> Vos données</a>
            </div>
            @if ($blockers)
                <ul class="space-y-1 text-sm text-stone-600">
                    @foreach ($blockers as $blocker)
                        <li class="flex gap-2"><x-icon name="info" class="mt-0.5 size-4 shrink-0 text-stone-400" /> {{ $blocker }}</li>
                    @endforeach
                </ul>
            @else
                <div x-data="{ open: false }">
                    <button type="button" x-show="! open" x-on:click="open = true" class="btn btn-ghost text-red-700"><x-icon name="delete" class="size-4" /> Supprimer mon compte…</button>
                    <form x-show="open" x-cloak wire:submit="deleteAccount" class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                        <div class="space-y-3">
                            <x-field label="Mot de passe" for="a-confirm-delete" error="deletePassword">
                                <input id="a-confirm-delete" type="password" wire:model="deletePassword" autocomplete="current-password" class="form-input">
                            </x-field>
                            <label class="flex items-start gap-2 text-sm text-stone-700">
                                <input type="checkbox" wire:model="confirmDeletion" class="form-checkbox mt-0.5">
                                <span>Je comprends que la suppression est définitive.</span>
                            </label>
                            @error('confirmDeletion') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn-danger">Supprimer définitivement</button>
                    </form>
                </div>
            @endif
        </section>
    </div>
</div>
