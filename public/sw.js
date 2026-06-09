const CACHE_NAME = 'avismart-v1';
const OFFLINE_URL = '/offline'; // La route vers la vue resources/views/offline.blade.php
const ASSETS = [
    '/css/app.css',
    '/js/app.js',
    '/offline', // Page d'attente personnalisée
    '/dashboard'
];


const ASSETS_TO_CACHE = [
    '/',
    '/offline',
    '/css/app.css', // Vérifiez vos chemins réels
    '/js/app.js',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css',
];

document.getElementById('batchForm').addEventListener('submit', async function(e) {
    if (!navigator.onLine) {
        e.preventDefault(); // Empêche l'envoi au serveur éteint

        const formData = new FormData(this);
        const offlineData = Object.fromEntries(formData.entries());
        
        // Ajout des métadonnées indispensables pour la réconciliation future
        offlineData.uuid = self.crypto.randomUUID(); 
        offlineData.is_synced = 0;
        offlineData.created_at = new Date().toISOString();
        offlineData.updated_at = new Date().toISOString();

        try {
            await db.batches.add(offlineData);
            alert("✅ MODE TERRAIN : Lot enregistré localement. Il sera synchronisé au retour du serveur.");
            window.location.href = "{{ route('batches.index') }}";
        } catch (err) {
            alert("Erreur stockage local : " + err);
        }
    }
});

// 1. Installation : On met en cache les fichiers de base
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(ASSETS_TO_CACHE);
        })
    );
    self.skipWaiting();
});

// 2. Activation : Nettoyage des anciens caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
});

// 3. Stratégie réseau : Réseau d'abord, sinon Cache, sinon Page Offline
self.addEventListener('fetch', (event) => {
    // On ne traite que les requêtes GET (pas les POST de formulaires)
    if (event.request.method !== 'GET') return;

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                // Si on a le réseau, on clone la réponse dans le cache pour plus tard
                const copy = response.clone();
                caches.open(CACHE_NAME).then((cache) => {
                    cache.put(event.request, copy);
                });
                return response;
            })
            .catch(() => {
                // SI WAMP EST ÉTEINT OU RÉSEAU COUPÉ
                return caches.match(event.request).then((response) => {
                    // On renvoie le fichier caché s'il existe
                    if (response) return response;
                    
                    // Sinon, si c'est une navigation (page HTML), on montre la page offline
                    if (event.request.mode === 'navigate') {
                        return caches.match(OFFLINE_URL);
                    }
                });
            })
    );
});

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS)));
});

self.addEventListener('fetch', (e) => {
    // Stratégie : Réseau d'abord, sinon Cache
    e.respondWith(
        fetch(e.request).catch(() => caches.match(e.request))
    );
});

self.addEventListener('fetch', (event) => {
    event.respondWith(
        fetch(event.request).catch(() => {
            // Si le réseau ou le serveur (WAMP) échoue, on renvoie la page offline
            return caches.match('/offline');
        })
    );
});