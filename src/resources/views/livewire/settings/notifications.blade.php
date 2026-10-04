<div>
    <x-page-header title="Paramètres" subtitle="Les listes de référence utilisées par les recettes et la liste de courses." />
    <x-settings-nav />

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- ============================================================ Cet appareil (19.2) --}}
        <section class="card space-y-4 p-5" x-data="bouffePush({ key: '{{ route('push.key') }}', subscribe: '{{ route('push.subscribe') }}', unsubscribe: '{{ route('push.unsubscribe') }}' })" x-init="init()">
            <div>
                <h2 class="font-display font-semibold text-stone-900">Sur cet appareil</h2>
                <p class="text-sm text-stone-500">Les notifications arrivent même quand Bouffe est fermé. À activer sur chaque téléphone ou ordinateur.</p>
            </div>

            <div class="rounded-lg px-3 py-2 text-sm ring-1"
                 x-bind:class="subscribed ? 'bg-herb-50 text-herb-900 ring-herb-100' : (blocked ? 'bg-amber-50 text-amber-900 ring-amber-200' : 'bg-stone-50 text-stone-700 ring-stone-200')">
                <p class="font-medium" x-text="status">Vérification…</p>
                <p class="mt-0.5 text-xs" x-show="hint" x-text="hint"></p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" x-show="canEnable && !subscribed" x-on:click="enable()" x-bind:disabled="busy">
                    <x-icon name="bell" class="size-4" /> Activer sur cet appareil
                </button>
                <button type="button" class="btn btn-secondary" x-show="subscribed" x-on:click="disable()" x-bind:disabled="busy" x-cloak>
                    Désactiver sur cet appareil
                </button>
                <button type="button" class="btn btn-ghost" wire:click="test" x-show="subscribed" x-cloak>
                    Envoyer un essai
                </button>
            </div>

            @if ($this->devices->isNotEmpty())
                <div class="border-t border-stone-200 pt-3">
                    <h3 class="mb-1 text-sm font-medium text-stone-700">Vos appareils abonnés</h3>
                    <ul class="divide-y divide-stone-100 text-sm">
                        @foreach ($this->devices as $device)
                            <li wire:key="device-{{ $device->id }}" class="flex items-center gap-2 py-1.5">
                                <x-icon name="phone" class="size-4 text-stone-400" />
                                <span class="min-w-0 flex-1 truncate text-stone-700">{{ $device->device ?? 'Appareil' }}</span>
                                <span class="text-xs text-stone-500">{{ $device->last_success_at ? 'reçu '.$device->last_success_at->locale('fr')->diffForHumans() : 'abonné '.$device->created_at->locale('fr')->diffForHumans() }}</span>
                                <button type="button" wire:click="forget({{ $device->id }})" class="btn btn-ghost px-1.5 hover:text-red-600" title="Oublier cet appareil">
                                    <x-icon name="close" class="size-4" /><span class="sr-only">Oublier</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        {{-- ============================================================ Ce que je reçois --}}
        <section class="card space-y-4 p-5">
            <div>
                <h2 class="font-display font-semibold text-stone-900">Ce que je reçois</h2>
                <p class="text-sm text-stone-500">Propre à votre compte. Tout reste visible dans la cloche de l'application.</p>
            </div>

            {{-- Lot 37 (37.1, R38) : le rendez-vous du soir. --}}
            <form wire:submit="saveEvening" id="ce-soir" class="space-y-3 rounded-xl bg-brand-50/60 p-3 ring-1 ring-brand-100">
                <label class="flex cursor-pointer items-start gap-3">
                    <input type="checkbox" wire:model="evening" class="form-checkbox mt-0.5">
                    <span>
                        <span class="block text-sm font-medium text-stone-800">Le rendez-vous du soir</span>
                        <span class="block text-xs text-stone-500">
                            Une seule notification par jour : « c'était mangé ? », ce qu'il faut préparer pour demain et les gamelles.
                            Elle ne part pas s'il n'y a rien à faire. Les rappels « à préparer » du soir y sont regroupés.
                            <a href="{{ route('evening') }}" wire:navigate class="font-medium text-brand-700 hover:underline">Voir la page</a>
                        </span>
                    </span>
                </label>
                <div class="flex flex-wrap items-end gap-3 pl-7">
                    <x-field label="À" for="evening-at" error="eveningAt" class="w-32">
                        <select id="evening-at" wire:model="eveningAt" class="form-input">
                            @for ($m = 17 * 60; $m <= 23 * 60 + 30; $m += 30)
                                @php $value = sprintf('%02d:%02d', intdiv($m, 60), $m % 60); @endphp
                                <option value="{{ $value }}">{{ str_replace(':', ' h ', $value) }}</option>
                            @endfor
                        </select>
                    </x-field>
                    <button type="submit" class="btn btn-secondary">Enregistrer</button>
                </div>
                @if ($evening && $eveningQuiet)
                    <p class="pl-7 text-xs text-amber-800">Cette heure tombe dans vos heures calmes : le rendez-vous ne partira pas.</p>
                @endif
            </form>

            <ul class="space-y-3">
                @foreach (\App\Services\Notifications\NotificationDispatcher::TYPES as $type => [$label, $help])
                    <li>
                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" wire:model.live="types.{{ $type }}" class="form-checkbox mt-0.5">
                            <span>
                                <span class="block text-sm font-medium text-stone-800">{{ $label }}</span>
                                <span class="block text-xs text-stone-500">{{ $help }}</span>
                            </span>
                        </label>
                    </li>
                @endforeach
            </ul>

            {{-- Lot 30 (30.6) : ne pas déranger --}}
            <form wire:submit="saveQuiet" class="space-y-3 border-t border-stone-200 pt-4">
                <label class="flex cursor-pointer items-start gap-3">
                    <input type="checkbox" wire:model="quiet" class="form-checkbox mt-0.5">
                    <span>
                        <span class="block text-sm font-medium text-stone-800">Ne pas déranger la nuit</span>
                        <span class="block text-xs text-stone-500">Rien sur votre téléphone pendant ces heures ; ce qui tombe pendant attend le matin.</span>
                    </span>
                </label>
                <div class="flex flex-wrap items-end gap-3 pl-7">
                    <x-field label="De" for="quiet-from" error="quietFrom" class="w-28">
                        <select id="quiet-from" wire:model="quietFrom" class="form-input">
                            @for ($h = 0; $h < 24; $h++) <option value="{{ $h }}">{{ $h }} h</option> @endfor
                        </select>
                    </x-field>
                    <x-field label="À" for="quiet-until" error="quietUntil" class="w-28">
                        <select id="quiet-until" wire:model="quietUntil" class="form-input">
                            @for ($h = 0; $h < 24; $h++) <option value="{{ $h }}">{{ $h }} h</option> @endfor
                        </select>
                    </x-field>
                    <button type="submit" class="btn btn-secondary">Enregistrer</button>
                </div>
            </form>

            <label class="flex cursor-pointer items-start gap-3 border-t border-stone-200 pt-4">
                <input type="checkbox" wire:model.live="recap" class="form-checkbox mt-0.5">
                <span>
                    <span class="block text-sm font-medium text-stone-800">Récapitulatif de la semaine par e-mail</span>
                    <span class="block text-xs text-stone-500">Menu, liste de courses et produits à consommer, envoyés à {{ auth()->user()->email }}.</span>
                </span>
            </label>
        </section>

        {{-- ============================================================ Réglages communs --}}
        @if ($canEdit)
            <form wire:submit="saveShared" class="card space-y-4 p-5">
                <div>
                    <h2 class="font-display font-semibold text-stone-900">Réglages communs</h2>
                    <p class="text-sm text-stone-500">Pour toute la maison. La nuit, de {{ config('bouffe.notifications.quiet_from') }} h à {{ config('bouffe.notifications.quiet_until') }} h, rien n'est envoyé par défaut ; chacun peut régler ses propres heures ci-dessus.</p>
                </div>

                <x-field label="Heure du message « produits à consommer »" for="daily-hour" error="dailyHour">
                    <select id="daily-hour" wire:model="dailyHour" class="form-input w-auto">
                        @foreach (range(6, 21) as $hour) <option value="{{ $hour }}">{{ $hour }} h</option> @endforeach
                    </select>
                </x-field>
                <p class="-mt-2 text-xs text-stone-500">Les rappels « la veille » partent à l'heure réglée dans Paramètres → Planning ({{ \App\Support\Settings::int('planning.reminder_hour', 18) }} h).</p>

                <div class="border-t border-stone-200 pt-4">
                    <label class="flex items-center gap-2 text-sm font-medium text-stone-800">
                        <input type="checkbox" wire:model.live="recapEnabled" class="form-checkbox"> Envoyer le récapitulatif par e-mail
                    </label>
                    @if ($recapEnabled)
                        <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-stone-700">
                            <span>Le</span>
                            <select wire:model="recapDay" class="form-input w-auto py-1.5" aria-label="Jour">
                                @foreach ($days as $i => $day) <option value="{{ $i }}">{{ $day }}</option> @endforeach
                            </select>
                            <span>à</span>
                            <select wire:model="recapHour" class="form-input w-auto py-1.5" aria-label="Heure">
                                @foreach (range(6, 22) as $hour) <option value="{{ $hour }}">{{ $hour }} h</option> @endforeach
                            </select>
                        </div>
                        <p class="mt-2 text-xs {{ $mailer === 'log' ? 'text-amber-700' : 'text-stone-500' }}">
                            @if ($mailer === 'log')
                                Aucun serveur d'envoi n'est configuré (MAIL_MAILER=log dans .env) : les messages sont écrits dans storage/logs au lieu d'être envoyés.
                            @else
                                Envoi par « {{ $mailer }} » (réglages MAIL_* du fichier .env).
                            @endif
                        </p>
                        <button type="button" wire:click="testRecap" class="btn btn-ghost mt-2 px-0 text-sm text-brand-700">
                            <x-icon name="envelope" class="size-4" /> M'envoyer un essai maintenant
                        </button>
                    @endif
                </div>

                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </form>

            {{-- ============================================================ Tâche planifiée (19.4) --}}
            <section class="card space-y-3 p-5">
                <h2 class="font-display font-semibold text-stone-900">Tâche planifiée</h2>
                <p class="text-sm text-stone-600">
                    Les notifications et le récapitulatif partent grâce à la commande <code class="rounded bg-stone-100 px-1">php artisan bouffe:reminders</code>,
                    lancée automatiquement toutes les 10 minutes.
                </p>
                <p @class(['rounded-lg px-3 py-2 text-sm ring-1', 'bg-amber-50 text-amber-900 ring-amber-200' => $lastRunLate, 'bg-herb-50 text-herb-900 ring-herb-100' => ! $lastRunLate])>
                    @if ($lastRun)
                        Dernier passage : {{ $lastRun->locale('fr')->diffForHumans() }} ({{ $lastRun->locale('fr')->isoFormat('ddd D MMM, HH[h]mm') }}).
                        @if ($lastRunLate) La tâche ne semble plus tourner. @endif
                    @else
                        La tâche n'a encore jamais tourné : les notifications ne partiront pas.
                    @endif
                </p>
                <p class="text-sm text-stone-600">
                    Sous Windows, pour la créer, ouvrez un terminal dans le dossier de Bouffe et tapez
                    <code class="rounded bg-stone-100 px-1">{{ $windowsCommand }}</code> : la commande à coller s'affiche.
                </p>
                @unless ($serverReady)
                    <p class="text-sm text-red-700">L'extension PHP « openssl » est incomplète : les notifications sur le téléphone ne peuvent pas être chiffrées.</p>
                @endunless
            </section>
        @endif
    </div>
</div>
