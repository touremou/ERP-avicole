// Service Worker AviSmart — mode terrain (connexions instables / coupures réseau).
//
// Stratégie : réseau d'abord pour les requêtes GET, avec mise en cache de la
// réponse pour rejouer la page en cas de coupure. Les pages de navigation qui
// échouent (réseau coupé, serveur injoignable) retombent sur /offline.
//
// Les formulaires (POST) ne sont JAMAIS interceptés ici : un Service Worker
// n'a pas accès au DOM. La sauvegarde locale (IndexedDB / Dexie) en cas de
// coupure est gérée directement dans les pages (cf. resources/js/*.js et les
// scripts des vues batches/create et daily-checks/create).
const CACHE_NAME = 'avismart-v1';
const OFFLINE_URL = '/offline';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.add(OFFLINE_URL))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => Promise.all(
            cacheNames
                .filter((name) => name !== CACHE_NAME)
                .map((name) => caches.delete(name))
        ))
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                const copy = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
                return response;
            })
            .catch(() => caches.match(event.request).then((cached) => {
                if (cached) return cached;
                if (event.request.mode === 'navigate') return caches.match(OFFLINE_URL);
            }))
    );
});
