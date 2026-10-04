/*
 * Point d'entrée JavaScript de Bouffe.
 *
 * Livewire (et Alpine.js qu'il embarque) est chargé par la directive @livewireScripts du layout.
 * Le glisser-déposer utilise la directive native de Livewire 4 : wire:sort.
 *
 * Ajouter ici uniquement le JavaScript spécifique à l'application.
 */

/**
 * QR code (Paramètres → Accès téléphone). La bibliothèque n'est chargée que sur cette page.
 */
window.bouffeQrCode = async (canvas, text) => {
    const { default: QRCode } = await import('qrcode');
    await QRCode.toCanvas(canvas, text, { width: 192, margin: 1, color: { dark: '#1c1917', light: '#ffffff' } });
    canvas.style.width = '';
    canvas.style.height = '';
};

/**
 * Mode cuisine (lot 12).
 *
 * 1. Écran maintenu allumé : API Screen Wake Lock, disponible uniquement en HTTPS (ou sur localhost).
 *    Sur le Wi-Fi de la maison en http://, le navigateur la refuse : on le signale au lieu d'échouer.
 * 2. Minuteurs : plusieurs en parallèle, gardés dans le navigateur (localStorage) pour survivre
 *    à un changement d'étape ou à un rechargement de page.
 */
window.bouffeWakeLock = {
    sentinel: null,
    supported: 'wakeLock' in navigator && window.isSecureContext,

    async request() {
        if (!this.supported) return false;
        try {
            this.sentinel = await navigator.wakeLock.request('screen');
            this.sentinel.addEventListener('release', () => { this.sentinel = null; });
            return true;
        } catch (e) {
            return false;
        }
    },

    async release() {
        try { await this.sentinel?.release(); } catch (e) {}
        this.sentinel = null;
    },
};

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && document.querySelector('[data-cook-mode]') && !window.bouffeWakeLock.sentinel) {
        window.bouffeWakeLock.request();
    }
});

/**
 * Pastille de l'icône (lot 37, 37.1) : les pages qui connaissent le nombre de repas à clôturer
 * (accueil, « Ce soir ») le donnent par data-app-badge ; la pastille posée par une notification
 * suit ainsi ce qui a été fait. Sans prise en charge (navigateur, iOS < 16.4), rien ne se passe.
 */
const syncAppBadge = () => {
    const holder = document.querySelector('[data-app-badge]');

    if (!holder || !('setAppBadge' in navigator)) return;

    const count = parseInt(holder.dataset.appBadge, 10) || 0;
    (count > 0 ? navigator.setAppBadge(count) : navigator.clearAppBadge()).catch(() => {});
};

document.addEventListener('livewire:navigated', syncAppBadge);
document.addEventListener('bouffe-badge', syncAppBadge);

/**
 * Minuteurs partagés (lot 41, 41.1).
 *
 * Un seul magasin pour toute la page : il lit les minuteurs du foyer (/minuteurs) toutes les
 * 5 secondes quand un minuteur tourne ou qu'une page de cuisine est ouverte, toutes les 30 secondes
 * sinon, et seulement si la page est visible. Le temps est compté ici, à l'heure du serveur (écart
 * mesuré à chaque lecture). C'est lui qui sonne — une fois par minuteur — et qui prévient le serveur
 * qu'il a sonné, pour qu'aucune notification ne parte en plus.
 *
 * Sans réseau (chalet, cave), un minuteur reste dans ce navigateur (« sur cet appareil ») et sonne
 * quand même.
 */
const LOCAL_TIMERS = 'bouffe-timers-local';

const timerStore = {
    timers: [],
    local: [],
    offset: 0,
    online: true,
    started: false,
    rung: new Set(),
    lastFetch: 0,

    start() {
        if (this.started) return;
        this.started = true;
        this.loadLocal();
        // Lot 35 : les minuteurs gardés par recette dans ce navigateur ne servent plus.
        try {
            Object.keys(localStorage).filter((key) => key.startsWith('bouffe-timers-') && key !== LOCAL_TIMERS).forEach((key) => localStorage.removeItem(key));
        } catch (e) {}
        this.refresh();
        setInterval(() => this.tick(), 500);
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') this.refresh(); });
        window.addEventListener('online', () => this.refresh());
    },

    now() { return Date.now() + this.offset; },

    all() { return [...this.timers, ...this.local].sort((a, b) => a.endsAt - b.endsAt); },

    emit() { window.dispatchEvent(new CustomEvent('bouffe-timers')); },

    csrf() { return document.querySelector('meta[name=csrf-token]')?.content ?? ''; },

    async request(method, url, body) {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: body ? JSON.stringify(body) : undefined,
        });
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    },

    apply(data) {
        if (!data || !Array.isArray(data.timers)) return;
        this.offset = (data.now || Date.now()) - Date.now();
        this.timers = data.timers;
        this.online = true;
        this.lastFetch = Date.now();
        this.emit();
    },

    async refresh() {
        try { this.apply(await this.request('GET', '/minuteurs')); } catch (e) { this.online = false; this.emit(); }
    },

    /** Lecture plus fréquente quand un minuteur tourne ou qu'une page de cuisine est ouverte. */
    tick() {
        const busy = this.all().length > 0 || document.querySelector('[data-timers-panel]');
        if (document.visibilityState === 'visible' && Date.now() - this.lastFetch > (busy ? 5000 : 30000)) {
            this.lastFetch = Date.now();
            this.refresh();
        }

        const now = this.now();
        this.all().filter((t) => t.endsAt <= now && !this.rung.has(this.keyOf(t))).forEach((t) => this.ring(t));
    },

    keyOf(timer) { return (timer.local ? 'l' : 's') + timer.id; },

    ring(timer) {
        this.rung.add(this.keyOf(timer));
        // Fini depuis plus de 2 minutes (page rouverte) : il clignote, sans sonner.
        if (this.now() - timer.endsAt > 120000) return;
        try { navigator.vibrate?.([300, 150, 300]); } catch (e) {}
        this.beep();
        // Lot 38 (38.4) : le mode mains libres l'annonce à voix haute.
        window.dispatchEvent(new CustomEvent('bouffe-timer-rang', { detail: timer }));
        if (!timer.local && document.visibilityState === 'visible') {
            this.request('POST', '/minuteurs/' + timer.id + '/sonne').catch(() => {});
        }
    },

    async add(minutes, label, source) {
        const seconds = Math.round(Number(minutes) * 60);
        if (!seconds) return;
        try {
            this.apply(await this.request('POST', '/minuteurs', { label, seconds, source }));
        } catch (e) {
            this.local.push({ id: Date.now() + Math.random(), label: label || 'Minuteur', endsAt: this.now() + seconds * 1000, duration: seconds * 1000, local: true });
            this.saveLocal();
            this.emit();
        }
    },

    async stop(timer) {
        if (timer.local) {
            this.local = this.local.filter((t) => t.id !== timer.id);
            this.saveLocal();
            this.emit();
            return;
        }
        this.timers = this.timers.filter((t) => t.id !== timer.id);
        this.emit();
        try { this.apply(await this.request('POST', '/minuteurs/' + timer.id + '/arreter')); } catch (e) { this.refresh(); }
    },

    loadLocal() {
        try {
            const cutoff = Date.now() - 3600000;
            this.local = JSON.parse(localStorage.getItem(LOCAL_TIMERS) || '[]').filter((t) => t && t.endsAt > cutoff).map((t) => ({ ...t, local: true }));
        } catch (e) { this.local = []; }
    },

    saveLocal() {
        try { localStorage.setItem(LOCAL_TIMERS, JSON.stringify(this.local)); } catch (e) {}
    },

    beep() {
        try {
            const context = new (window.AudioContext || window.webkitAudioContext)();
            [0, 0.35, 0.7].forEach((offset) => {
                const oscillator = context.createOscillator();
                const gain = context.createGain();
                oscillator.connect(gain);
                gain.connect(context.destination);
                oscillator.frequency.value = 880;
                gain.gain.setValueAtTime(0.25, context.currentTime + offset);
                gain.gain.exponentialRampToValueAtTime(0.001, context.currentTime + offset + 0.25);
                oscillator.start(context.currentTime + offset);
                oscillator.stop(context.currentTime + offset + 0.25);
            });
        } catch (e) {}
    },
};

