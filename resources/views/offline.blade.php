<x-guest-layout>
    <div class="flex flex-col items-center justify-center min-h-screen bg-slate-50 italic font-black text-center p-10">
        <i class="fa-solid fa-plane-slash text-6xl text-slate-300 mb-6"></i>
        <h1 class="text-2xl text-slate-800 uppercase tracking-tighter">{{ __("Mode Terrain Activé") }}</h1>
        <p class="text-slate-400 text-xs mt-4 uppercase">{{ __("Le serveur central (WAMP) est injoignable.") }}</p>
        <div class="mt-8 p-6 bg-white rounded-[2rem] shadow-xl border border-slate-100">
            <p class="text-[10px] text-blue-600 uppercase">{{ __("Vous pouvez toujours consulter vos données synchronisées et préparer vos saisies.") }}</p>
        </div>
    </div>
</x-guest-layout>