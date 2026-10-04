{{--
    Comportements réseau globaux (à inclure avant @livewireScripts) :
    - session expirée (419, téléphone resté en veille) : rechargement silencieux au lieu de la question en anglais ;
    - serveur injoignable (hors du Wi-Fi, PC éteint) : bandeau « Connexion perdue ».
--}}
<div x-data="{ lost: false }"
     x-on:bouffe-connection.window="lost = $event.detail.lost"
     x-show="lost" x-cloak x-transition.opacity
     class="fixed inset-x-0 top-0 z-[60] flex items-center justify-center gap-3 bg-amber-500 px-4 py-2 pt-[max(0.5rem,env(safe-area-inset-top))] text-sm font-medium text-white shadow print:hidden"
     role="alert">
    <x-icon name="warning" class="size-5 shrink-0" />
    <span>Connexion à Bouffe perdue : les dernières modifications ne sont peut-être pas enregistrées.</span>
    <button type="button" class="rounded-md bg-white/20 px-2 py-0.5 hover:bg-white/30" x-on:click="window.location.reload()">Réessayer</button>
</div>

<script @nonce>
    document.addEventListener('livewire:init', () => {
        const connection = (lost) => window.dispatchEvent(new CustomEvent('bouffe-connection', { detail: { lost } }));

        Livewire.interceptRequest(({ onError, onFailure, onSuccess }) => {
            onFailure(() => connection(true));
            onSuccess(() => connection(false));
            onError(({ response, preventDefault }) => {
                if (response.status === 419) {
                    preventDefault();
                    window.location.reload();
                }
            });
        });

        window.addEventListener('online', () => connection(false));
        window.addEventListener('offline', () => connection(true));
    });
</script>