window.bouffeTimerStore = timerStore;

/** « 12:05 », « 1 h 05 » ou « Terminé ». */
const timerDisplay = (timer, now) => {
    const left = Math.max(0, Math.round((timer.endsAt - now) / 1000));
    if (left === 0) return 'Terminé';
    const minutes = Math.floor(left / 60);
    return minutes >= 60 ? Math.floor(minutes / 60) + ' h ' + String(minutes % 60).padStart(2, '0') : String(minutes).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0');
};

/**
 * Liste de minuteurs (mode cuisine, repas complet, écran de cuisine, pastille) : tous ceux du foyer.
 * `source` dit d'où partent ceux lancés ici (« recette:12 », « repas:2026-10-20-2 », « cuisine »).
 * Les boutons d'étape envoient l'événement `bouffe-timer` : `x-on:bouffe-timer.window="add(…)"`.
 */
window.bouffeTimers = (source = null) => ({
    source,
    timers: [],
    now: Date.now(),
    clock: null,
    listener: null,
    online: true,

    init() {
        timerStore.start();
        this.sync();
        this.listener = () => this.sync();
        window.addEventListener('bouffe-timers', this.listener);
        this.clock = setInterval(() => { this.now = timerStore.now(); }, 500);
    },

    destroy() {
        clearInterval(this.clock);
        window.removeEventListener('bouffe-timers', this.listener);
    },

    sync() {
        this.timers = timerStore.all();
        this.now = timerStore.now();
        this.online = timerStore.online;
    },

    add(minutes, label) { return timerStore.add(minutes, label, this.source); },

    remove(timer) { return timerStore.stop(timer); },

    finished(timer) { return timer.endsAt <= this.now; },

    display(timer) { return timerDisplay(timer, this.now); },

    percent(timer) { return Math.min(100, Math.max(0, 100 - ((timer.endsAt - this.now) / timer.duration) * 100)); },

    beep() { timerStore.beep(); },
});

/** Écran de cuisine (lot 35) : la même liste, avec des minuteurs rapides. */
window.bouffeKitchenTimers = () => window.bouffeTimers('cuisine');

/**
 * Pastille des minuteurs (lot 41) : sur toutes les pages, le plus proche, et la liste d'un toucher.
 * Masquée sur les pages qui montrent déjà les minuteurs (mode cuisine, écran de cuisine).
 */
window.bouffeTimerPill = () => ({
    ...window.bouffeTimers(null),
    open: false,
    hidden: false,

    init() {
        timerStore.start();
        this.sync();
        this.listener = () => this.sync();
        window.addEventListener('bouffe-timers', this.listener);
        this.clock = setInterval(() => {
            this.now = timerStore.now();
            this.hidden = !!document.querySelector('[data-timers-panel]');
        }, 500);
    },

    get next() { return this.timers[0] ?? null; },
});

/**
 * Mode cuisine mains libres (lot 38, 38.4).
 *
 * « Lire à voix haute » : chaque étape est lue par le téléphone (synthèse vocale du navigateur, sans
 * service externe) ; un toucher n'importe où sur l'écran passe à la suivante ; un minuteur qui sonne
 * est annoncé. Là où le navigateur reconnaît la parole (Safari, Chrome), dire « suivant »,
 * « précédent », « répète » ou « minuteur » suffit. Le choix est gardé le temps de la session.
 */
const HANDS_FREE = 'bouffe-hands-free';

