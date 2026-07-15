// Service Worker AviSmart — mode terrain (connexions instables / coupures réseau).
//
// Stratégie :
//   - Navigations (pages HTML) : RÉSEAU D'ABORD, repli sur /offline. Le HTML
//     authentifié n'est JAMAIS mis en cache — sinon le SW pouvait resservir une
//     page périmée (voire d'un autre utilisateur), ce qui « collait » la page
//     offline même serveur joignable.
//   - Autres GET (assets) : réseau d'abord, cache de secours (réponses 200
//     same-origin uniquement).
//
// Les formulaires (POST) ne sont jamais interceptés : la sauvegarde locale en
// cas de coupure est gérée dans les pages (IndexedDB / Dexie).
//
// CACHE_NAME est versionné : tout changement de version purge les anciens
// caches à l'activation (cf. handler `activate`).
const CACHE_NAME = 'avismart-v4';
const OFFLINE_URL = '/offline';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.add(OFFLINE_URL))
            .catch(() => {}) // ne jamais faire échouer l'installation
    );
    // ⚠️ PAS de skipWaiting() ici : un nouveau SW ne doit pas s'activer et
    // recharger la page en plein milieu d'une navigation (symptôme « double-clic »
    // / « la redirection ne prend pas »). L'activation est pilotée par
    // l'utilisateur via le toast de mise à jour (message 'skipWaiting' ci-dessous).
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((names) => Promise.all(
                names.filter((name) => name !== CACHE_NAME).map((name) => caches.delete(name))
            ))
            .then(() => self.clients.claim())
    );
});

// Permet à la page de forcer l'activation immédiate du nouveau SW.
self.addEventListener('message', (event) => {
    if (event.data === 'skipWaiting') self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    // On n'intercepte que le même domaine (jamais les CDN / API externes).
    if (url.origin !== self.location.origin) return;

    // Pages HTML : réseau d'abord, repli /offline, AUCUNE mise en cache du HTML.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    // Assets statiques : réseau d'abord, cache de secours.
    event.respondWith(
        fetch(req)
            .then((response) => {
                if (response && response.status === 200 && response.type === 'basic') {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(req, copy));
                }
                return response;
            })
            .catch(() => caches.match(req))
    );
});
