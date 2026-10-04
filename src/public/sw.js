/*
 * Bouffe — service worker (lot 16, point 20.2).
 *
 * Objectif : que la liste de courses s'ouvre en magasin même sans réseau.
 *
 *  - les fichiers construits (public/build) ne changent jamais à URL identique : cache d'abord ;
 *  - les pages : réseau d'abord, puis la dernière version gardée, puis la page « hors ligne » ;
 *  - tout le reste (POST, Livewire, synchronisation) passe directement au réseau.
 *
 * La version ci-dessous sert à vider l'ancien cache après une mise à jour.
 */

const VERSION = 'bouffe-v2';
const OFFLINE = '/hors-ligne';
const PRECACHE = [OFFLINE, '/favicon.svg', '/icon-192.png', '/manifest.webmanifest'];
// Lot 41 (41.2) : recettes gardées pour cuisiner sans réseau — conservées d'une version à l'autre.
const KEPT = 'bouffe-offline';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(VERSION)
            .then((cache) => cache.addAll(PRECACHE))
            .catch(() => {})
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== VERSION && key !== KEPT).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET' || new URL(request.url).origin !== self.location.origin) return;

    // Fichiers construits par Vite : leur nom contient une empreinte, ils ne changent pas.
    if (request.url.includes('/build/') || PRECACHE.some((path) => request.url.endsWith(path))) {
        event.respondWith(
            caches.match(request).then((hit) => hit || fetch(request).then((response) => store(request, response)))
        );

        return;
    }

    // Pages : on essaie le réseau, sinon la dernière version vue, sinon la page « hors ligne ».
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => store(request, response))
                .catch(() => caches.match(request).then((hit) => hit || caches.match(OFFLINE)))
        );
    }
});

function store(request, response) {
    if (response && response.ok && response.type === 'basic') {
        const copy = response.clone();
        caches.open(VERSION).then((cache) => cache.put(request, copy)).catch(() => {});
    }

    return response;
}

/*
 * Notifications (lot 20, 19.2) : le message chiffré par Bouffe est déchiffré par le navigateur,
 * puis affiché ici. Un toucher ouvre la page concernée (ou la ramène au premier plan).
 */
self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'Bouffe', body: event.data ? event.data.text() : '' };
    }

    // Lot 37 (37.1) : pastille chiffrée sur l'icône (repas à clôturer), là où le système la propose.
    const badge = Number.isInteger(data.badge) && 'setAppBadge' in self.navigator
        ? (data.badge > 0 ? self.navigator.setAppBadge(data.badge) : self.navigator.clearAppBadge()).catch(() => {})
        : Promise.resolve();

    event.waitUntil(Promise.all([
        badge,
        self.registration.showNotification(data.title || 'Bouffe', {
            body: data.body || '',
            icon: '/icon-192.png',
            badge: '/icon-192.png',
            tag: data.tag || undefined,
            data: { url: data.url || '/' },
        }),
    ]));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/', self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            const existing = clients.find((client) => client.url.startsWith(self.location.origin));

            if (existing) {
                return existing.focus().then((client) => client.navigate ? client.navigate(url) : client);
            }

            return self.clients.openWindow(url);
        })
    );
});