window.bouffeCookMode = () => ({
    wakeSupported: window.bouffeWakeLock.supported,
    canSpeak: 'speechSynthesis' in window,
    canListen: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
    handsFree: false,
    listening: false,
    recognition: null,
    onTimer: null,

    init() {
        try { this.handsFree = this.canSpeak && sessionStorage.getItem(HANDS_FREE) === '1'; } catch (e) {}
        this.onTimer = (event) => { if (this.handsFree) this.say(event.detail.label + ' : terminé.'); };
        window.addEventListener('bouffe-timer-rang', this.onTimer);
        if (this.handsFree) this.listen();
    },

    destroy() {
        window.removeEventListener('bouffe-timer-rang', this.onTimer);
        this.stopListening();
        try { window.speechSynthesis?.cancel(); } catch (e) {}
    },

    toggle() {
        this.handsFree = !this.handsFree;
        try { sessionStorage.setItem(HANDS_FREE, this.handsFree ? '1' : '0'); } catch (e) {}

        if (this.handsFree) {
            this.readCurrent();
            this.listen();
        } else {
            this.stopListening();
            try { window.speechSynthesis.cancel(); } catch (e) {}
        }
    },

    say(text) {
        if (!this.canSpeak || !text) return;
        try {
            window.speechSynthesis.cancel();
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = 'fr-FR';
            const voice = window.speechSynthesis.getVoices().find((v) => v.lang?.toLowerCase().startsWith('fr'));
            if (voice) utterance.voice = voice;
            window.speechSynthesis.speak(utterance);
        } catch (e) {}
    },

    readCurrent() {
        const holder = this.$root.querySelector('[data-speak]');
        if (holder) this.say(holder.dataset.speak);
    },

    /** Appelé par chaque étape quand elle s'affiche. */
    stepShown(text) {
        if (this.handsFree) this.$nextTick(() => this.say(text));
    },

    /** Un toucher n'importe où (sauf sur un bouton, un lien, un champ) : étape suivante. */
    tap(event) {
        if (!this.handsFree || event.target.closest('button, a, input, textarea, select, label, [role=menu], [role=dialog]')) return;
        this.$wire.next();
    },

    listen() {
        if (!this.canListen || this.recognition) return;
        try {
            const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            const recognition = new Recognition();
            recognition.lang = 'fr-FR';
            recognition.continuous = true;
            recognition.interimResults = false;
            recognition.onresult = (event) => {
                const result = event.results[event.results.length - 1];
                if (result?.isFinal !== false) this.command(result[0]?.transcript || '');
            };
            recognition.onstart = () => { this.listening = true; };
            // La reconnaissance s'arrête d'elle-même après un silence : on la relance tant que le mode est actif.
            recognition.onend = () => {
                this.listening = false;
                if (this.handsFree && this.recognition === recognition) setTimeout(() => { try { recognition.start(); } catch (e) {} }, 400);
            };
            recognition.onerror = (event) => {
                if (event.error === 'not-allowed' || event.error === 'service-not-allowed') { this.recognition = null; this.canListen = false; }
            };
            this.recognition = recognition;
            recognition.start();
        } catch (e) { this.recognition = null; }
    },

    stopListening() {
        const recognition = this.recognition;
        this.recognition = null;
        this.listening = false;
        try { recognition?.stop(); } catch (e) {}
    },

    command(transcript) {
        const said = transcript.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        if (/\b(suivant|suivante|apres|ensuite|continue)\b/.test(said)) this.$wire.next();
        else if (/\b(precedent|precedente|retour|avant)\b/.test(said)) this.$wire.previous();
        else if (/\b(repete|repeter|encore|relis)\b/.test(said)) this.readCurrent();
        else if (/\bminuteur\b/.test(said)) this.$root.querySelector('[data-step-timer]')?.click();
    },
});

/**
 * Cuisiner sans réseau (lot 41, 41.2) : pages autonomes, sans Alpine.
 *
 * « Garder sur cet appareil » met la liste et chaque recette dans le cache du service web
 * (« bouffe-offline », gardé d'une version à l'autre), avec leurs feuilles de style et scripts.
 * Les minuteurs passent par le même magasin que les autres pages : partagés avec la maison quand il
 * y a du réseau, gardés dans le téléphone sinon.
 */
const OFFLINE_CACHE = 'bouffe-offline';
const OFFLINE_SAVED = 'bouffe-offline-saved';

const offlineStatus = (box, text) => { const status = box?.querySelector('[data-offline-status]'); if (status) status.textContent = text; };

const keepOffline = async (button) => {
    const box = button.closest('[data-keep-offline-box]');

    if (!('caches' in window) || !window.isSecureContext) {
        offlineStatus(box, 'Indisponible ici : il faut ouvrir Bouffe par son adresse en https.');
        return;
    }

    const urls = [window.location.href, ...JSON.parse(button.dataset.urls || '[]')];
    const assets = new Set();
    let saved = 0;
    button.disabled = true;

    try {
        const cache = await caches.open(OFFLINE_CACHE);

        for (const url of urls) {
            offlineStatus(box, 'Enregistrement… ' + saved + ' / ' + urls.length);
            const response = await fetch(url, { credentials: 'same-origin' });
            if (!response.ok) continue;
            const html = await response.clone().text();
            // La liste est aussi gardée à « /sans-reseau », l'adresse proposée par la page « Pas de réseau ».
            if (url === window.location.href) await cache.put(new URL('/sans-reseau', window.location.href).href, response.clone());
            await cache.put(url, response);
            saved++;
            for (const match of html.matchAll(/(?:href|src)="([^"]*\/build\/[^"]+)"/g)) assets.add(new URL(match[1], window.location.href).href);
        }

        for (const asset of assets) {
            if (!(await cache.match(asset))) {
                const response = await fetch(asset);
                if (response.ok) await cache.put(asset, response);
            }
        }

        const at = new Date();
        try { localStorage.setItem(OFFLINE_SAVED, JSON.stringify({ at: at.toISOString(), count: saved - 1 })); } catch (e) {}
        offlineStatus(box, 'Gardé sur cet appareil : ' + (saved - 1) + ' recette' + (saved - 1 > 1 ? 's' : '') + ', le ' + at.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' }) + ' à ' + at.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) + '.');
    } catch (e) {
        offlineStatus(box, 'Enregistrement interrompu (' + saved + ' / ' + urls.length + ') : vérifiez le réseau et réessayez.');
    } finally {
        button.disabled = false;
    }
};

const offlineTimersRender = (container) => {
    const now = timerStore.now();
    const rows = timerStore.all().map((timer) => {
        const done = timer.endsAt <= now;
        return '<div class="flex items-center gap-3 rounded-xl px-4 py-2.5 ring-1 ' + (done ? 'bg-red-50 text-red-900 ring-red-200 animate-pulse' : 'bg-stone-900 text-white ring-stone-900') + '">'
            + '<span class="min-w-0 flex-1"><span class="block truncate">' + timer.label.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]) + '</span>'
            + (timer.local ? '<span class="block text-xs opacity-75">sur cet appareil (sans réseau)</span>' : '') + '</span>'
            + '<span class="text-xl font-bold tabular-nums">' + timerDisplay(timer, now) + '</span>'
            + '<button type="button" class="min-h-10 rounded-lg px-3 text-sm ' + (done ? 'bg-red-600 text-white' : 'bg-white/15') + '" data-stop-timer="' + (timer.local ? 'l' : 's') + timer.id + '">' + (done ? 'OK' : 'Arrêter') + '</button></div>';
    });
    container.innerHTML = rows.join('');
};

