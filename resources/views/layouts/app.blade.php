<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ setting('general.company_name', 'AviSmart') }}</title>

        @include('partials.pwa-head')

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        
        <style>
            html { scroll-behavior: smooth; }
            .no-scrollbar::-webkit-scrollbar { display: none; }
            @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
            .animate-slide-in { animation: slideIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
            
            /* Indicateur Offline */
            .offline-mode { filter: grayscale(0.2); border-top: 4px solid #f43f5e; }
            #connectivity-status.online { background: #10b981; }
            #connectivity-status.offline { background: #f43f5e; animation: pulse 2s infinite; }
            @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
            [x-cloak] { display: none !important; }
        </style>
    </head>
    <body class="font-sans antialiased bg-gray-50 italic font-bold text-slate-700 transition-all duration-500">
        
        {{-- 🛰️ INDICATEUR DE SYNCHRONISATION/CONNEXION --}}
        <div id="sync-indicator" class="fixed bottom-6 right-6 z-[100] flex items-center gap-3 bg-slate-900 text-white px-5 py-3 rounded-2xl shadow-2xl transform translate-y-24 transition-transform duration-500">
            <div id="connectivity-status" class="w-2 h-2 rounded-full online"></div>
            <p id="sync-text" class="text-[9px] font-black uppercase tracking-widest italic leading-none">{{ __("Système Synchronisé") }}</p>
        </div>

        <div class="min-h-screen">
            @include('layouts.navigation')

            {{-- NOTIFICATION D'ERREUR --}}
            @if(session('error'))
                <div class="fixed top-24 right-6 z-[100] animate-slide-in" x-data x-init="setTimeout(() => $el.remove(), 8000)">
                    <div class="bg-slate-900 border-l-4 border-rose-600 p-5 rounded-2xl shadow-2xl flex items-center gap-4 min-w-[300px]">
                        <div class="w-10 h-10 bg-rose-600/20 rounded-xl flex items-center justify-center text-rose-500">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="text-left">
                            <p class="text-[8px] font-black text-rose-500 uppercase tracking-[0.2em] mb-1">{{ __("Alerte Système") }}</p>
                            <p class="text-white text-[10px] font-black uppercase tracking-widest leading-none">{{ session('error') }}</p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- NOTIFICATION DE SUCCÈS (globale — certaines vues l'affichent aussi localement) --}}
            @if(session('success'))
                <div class="fixed top-24 right-6 z-[100] animate-slide-in" x-data x-init="setTimeout(() => $el.remove(), 6000)">
                    <div class="bg-slate-900 border-l-4 border-emerald-500 p-5 rounded-2xl shadow-2xl flex items-center gap-4 min-w-[300px]">
                        <div class="w-10 h-10 bg-emerald-500/20 rounded-xl flex items-center justify-center text-emerald-400">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="text-left">
                            <p class="text-[8px] font-black text-emerald-400 uppercase tracking-[0.2em] mb-1">{{ __("Opération Réussie") }}</p>
                            <p class="text-white text-[10px] font-black uppercase tracking-widest leading-none">{{ session('success') }}</p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ERREURS DE VALIDATION (globales — ex. doublon, champ invalide) --}}
            @if($errors->any())
                <div class="fixed top-24 right-6 z-[100] animate-slide-in" x-data x-init="setTimeout(() => $el.remove(), 8000)">
                    <div class="bg-slate-900 border-l-4 border-amber-500 p-5 rounded-2xl shadow-2xl flex items-start gap-4 min-w-[300px] max-w-md">
                        <div class="w-10 h-10 shrink-0 bg-amber-500/20 rounded-xl flex items-center justify-center text-amber-400">
                            <i class="fas fa-triangle-exclamation"></i>
                        </div>
                        <div class="text-left">
                            <p class="text-[8px] font-black text-amber-400 uppercase tracking-[0.2em] mb-1">{{ __("Saisie Refusée") }}</p>
                            <ul class="space-y-1">
                                @foreach($errors->all() as $message)
                                    <li class="text-white text-[10px] font-black uppercase tracking-widest leading-tight">{{ $message }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            @endif

            {{-- HEADER STICKY --}}
            @isset($header)
                <header class="sticky top-16 z-40 bg-white/80 backdrop-blur-md border-b border-slate-200/50 shadow-sm transition-all duration-300">
                    <div class="max-w-7xl mx-auto py-5 px-4 sm:px-6 lg:px-8 flex justify-between items-center">
                        <div class="flex-1">{{ $header }}</div>
                        
                        {{-- 🔔 CLOCHE DE NOTIFICATION INDUSTRIELLE (OPTIMISÉE) --}}
                        @php
                            // On charge les notifications une seule fois pour éviter de multiplier les requêtes SQL
                            $unreadNotifications = auth()->check() ? auth()->user()->unreadNotifications : collect();
                            $unreadCount = $unreadNotifications->count();
                        @endphp

                        <x-menu align="right" width="w-80" panel="bg-white rounded-[2.5rem] shadow-[0_20px_50px_rgba(0,0,0,0.1)] border border-slate-100 overflow-hidden" class="ml-4">
                            <x-slot name="trigger">
                                <span class="relative block p-2 bg-slate-50 rounded-xl hover:bg-slate-100 transition-colors">
                                    <i class="fa-solid fa-bell text-slate-400 text-lg"></i>

                                    @if(!config('app.database_down') && $unreadCount > 0)
                                        <span class="absolute -top-1 -right-1 w-5 h-5 bg-rose-600 text-white text-[9px] font-black rounded-lg flex items-center justify-center shadow-lg animate-bounce">
                                            {{ $unreadCount }}
                                        </span>
                                    @endif
                                </span>
                            </x-slot>
                                <div class="p-5 bg-slate-900 text-white flex justify-between items-center">
                                    <p class="text-[10px] font-black uppercase italic tracking-widest">
                                        {{ config('app.database_down') ? __("Flux Temporairement Indisponible") : __("Flux de Production") }}
                                    </p>
                                </div>
                                <div class="max-h-80 overflow-y-auto no-scrollbar">
                                    @if(config('app.database_down'))
                                        <div class="p-10 text-center">
                                            <i class="fa-solid fa-cloud-slash text-slate-300 text-2xl mb-3"></i>
                                            <p class="text-[9px] font-black text-slate-400 uppercase italic">{{ __("Les alertes seront synchronisées au retour du serveur.") }}</p>
                                        </div>
                                    @else
                                        @forelse($unreadNotifications as $notification)
                                            <a href="{{ route('notifications.read', $notification->id) }}" class="block p-5 border-b border-slate-50 hover:bg-slate-50 transition-colors relative no-underline">
                                                <div class="flex items-start gap-3">
                                                    <div @class([
                                                        'w-2 h-2 rounded-full mt-1',
                                                        'bg-rose-600' => ($notification->data['severity'] ?? '') === 'critique',
                                                        'bg-amber-500' => ($notification->data['severity'] ?? '') === 'attention',
                                                        'bg-blue-500' => ! in_array($notification->data['severity'] ?? '', ['critique', 'attention']),
                                                    ])></div>
                                                    <div class="text-left">
                                                        <p class="text-[10px] font-black text-slate-800 uppercase italic mb-1">{{ $notification->data['title'] ?? __("Alerte") }}</p>
                                                        <p class="text-[9px] text-slate-400 font-bold uppercase leading-tight">{{ $notification->data['message'] ?? '' }}</p>
                                                    </div>
                                                </div>
                                            </a>
                                        @empty
                                            <div class="p-10 text-center">
                                                <i class="fa-solid fa-check-circle text-emerald-500 text-2xl mb-3"></i>
                                                <p class="text-[10px] font-black text-slate-400 uppercase italic">{{ __("Aucune alerte en attente") }}</p>
                                            </div>
                                        @endforelse
                                    @endif
                                </div>
                                @if(!config('app.database_down') && $unreadCount > 0)
                                    <form method="POST" action="{{ route('notifications.read-all') }}" class="p-3 bg-slate-50 border-t border-slate-100">
                                        @csrf
                                        <button type="submit" class="w-full text-center text-[9px] font-black uppercase tracking-widest text-slate-500 hover:text-slate-900 transition-colors py-2 border-none bg-transparent cursor-pointer">
                                            <i class="fa-solid fa-check-double mr-1"></i> {{ __("Tout marquer comme lu") }}
                                        </button>
                                    </form>
                                @endif
                        </x-menu>
                    </div>
                </header>
            @endisset

            {{-- CONTENU PRINCIPAL --}}
            <div class="relative">
                <main class="py-8">
                    {{ $slot }}
                </main>
            </div>
        </div>

        <script>
            // --- 🛰️ LOGIQUE OFFLINE-FIRST SÉCURISÉE ---
            const syncIndicator = document.getElementById('sync-indicator');
            const connectivityStatus = document.getElementById('connectivity-status');
            const syncText = document.getElementById('sync-text');

            function updateConnectivity() {
                try {
                    if (navigator.onLine) {
                        document.body.classList.remove('offline-mode');
                        if (connectivityStatus) connectivityStatus.className = "w-2 h-2 rounded-full online";
                        if (syncText) syncText.innerText = @json(__("Système en ligne"));
                        if (syncIndicator) {
                            syncIndicator.classList.remove('translate-y-24');
                            setTimeout(() => syncIndicator.classList.add('translate-y-24'), 3000);
                        }
                    } else {
                        document.body.classList.add('offline-mode');
                        if (connectivityStatus) connectivityStatus.className = "w-2 h-2 rounded-full offline";
                        if (syncText) syncText.innerText = @json(__("Mode Hors-Ligne Actif"));
                        if (syncIndicator) syncIndicator.classList.remove('translate-y-24');
                    }
                } catch (err) {
                    console.error("Erreur indicateur connectivité :", err);
                }
            }

            window.addEventListener('online', updateConnectivity);
            window.addEventListener('offline', updateConnectivity);

            // --- 🛠️ MOTEUR DE RENDU ISOLE ---
            async function autoFillOfflineData() {
                // SÉCURITÉ VITE.JS : S'assurer que db a bien été exposé à l'objet window dans resources/js/app.js
                // ex: window.db = new Dexie("AviSmartDB");
                if (typeof window.db === 'undefined') {
                    console.warn("⚠️ Base de données locale (Dexie) non détectée dans l'espace global.");
                    return;
                }

                try {
                    const batchContainer = document.getElementById('batchContainer');
                    const buildingContainer = document.getElementById('buildingContainer');

                    // 1. RENDU DES BÂTIMENTS
                    if (buildingContainer && buildingContainer.children.length === 0) {
                        const data = await window.db.buildings.toArray();
                        if (data && data.length > 0) {
                            buildingContainer.innerHTML = data.map(b => `
                                <div class="bg-white rounded-[3rem] border-2 border-dashed border-slate-200 p-8 opacity-75">
                                    <div class="flex justify-between mb-4">
                                        <span class="text-[8px] font-black uppercase px-2 py-1 bg-slate-100 rounded">${b.type || @json(__("BÂTIMENT"))}</span>
                                        <span class="text-[8px] font-black uppercase px-2 py-1 bg-blue-50 text-blue-600 rounded">${b.status}</span>
                                    </div>
                                    <h3 class="text-2xl font-black text-slate-800 uppercase tracking-tighter">${b.name}</h3>
                                    <p class="text-[10px] mt-4 font-black text-slate-400 uppercase italic">{{ __("Données locales (Lecture seule)") }}</p>
                                </div>
                            `).join('');
                        }
                    }

                    // 2. RENDU DES LOTS
                    if (batchContainer && batchContainer.children.length === 0) {
                        const data = await window.db.batches.toArray();
                        if (data && data.length > 0) {
                            batchContainer.innerHTML = data.map(batch => `
                                <div class="flex items-center justify-between bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                                    <div class="flex items-center gap-5">
                                        <div class="w-14 h-14 rounded-[1.5rem] bg-slate-900 text-white flex items-center justify-center">
                                            <i class="fa-solid fa-plane-slash"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-black text-slate-900 text-xl uppercase italic tracking-tighter">${batch.code}</h4>
                                            <p class="text-[9px] font-black text-blue-600 uppercase mt-1 italic tracking-widest">OFFLINE</p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-2xl font-black text-slate-900 italic leading-none">${batch.current_quantity}</p>
                                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Sujets") }}</p>
                                    </div>
                                </div>
                            `).join('');
                        }
                    }
                } catch (err) {
                    console.error("Échec du rendu des données hors-ligne :", err);
                }
            }

            // --- 🛫 INITIALISATION ---
            window.addEventListener('DOMContentLoaded', () => {
                try {
                    updateConnectivity();
                } catch(e) {}

                // Délai léger pour laisser Alpine.js initialiser le DOM en priorité
                setTimeout(async () => {
                    await autoFillOfflineData();

                    // Rafraîchit le miroir local (référentiels + lots) si en ligne,
                    // pour que le mode terrain dispose de données récentes.
                    if (navigator.onLine && typeof window.refreshLocalData === 'function') {
                        window.refreshLocalData();
                    }

                    if ('serviceWorker' in navigator) {
                        navigator.serviceWorker.register('/sw.js').then((reg) => {

                            function offerUpdate(waiting) {
                                const toast = document.getElementById('sw-update-toast');
                                if (!toast || toast._armed) return;
                                toast._armed = true;
                                toast.classList.remove('translate-y-24', 'opacity-0');
                                document.getElementById('sw-reload-btn')?.addEventListener('click', () => {
                                    waiting.postMessage('skipWaiting');
                                    toast.remove();
                                }, { once: true });
                            }

                            // Mise à jour déjà en attente (onglet rouvert après update)
                            if (reg.waiting) offerUpdate(reg.waiting);

                            // Mise à jour téléchargée en arrière-plan
                            reg.addEventListener('updatefound', () => {
                                const sw = reg.installing;
                                if (!sw) return;
                                sw.addEventListener('statechange', () => {
                                    if (sw.state === 'installed' && navigator.serviceWorker.controller) {
                                        offerUpdate(sw);
                                    }
                                });
                            });

                        }).catch(() => console.warn('SW passif'));

                        // Rechargement propre quand le nouveau SW prend le contrôle —
                        // une seule fois (anti-boucle), et uniquement suite à une mise
                        // à jour acceptée par l'utilisateur (le SW ne s'auto-active plus).
                        let swRefreshing = false;
                        navigator.serviceWorker.addEventListener('controllerchange', () => {
                            if (swRefreshing) return;
                            swRefreshing = true;
                            window.location.reload();
                        });
                    }
                }, 150);
            });
        </script>

        {{-- Toast mise à jour SW (caché par défaut, animé à l'apparition) --}}
        <div id="sw-update-toast"
             class="fixed bottom-5 right-5 z-50 flex items-center gap-3 bg-slate-900 text-white rounded-2xl shadow-2xl px-5 py-4
                    translate-y-24 opacity-0 transition-all duration-500 ease-out"
             aria-live="polite">
            <i class="fa-solid fa-rotate text-blue-400 text-base"></i>
            <span class="text-[11px] font-black uppercase tracking-widest">{{ __("Mise à jour disponible") }}</span>
            <button id="sw-reload-btn"
                    class="ml-1 px-4 py-2 bg-blue-500 hover:bg-blue-400 rounded-xl text-[10px] font-black uppercase tracking-wider transition-colors">
                {{ __("Recharger") }}
            </button>
            <button onclick="this.closest('#sw-update-toast').classList.add('translate-y-24','opacity-0')"
                    class="text-slate-500 hover:text-white transition-colors ml-1 text-sm">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </body>
</html>