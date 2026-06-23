<x-guest-layout>
    <div class="flex flex-col items-center justify-center min-h-screen bg-slate-50 italic font-black text-center p-10">
        <i class="fa-solid fa-plane-slash text-6xl text-slate-300 mb-6"></i>
        <h1 class="text-2xl text-slate-800 uppercase tracking-tighter">{{ __("Mode Terrain Activé") }}</h1>
        <p class="text-slate-400 text-xs mt-4 uppercase">{{ __("Le serveur central est momentanément injoignable.") }}</p>

        <div class="mt-8 p-6 bg-white rounded-[2rem] shadow-xl border border-slate-100 max-w-md">
            <p class="text-[10px] text-blue-600 uppercase">{{ __("Vous pouvez toujours consulter vos données synchronisées et préparer vos saisies.") }}</p>
            <p id="reconnectStatus" class="text-[10px] text-slate-400 uppercase mt-4 flex items-center justify-center gap-2">
                <span class="inline-block w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span>
                {{ __("Tentative de reconnexion automatique…") }}
            </p>
            <button id="retryBtn" type="button"
                class="mt-5 px-6 py-3 bg-slate-900 text-white rounded-2xl text-[10px] uppercase tracking-widest hover:bg-slate-700 transition-colors">
                <i class="fa-solid fa-rotate-right mr-2"></i>{{ __("Réessayer maintenant") }}
            </button>
        </div>
    </div>

    <script>
        (function () {
            const status = document.getElementById('reconnectStatus');
            let checking = false;

            // Ping léger du point de santé Laravel (/up, sans authentification).
            // Dès que le serveur répond, on revient à l'accueil.
            async function checkServer() {
                if (checking) return;
                checking = true;
                try {
                    const res = await fetch('/up?_=' + Date.now(), { method: 'HEAD', cache: 'no-store' });
                    if (res && res.ok) {
                        if (status) {
                            status.innerHTML = '<span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span> {{ __("Serveur joignable — redirection…") }}';
                        }
                        window.location.replace('/');
                        return;
                    }
                } catch (e) {
                    /* serveur toujours injoignable : on retente */
                } finally {
                    checking = false;
                }
            }

            document.getElementById('retryBtn')?.addEventListener('click', checkServer);
            window.addEventListener('online', checkServer);
            setInterval(checkServer, 5000);
            checkServer();
        })();
    </script>
</x-guest-layout>