const setupOfflinePage = () => {
    if (!document.querySelector('[data-offline-page]')) return;

    // État du réseau, en haut de la page.
    const network = () => {
        const online = navigator.onLine;
        document.querySelectorAll('[data-network-dot]').forEach((dot) => dot.className = 'size-2 rounded-full ' + (online ? 'bg-herb-500' : 'bg-amber-500'));
        document.querySelectorAll('[data-network-label]').forEach((label) => label.textContent = online ? 'En ligne' : 'Sans réseau');
    };
    network();
    window.addEventListener('online', network);
    window.addEventListener('offline', network);

    // Dernier enregistrement.
    const box = document.querySelector('[data-keep-offline-box]');
    try {
        const last = JSON.parse(localStorage.getItem(OFFLINE_SAVED) || 'null');
        if (box && last?.at) offlineStatus(box, 'Dernier enregistrement : ' + new Date(last.at).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' }) + ' (' + last.count + ' recette' + (last.count > 1 ? 's' : '') + ').');
    } catch (e) {}

    document.addEventListener('click', (event) => {
        const keep = event.target.closest('[data-keep-offline]');
        if (keep) { keepOffline(keep); return; }

        const start = event.target.closest('[data-offline-timer]');
        if (start) { timerStore.add(Number(start.dataset.minutes), start.dataset.label, 'sans-reseau'); return; }

        const stop = event.target.closest('[data-stop-timer]');
        if (stop) {
            const timer = timerStore.all().find((t) => (t.local ? 'l' : 's') + t.id === stop.dataset.stopTimer);
            if (timer) timerStore.stop(timer);
        }
    });

    const container = document.querySelector('[data-offline-timers]');
    if (container) {
        timerStore.start();
        offlineTimersRender(container);
        window.addEventListener('bouffe-timers', () => offlineTimersRender(container));
        setInterval(() => offlineTimersRender(container), 1000);
    }
};

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setupOfflinePage); else setupOfflinePage();

/**
 * Écran de cuisine : sombre le soir (19 h – 7 h), quel que soit le thème choisi, et horloge.
 */
window.bouffeKitchen = () => ({
    time: '',
    evening: false,

    init() {
        this.tick();
        setInterval(() => this.tick(), 15000);
        // Le thème choisi est réappliqué au chargement (livewire:navigated) : le soir reprend la main.
        document.addEventListener('livewire:navigated', () => this.tick());
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => this.tick());
        window.bouffeWakeLock.request();
    },

    tick() {
        const now = new Date();
        this.time = now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
        this.evening = now.getHours() >= 19 || now.getHours() < 7;
        const theme = window.bouffeTheme?.get() ?? 'auto';
        const chosenDark = theme === 'dark' || (theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', this.evening || chosenDark);
    },
});

/* ============================================================================
 * Mode magasin et liste hors-ligne (lot 16 — 15.1, 15.4, 20.3, règle R20).
 *
 * En magasin, le réseau va et vient. La page embarque la liste, garde les gestes
 * dans le navigateur (localStorage) et les renvoie au serveur dès que possible,
 * dans l'ordre où ils ont eu lieu. Cocher un article ne demande jamais le réseau.
 * ========================================================================== */

window.bouffeUuid = () => {
    try { return crypto.randomUUID(); } catch (e) {}
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
};

