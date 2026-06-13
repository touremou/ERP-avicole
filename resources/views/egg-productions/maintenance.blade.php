<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between text-left">
            <div class="flex items-center gap-4">
                <a href="{{ route('egg-productions.index') }}" class="group text-slate-400 hover:text-slate-800 transition no-underline">
                    <i class="fas fa-arrow-left group-hover:-translate-x-1 transition-transform text-xl"></i>
                </a>
                <div>
                    <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none m-0">
                        {{ __("🛠️ Maintenance & Inventaire Magasin") }}
                    </h2>
                    <p class="text-[9px] font-black text-red-500 uppercase tracking-[0.2em] mt-1 italic leading-none animate-pulse m-0">
                        {{ __("Accès Niveau : Super-Admin (S)") }}
                    </p>
                </div>
            </div>
            <div class="hidden md:block">
                <span class="px-4 py-2 bg-slate-100 text-slate-400 rounded-xl text-[8px] font-black uppercase italic tracking-widest border border-slate-200">{{ __("Session d'Audit") }} : {{ now()->format('d/m/Y') }}</span>
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            
            @can('production.S')
            <div class="bg-white p-10 rounded-[3rem] shadow-2xl border border-red-50 relative overflow-hidden">
                
                <div class="mb-10 p-8 bg-red-600 rounded-[2.5rem] text-white shadow-xl shadow-red-200 relative overflow-hidden text-left italic">
                    <div class="relative z-10">
                        <h4 class="text-sm font-black uppercase tracking-widest mb-2 leading-none m-0">{{ __("⚠️ Procédure de Rebasage de Stock") }}</h4>
                        <p class="text-[10px] font-bold opacity-90 leading-relaxed uppercase tracking-tight m-0 mt-2">
                            {{ __("L'enregistrement de ce formulaire **écrasera définitivement** les quantités calculées par l'ERP pour les remplacer par vos valeurs physiques.") }}
                            {{ __("Cette opération sera logguée comme \"Ajustement Critique\" dans le journal de maintenance.") }}
                        </p>
                    </div>
                    <i class="fa-solid fa-triangle-exclamation absolute -right-6 -bottom-6 text-white/10 text-9xl rotate-12"></i>
                </div>

                <form action="{{ route('stocks.rebase') }}" method="POST" class="space-y-6">
                    @csrf
                    <div class="divide-y divide-slate-100">
                        @foreach($stocks as $s)
                        <div class="py-6 flex items-center justify-between group transition-all hover:bg-slate-50/50 px-4 rounded-2xl">
                            <div class="text-left">
                                <p class="text-base font-black text-slate-900 uppercase italic leading-none tracking-tighter m-0">{{ $s->item_name }}</p>
                                <div class="flex items-center gap-3 mt-2">
                                    <span class="text-[9px] text-slate-400 uppercase font-black italic bg-slate-100 px-2 py-0.5 rounded">{{ __("Actuel") }} : {{ number_format($s->current_quantity, 2) }}</span>
                                    <i class="fa-solid fa-right-long text-[8px] text-slate-300"></i>
                                    <span class="text-[9px] text-red-500 uppercase font-black italic">{{ __("Nouvelle Valeur") }}</span>
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-4">
                                <div class="relative">
                                    <input type="number" min="0" step="0.001" name="stocks[{{ $s->id }}]" 
                                           value="{{ old('stocks.'.$s->id, $s->current_quantity) }}"
                                           required
                                           class="w-36 bg-slate-50 border-none rounded-2xl p-4 font-black text-right text-2xl text-slate-900 focus:ring-4 focus:ring-red-500/10 shadow-inner transition-all italic outline-none">
                                </div>
                                <span class="text-[10px] font-black text-slate-400 w-12 uppercase italic tracking-tighter">{{ $s->unit }}</span>
                            </div>
                        </div>
                        @endforeach
                    </div>

                    <div class="pt-10 space-y-4">
                        <button type="submit" 
                                onclick="return confirm(@json(__('🚨 ACTION CRITIQUE : Confirmez-vous l\'alignement du stock système sur le stock physique ?')))"
                                class="w-full bg-slate-900 text-white py-8 rounded-[3rem] font-black uppercase italic shadow-2xl hover:bg-red-600 active:scale-95 transition-all flex items-center justify-center gap-4 group border-none cursor-pointer">
                            <span class="tracking-[0.2em] text-xs">{{ __("Écraser & Synchroniser l'Inventaire") }}</span>
                            <i class="fa-solid fa-rotate animate-spin-slow group-hover:rotate-180 transition-transform"></i>
                        </button>
                        
                        <a href="{{ route('egg-productions.index') }}" class="block text-center text-[10px] text-slate-400 uppercase tracking-widest hover:text-slate-800 transition-colors italic no-underline font-black">
                            <i class="fa-solid fa-chevron-left mr-1"></i> {{ __("Abandonner la maintenance") }}
                        </a>
                    </div>
                </form>
            </div>
            @else
            <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-2xl text-center italic">
                <div class="w-24 h-24 bg-red-50 text-red-500 rounded-[2.5rem] flex items-center justify-center mx-auto mb-8 rotate-3">
                    <i class="fa-solid fa-shield-halved text-4xl"></i>
                </div>
                <h3 class="text-2xl font-black text-slate-900 uppercase italic mb-4 tracking-tighter leading-none">{{ __("Sécurité Système Violée") }}</h3>
                <p class="text-slate-400 text-[11px] font-black uppercase tracking-widest italic max-w-sm mx-auto leading-relaxed">
                    {{ __("Cette interface de rebasage est verrouillée. Seul un profil disposant de la permission **Maintenance (S)** peut modifier les registres physiques de stock.") }}
                </p>
                <div class="mt-10 pt-10 border-t border-slate-50">
                    <a href="{{ route('egg-productions.index') }}" class="inline-block px-12 py-5 bg-slate-900 text-white rounded-3xl text-[10px] font-black uppercase italic no-underline hover:bg-emerald-500 transition-all shadow-xl">
                        {{ __("Retour au Dashboard Securisé") }}
                    </a>
                </div>
            </div>
            @endcan

        </div>
    </div>
</x-app-layout>