{{--
    Invitation à installer Bouffe sur le téléphone (20.2).

    Le navigateur ne propose l'installation que s'il le veut bien (HTTPS, visites répétées) :
    on se contente d'attraper sa proposition et de l'offrir au bon moment. « Non merci » est
    définitif, gardé sur l'appareil.
--}}
<div x-data="bouffeInstall()" x-show="visible" x-cloak x-transition
     class="fixed inset-x-3 bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-40 mx-auto max-w-md rounded-xl bg-stone-900 p-4 text-white shadow-xl md:bottom-4 print:hidden">
    <div class="flex items-start gap-3">
        <x-app-logo class="mt-0.5 size-6 shrink-0 text-brand-400" />
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold">Installer Bouffe sur cet appareil</p>
            <p class="mt-0.5 text-xs text-stone-300">
                Une icône sur l'écran d'accueil, et la liste de courses qui s'ouvre en magasin même sans réseau.
            </p>
            <div class="mt-3 flex gap-2">
                <button type="button" x-on:click="install()" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium">Installer</button>
                <button type="button" x-on:click="never()" class="rounded-lg px-3 py-1.5 text-sm text-stone-300">Non merci</button>
            </div>
        </div>
    </div>
</div>