window.storeMode = (snapshot, syncUrl, stateUrl, aislesUrl = null) => ({
    key: 'bouffe-magasin-' + snapshot.id,
    list: snapshot,
    pending: [],          // gestes pas encore remontés (R20)
    messages: [],         // ce que le serveur a dû ignorer ou reporter
    online: true,
    syncing: false,
    awake: false,
    hideChecked: false,
    collapsed: [],
    newItem: '',
    lastSync: null,
    splitOpen: false,     // lot 42 (42.3) : « On se partage ? »
    expanded: [],         // rayons des autres, dépliés quand même

    init() {
        this.online = navigator.onLine !== false;
        this.restore();
        this.replay();

        window.addEventListener('online', () => { this.online = true; this.push(); });
        window.addEventListener('offline', () => { this.online = false; });

        // Retour sur la page : on récupère ce que le reste de la maison a fait.
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') this.push();
        });

        setInterval(() => this.push(), 30000);
        // Courses à deux : on relit souvent la liste pour voir ce que l'autre a coché.
        setInterval(() => { if (this.splitActive && document.visibilityState === 'visible') this.push(); }, 6000);
        this.$watch('hideChecked', () => this.remember());
        this.$watch('collapsed', () => this.remember());

        if (this.pending.length > 0) this.push();
    },

    /* ------------------------------------------------------------------ État */

    get items() {
        return this.list.items || [];
    },

    get checkedCount() {
        return this.items.filter((i) => i.checked).length;
    },

    get remaining() {
        return this.items.length - this.checkedCount;
    },

    get progress() {
        return this.items.length === 0 ? 0 : Math.round((this.checkedCount / this.items.length) * 100);
    },

    /** Rayons qui ont encore quelque chose à montrer (le filtre « masquer ce qui est pris » les vide). */
    get visibleAisles() {
        const aisles = (this.list.aisles || []).filter((aisle) => this.itemsOf(aisle.id).length > 0);
        if (! this.splitActive) return aisles;

        // À deux : mes rayons d'abord, puis les rayons libres, puis ceux des autres.
        const rank = (aisle) => {
            const owner = this.ownerOf(aisle.id);
            return owner === this.split.me ? 0 : (owner === null ? 1 : 2);
        };
        return aisles.map((aisle, i) => ({ aisle, i })).sort((a, b) => rank(a.aisle) - rank(b.aisle) || a.i - b.i).map((x) => x.aisle);
    },

    /* ---------------------------------------------------- Courses à deux (42.3) */

    get split() {
        return this.list.split || { me: null, people: [], owners: {} };
    },

    /** Moi d'abord, puis les autres. */
    get splitPeople() {
        const people = this.split.people || [];
        return [...people.filter((p) => p.id === this.split.me), ...people.filter((p) => p.id !== this.split.me)];
    },

    get splitActive() {
        return Object.keys(this.split.owners || {}).length > 0;
    },

    ownerOf(aisleId) {
        const owner = (this.split.owners || {})[aisleId];
        return owner === undefined || owner === null ? null : Number(owner);
    },

    ownerName(aisleId) {
        const owner = this.ownerOf(aisleId);
        if (owner === null) return '';
        if (owner === this.split.me) return 'Vous';
        return (this.split.people.find((p) => p.id === owner) || {}).name || '';
    },

    isMine(aisleId) {
        return this.ownerOf(aisleId) === this.split.me;
    },

    /** Un rayon des autres est replié, sauf si on l'a déplié. */
    isCollapsed(aisleId) {
        const owner = this.ownerOf(aisleId);
        if (this.splitActive && owner !== null && owner !== this.split.me) return ! this.expanded.includes(aisleId);
        return this.collapsed.includes(aisleId);
    },

    sectionTitle(aisle, index) {
        if (! this.splitActive) return '';
        const owner = this.ownerOf(aisle.id);
        const previous = index > 0 ? this.visibleAisles[index - 1] : null;
        const previousOwner = previous ? this.ownerOf(previous.id) : undefined;
        const group = owner === this.split.me ? 'me' : (owner === null ? 'free' : 'other-' + owner);
        const previousGroup = previous === null ? null : (previousOwner === this.split.me ? 'me' : (previousOwner === null ? 'free' : 'other-' + previousOwner));
        if (group === previousGroup) return '';
        if (group === 'me') return 'Vos rayons';
        if (group === 'free') return 'Rayons libres';
        return 'Rayons de ' + this.ownerName(aisle.id);
    },

    async assign(aisleId, userId) {
        const owners = { ...(this.split.owners || {}) };
        if (userId === null) delete owners[aisleId]; else owners[aisleId] = userId;
        this.list.split = { ...this.split, owners };
        await this.postAisles({ aisle_id: aisleId, user_id: userId });
    },

    async resetSplit() {
        this.list.split = { ...this.split, owners: {} };
        this.splitOpen = false;
        await this.postAisles({ reset: true });
    },

    async postAisles(body) {
        if (! aislesUrl) return;
        try {
            const response = await fetch(aislesUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                },
                body: JSON.stringify(body),
            });
            if (! response.ok) throw new Error(response.status);
            this.reconcile(await response.json());
            this.lastSync = Date.now();
        } catch (e) {
            this.messages = ['Le partage des rayons demande du réseau : réessayez dans un instant.'];
        }
    },

    itemsOf(aisleId) {
        return this.items.filter((i) => i.aisle_id === aisleId && (! this.hideChecked || ! i.checked));
    },

    aisleTotal(aisleId) {
        return this.items.filter((i) => i.aisle_id === aisleId).length;
    },

    aisleRemaining(aisleId) {
        return this.items.filter((i) => i.aisle_id === aisleId && ! i.checked).length;
    },

    /* ---------------------------------------------------------------- Gestes */

    toggle(item) {
        item.checked = ! item.checked;
        item.checked_by = item.checked ? this.split.me : null;
        item.checked_name = null;
        this.queue({ action: item.checked ? 'check' : 'uncheck', item_id: item.id > 0 ? item.id : null, label: item.label });
        try { navigator.vibrate?.(15); } catch (e) {}
    },

    /** « Tout ce rayon est fait » : un geste par article restant, dans l'ordre. */
    checkAisle(aisleId) {
        this.items.filter((i) => i.aisle_id === aisleId && ! i.checked).forEach((item) => this.toggle(item));
    },

    toggleAisle(aisleId) {
        const owner = this.ownerOf(aisleId);
        if (this.splitActive && owner !== null && owner !== this.split.me) {
            this.expanded = this.expanded.includes(aisleId) ? this.expanded.filter((id) => id !== aisleId) : [...this.expanded, aisleId];
            return;
        }
        this.collapsed = this.collapsed.includes(aisleId)
            ? this.collapsed.filter((id) => id !== aisleId)
            : [...this.collapsed, aisleId];
    },

    /** Ajout d'un article oublié : visible tout de suite, envoyé au retour du réseau. */
    add() {
        const label = this.newItem.trim();
        if (label === '') return;

        const aisle = { id: -2, name: 'Ajouté en magasin' };
        if (! (this.list.aisles || []).some((a) => a.id === aisle.id)) this.list.aisles = [...(this.list.aisles || []), aisle];

        this.list.items = [...this.items, {
            id: -Date.now(), text: label, label, aisle: aisle.name, aisle_id: aisle.id,
            checked: false, optional: false, note: null, origin: 'manual',
        }];

        this.queue({ action: 'add', item_id: null, label, value: label });
        this.newItem = '';
    },

    queue(operation) {
        this.pending = [...this.pending, { uuid: window.bouffeUuid(), at: new Date().toISOString(), value: null, ...operation }];
        this.remember();
        this.push();
    },

    /* --------------------------------------------------- Aller-retour serveur */

    async push() {
        if (this.syncing || ! this.online) return;
        if (this.pending.length === 0) return this.refresh();

        this.syncing = true;
        const sent = this.pending.map((o) => o.uuid);

        try {
            const response = await fetch(syncUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                },
                body: JSON.stringify({ operations: this.pending }),
            });

            if (! response.ok) throw new Error(response.status);

            const data = await response.json();
            this.pending = this.pending.filter((o) => ! sent.includes(o.uuid));
            this.messages = data.report?.messages ?? [];
            this.reconcile(data.list);
            this.lastSync = Date.now();
            this.online = true;
        } catch (e) {
            // Pas de réseau (ou serveur injoignable) : les gestes restent en attente.
            this.online = navigator.onLine !== false && ! (e instanceof TypeError);
        } finally {
            this.syncing = false;
            this.remember();
        }
    },

    /** Rien à envoyer : on demande simplement la liste à jour (quelqu'un d'autre a pu cocher). */
    async refresh() {
        if (this.lastSync && Date.now() - this.lastSync < (this.splitActive ? 5000 : 15000)) return;

        this.syncing = true;

        try {
            const response = await fetch(stateUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (! response.ok) throw new Error(response.status);

            this.reconcile(await response.json());
            this.lastSync = Date.now();
            this.online = true;
        } catch (e) {
            this.online = navigator.onLine !== false && ! (e instanceof TypeError);
        } finally {
            this.syncing = false;
            this.remember();
        }
    },

    /**
     * La liste du serveur fait foi, sauf pour les gestes pas encore remontés :
     * ceux-là sont réappliqués par-dessus pour que l'écran reste cohérent.
     */
    reconcile(list) {
        if (! list) return;
        this.list = list;
        this.replay();
    },

    replay() {
        this.pending.forEach((operation) => {
            const item = this.items.find((i) => i.id === operation.item_id)
                || this.items.find((i) => operation.label && i.label === operation.label);

            if (operation.action === 'check' && item) item.checked = true;
            if (operation.action === 'uncheck' && item) item.checked = false;

            if (operation.action === 'add' && ! item) {
                this.list.items = [...this.items, {
                    id: -Date.now() - Math.floor(Math.random() * 1000), text: operation.label, label: operation.label,
                    aisle: 'Ajouté en magasin', aisle_id: -2, checked: false, optional: false, note: null, origin: 'manual',
                }];
                if (! (this.list.aisles || []).some((a) => a.id === -2)) {
                    this.list.aisles = [...(this.list.aisles || []), { id: -2, name: 'Ajouté en magasin' }];
                }
            }
        });
    },

    /* ------------------------------------------------- Mémoire du navigateur */

    remember() {
        try {
            localStorage.setItem(this.key, JSON.stringify({
                pending: this.pending,
                list: this.list,
                hideChecked: this.hideChecked,
                collapsed: this.collapsed,
            }));
        } catch (e) {}
    },

    restore() {
        try {
            const saved = JSON.parse(localStorage.getItem(this.key) || 'null');
            if (! saved) return;

            this.pending = Array.isArray(saved.pending) ? saved.pending : [];
            this.hideChecked = !! saved.hideChecked;
            this.collapsed = Array.isArray(saved.collapsed) ? saved.collapsed : [];

            // Page ouverte sans réseau : la liste embarquée peut être vide, on reprend celle gardée.
            if (! navigator.onLine && saved.list?.items?.length) this.list = saved.list;
        } catch (e) {}
    },

    async wake() {
        this.awake = await window.bouffeWakeLock.request();
        if (! this.awake) this.messages = ["Votre navigateur ne garde l'écran allumé qu'en HTTPS."];
    },
});

/* ============================================================================
 * PWA (20.2) : service worker et invitation à installer.
 * ========================================================================== */

if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}

window.bouffeInstall = () => ({
    prompt: null,
    dismissed: false,

    init() {
        try { this.dismissed = localStorage.getItem('bouffe-install') === 'non'; } catch (e) {}

        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault();
            this.prompt = event;
        });

        window.addEventListener('appinstalled', () => { this.prompt = null; });
    },

    get visible() {
        return this.prompt !== null && ! this.dismissed;
    },

    async install() {
        if (! this.prompt) return;
        this.prompt.prompt();
        await this.prompt.userChoice;
        this.prompt = null;
    },

    never() {
        this.dismissed = true;
        try { localStorage.setItem('bouffe-install', 'non'); } catch (e) {}
    },
});

/* ============================================================================
 * Scan de code-barres (lot 18 — 16.1).
 *
 * Le décodage se fait entièrement dans le navigateur (bibliothèque ZXing) : l'image de la
 * caméra ne quitte jamais le téléphone. La bibliothèque n'est chargée que sur cette page,
 * au moment où on active la caméra.
 *
 * La caméra exige une origine sûre (https:// ou localhost) : sinon on le dit clairement,
 * et la saisie du code au clavier prend le relais.
 * ========================================================================== */

window.barcodeScanner = () => ({
    running: false,
    message: '',
    controls: null,
    supported: false,
    lastCode: '',
    lastAt: 0,

    init() {
        this.supported = !! (navigator.mediaDevices?.getUserMedia) && window.isSecureContext;
        window.addEventListener('beforeunload', () => this.stop());
        document.addEventListener('livewire:navigating', () => this.stop());
    },

    async start() {
        if (! this.supported || this.running) return;

        this.message = '';

        try {
            const { BrowserMultiFormatReader } = await import('@zxing/browser');
            const reader = new BrowserMultiFormatReader();

            this.controls = await reader.decodeFromVideoDevice(undefined, this.$refs.video, (result, error, controls) => {
                if (! result) return;

                const code = result.getText();
                const now = Date.now();

                // Le même code est lu plusieurs fois par seconde : on ne le traite qu'une fois.
                if (code === this.lastCode && now - this.lastAt < 4000) return;

                this.lastCode = code;
                this.lastAt = now;

                try { navigator.vibrate?.(40); } catch (e) {}

                this.$wire.lookup(code);
                controls.stop();
                this.running = false;
            });

            this.running = true;
        } catch (e) {
            this.running = false;
            this.message = e?.name === 'NotAllowedError'
                ? "Accès à la caméra refusé. Autorisez-le dans le navigateur, ou saisissez le code à la main."
                : "Caméra indisponible sur cet appareil. Saisissez le code à la main juste en dessous.";
        }
    },

    stop() {
        try { this.controls?.stop(); } catch (e) {}
        this.controls = null;
        this.running = false;
    },
});

/*
 * Notifications sur le téléphone (lot 20, 19.2).
 *
 * Activation en trois temps : autorisation du navigateur, abonnement auprès de son service de
 * notification (avec la clé publique de Bouffe), puis envoi de l'abonnement au serveur.
 * Sur iPhone, seulement une fois Bouffe ajouté à l'écran d'accueil (iOS 16.4 ou plus récent).
 */
window.bouffePush = (urls = { key: '/notifications/cle', subscribe: '/notifications/abonnement', unsubscribe: '/notifications/desabonnement' }) => ({
    urls,
    supported: false,
    subscribed: false,
    blocked: false,
    canEnable: false,
    busy: false,
    status: 'Vérification…',
    hint: '',

    csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    },

    isIos() {
        return /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    },

    isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    },

    device() {
        const ua = navigator.userAgent;
        const os = /iphone/i.test(ua) ? 'iPhone' : /ipad/i.test(ua) ? 'iPad' : /android/i.test(ua) ? 'Android' : /windows/i.test(ua) ? 'Windows' : /mac os/i.test(ua) ? 'Mac' : /linux/i.test(ua) ? 'Linux' : 'Appareil';
        const browser = /edg\//i.test(ua) ? 'Edge' : /firefox/i.test(ua) ? 'Firefox' : /chrome|crios/i.test(ua) ? 'Chrome' : /safari/i.test(ua) ? 'Safari' : 'navigateur';
        return this.isStandalone() ? os + ' (application)' : browser + ' sur ' + os;
    },

    async init() {
        if (!window.isSecureContext) {
            this.status = 'Indisponible sur une adresse non sécurisée';
            this.hint = 'Les notifications demandent une adresse en https:// (voir Paramètres → Accès téléphone).';
            return;
        }

        if (this.isIos() && !this.isStandalone()) {
            this.status = 'Sur iPhone : ajoutez d\'abord Bouffe à l\'écran d\'accueil';
            this.hint = 'Dans Safari : bouton Partager → « Sur l\'écran d\'accueil », puis ouvrez Bouffe depuis cette icône et revenez ici (iOS 16.4 ou plus récent).';
            return;
        }

        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            this.status = 'Ce navigateur ne gère pas les notifications';
            return;
        }

        this.supported = true;

        if (Notification.permission === 'denied') {
            this.blocked = true;
            this.status = 'Notifications bloquées pour Bouffe';
            this.hint = 'Autorisez-les dans les réglages du navigateur (icône à gauche de l\'adresse), puis rechargez la page.';
            return;
        }

        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        this.subscribed = subscription !== null;
        this.canEnable = true;
        this.status = this.subscribed ? 'Notifications activées sur cet appareil' : 'Notifications désactivées sur cet appareil';

        // L'abonnement peut avoir changé côté navigateur : on le renvoie (sans effet s'il est connu).
        if (subscription) this.send(subscription).catch(() => {});
    },

    async enable() {
        this.busy = true;
        this.hint = '';

        try {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                this.blocked = permission === 'denied';
                this.status = 'Autorisation refusée';
                return;
            }

            const response = await fetch(this.urls.key, { headers: { Accept: 'application/json' } });
            const data = await response.json();

            if (!data.supported) {
                this.status = 'Le serveur ne peut pas envoyer de notifications';
                this.hint = data.message ?? '';
                return;
            }

            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.toBytes(data.publicKey),
            });

            await this.send(subscription);
            this.subscribed = true;
            this.status = 'Notifications activées sur cet appareil';
            this.$wire?.$dispatch('push-changed');
        } catch (error) {
            this.status = 'Activation impossible';
            this.hint = String(error?.message ?? error);
        } finally {
            this.busy = false;
        }
    },

    async disable() {
        this.busy = true;

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            if (subscription) {
                await fetch(this.urls.unsubscribe, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    body: JSON.stringify({ endpoint: subscription.endpoint }),
                });
                await subscription.unsubscribe();
            }

            this.subscribed = false;
            this.status = 'Notifications désactivées sur cet appareil';
            this.$wire?.$dispatch('push-changed');
        } finally {
            this.busy = false;
        }
    },

    async send(subscription) {
        const json = subscription.toJSON();
        const response = await fetch(this.urls.subscribe, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
            body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys, device: this.device() }),
        });

        if (!response.ok) throw new Error('Le serveur a refusé l\'abonnement (' + response.status + ').');
    },

    toBytes(base64) {
        const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
    },
});

/**
 * Photo d'un ticket de caisse (lot 23, 24.1) : rotation et recadrage dans le navigateur, puis
 * réduction (2 400 px au plus) et envoi en JPEG. Un PDF est envoyé tel quel.
 * Usage : x-data="bouffeReceiptPhoto()" dans un composant Livewire ayant une propriété « newPhoto ».
 */
window.bouffeReceiptPhoto = () => ({
    image: null,
    rotation: 0,
    crop: { top: 0, bottom: 0, left: 0, right: 0 },
    progress: 0,
    uploading: false,
    error: null,
    maxSide: 2400,

    async pick(event) {
        const file = event.target.files?.[0];
        event.target.value = '';
        this.error = null;
        if (!file) return;

        if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
            this.send(file);
            return;
        }

        const url = URL.createObjectURL(file);
        const img = new Image();
        img.src = url;
        try {
            await img.decode();
        } catch (e) {
            this.error = 'Cette image ne peut pas être ouverte ici. Essayez une photo JPEG.';
            URL.revokeObjectURL(url);
            return;
        }
        this.image = img;
        this.rotation = 0;
        this.crop = { top: 0, bottom: 0, left: 0, right: 0 };
        this.$nextTick(() => this.preview());
    },

    rotate() {
        this.rotation = (this.rotation + 90) % 360;
        this.preview();
    },

    /** Image tournée, réduite d'un facteur « scale ». */
    rotated(scale) {
        const w = Math.round(this.image.naturalWidth * scale);
        const h = Math.round(this.image.naturalHeight * scale);
        const turned = this.rotation % 180 !== 0;
        const canvas = document.createElement('canvas');
        canvas.width = turned ? h : w;
        canvas.height = turned ? w : h;
        const ctx = canvas.getContext('2d');
        ctx.translate(canvas.width / 2, canvas.height / 2);
        ctx.rotate((this.rotation * Math.PI) / 180);
        ctx.drawImage(this.image, -w / 2, -h / 2, w, h);
        return canvas;
    },

    preview() {
        const target = this.$refs.preview;
        if (!target || !this.image) return;
        const box = Math.min(target.parentElement.clientWidth || 320, 480);
        const scale = Math.min(1, box / Math.max(this.image.naturalWidth, this.image.naturalHeight));
        const source = this.rotated(scale);
        target.width = source.width;
        target.height = source.height;
        const ctx = target.getContext('2d');
        ctx.drawImage(source, 0, 0);
        // Parties retirées par le recadrage : voilées
        const { top, bottom, left, right } = this.crop;
        ctx.fillStyle = 'rgba(28, 25, 23, 0.65)';
        ctx.fillRect(0, 0, target.width, (target.height * top) / 100);
        ctx.fillRect(0, target.height * (1 - bottom / 100), target.width, (target.height * bottom) / 100);
        ctx.fillRect(0, 0, (target.width * left) / 100, target.height);
        ctx.fillRect(target.width * (1 - right / 100), 0, (target.width * right) / 100, target.height);
    },

    async confirm() {
        if (!this.image) return;
        const { top, bottom, left, right } = this.crop;
        const keepW = 1 - (left + right) / 100;
        const keepH = 1 - (top + bottom) / 100;
        const turned = this.rotation % 180 !== 0;
        const fullW = turned ? this.image.naturalHeight : this.image.naturalWidth;
        const fullH = turned ? this.image.naturalWidth : this.image.naturalHeight;
        const scale = Math.min(1, this.maxSide / Math.max(fullW * keepW, fullH * keepH));
        const source = this.rotated(scale);
        const x = Math.round((source.width * left) / 100);
        const y = Math.round((source.height * top) / 100);
        const w = Math.max(1, Math.round(source.width * keepW));
        const h = Math.max(1, Math.round(source.height * keepH));
        const out = document.createElement('canvas');
        out.width = w;
        out.height = h;
        out.getContext('2d').drawImage(source, x, y, w, h, 0, 0, w, h);
        const blob = await new Promise((resolve) => out.toBlob(resolve, 'image/jpeg', 0.85));
        if (!blob) {
            this.error = 'La photo n\'a pas pu être préparée.';
            return;
        }
        this.send(new File([blob], 'ticket.jpg', { type: 'image/jpeg' }));
    },

    cancel() {
        this.image = null;
    },

    send(file) {
        this.uploading = true;
        this.progress = 0;
        this.$wire.upload(
            'newPhoto',
            file,
            () => { this.uploading = false; this.image = null; },
            () => { this.uploading = false; this.error = 'L\'envoi a échoué (fichier trop lourd ?). Réessayez.'; },
            (e) => { this.progress = e.detail.progress; },
        );
    },
});

/**
 * Planning jour par jour sur téléphone (lot 28, 28.3).
 *
 * Le jour montré est l'attribut data-day du conteneur (le CSS masque les autres jours) : le changer
 * est instantané. La valeur est confiée à Livewire sans requête (`$set(…, false)`) et part avec la
 * requête suivante, pour que le serveur réaffiche le même jour après un ajout ou un déplacement.
 * Un balayage horizontal passe au jour voisin ; au-delà du dimanche ou avant le lundi, à la semaine
 * voisine. Les repas qu'on fait glisser (wire:sort) ne déclenchent pas de balayage.
 */
window.bouffeWeekDays = () => ({
    sx: null,
    sy: null,

    current() {
        return parseInt(this.$root.dataset.day, 10) || 0;
    },

    show(index) {
        this.$root.dataset.day = String(index);
        this.$wire.$set('day', index, false);
        if (this.$root.dataset.all === '1') this.$wire.toggleAllDays();
    },

    start(event) {
        const touch = event.changedTouches[0];
        const onMeal = event.target.closest('[wire\\:sort\\:item], input, textarea, select');
        this.sx = onMeal ? null : touch.clientX;
        this.sy = touch.clientY;
    },

    end(event) {
        if (this.sx === null) return;
        const touch = event.changedTouches[0];
        const dx = touch.clientX - this.sx;
        const dy = touch.clientY - this.sy;
        this.sx = null;

        if (Math.abs(dx) < 60 || Math.abs(dx) < Math.abs(dy) * 1.5) return;
        if (this.$root.dataset.all === '1' || window.matchMedia('(min-width: 768px)').matches) return;

        const next = this.current() + (dx < 0 ? 1 : -1);
        if (next < 0 || next > 6) {
            this.$wire.shiftDay(dx < 0 ? 1 : -1);
            return;
        }
        this.show(next);
    },
});
