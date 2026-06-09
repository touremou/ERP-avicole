<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4 text-left">
            <a href="{{ route('planning.show', $plan) }}" class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 text-slate-500 hover:text-slate-800 rounded-xl transition-all shadow-sm group no-underline">
                <i class="fas fa-chevron-left group-hover:-translate-x-1 transition-transform text-xs"></i>
                <span class="text-[10px] font-black uppercase italic tracking-widest">Retour</span>
            </a>
            <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                🐣 Activer la bande — {{ $plan->building->name }}
            </h2>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold text-slate-700 text-left">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">

            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl">
                    <ul class="text-[10px] list-disc ml-8 uppercase font-black tracking-tight">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            {{-- RAPPEL PLANIFICATION --}}
            <div class="bg-indigo-50 p-8 rounded-[3rem] border border-indigo-200 mb-8">
                <h3 class="text-[10px] font-black text-indigo-600 uppercase tracking-widest mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-calendar-check"></i> Planification d'origine
                </h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-white p-4 rounded-2xl text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase">Bâtiment</p>
                        <p class="text-sm font-black text-slate-900">{{ $plan->building->name }}</p>
                    </div>
                    <div class="bg-white p-4 rounded-2xl text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase">Type</p>
                        <p class="text-sm font-black text-slate-900 uppercase">{{ $plan->batch_type }}</p>
                    </div>
                    <div class="bg-white p-4 rounded-2xl text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase">Quantité prévue</p>
                        <p class="text-sm font-black text-emerald-600">{{ number_format($plan->planned_quantity) }}</p>
                    </div>
                    <div class="bg-white p-4 rounded-2xl text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase">Fournisseur</p>
                        <p class="text-sm font-black text-slate-900">{{ $plan->provider->name ?? '—' }}</p>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('planning.status', $plan) }}">
                @csrf @method('PUT')
                <input type="hidden" name="status" value="en_cours">

                <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 mb-8">
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-8 italic">Données d'arrivée réelles</h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div>
                            <label class="block text-[10px] font-black text-emerald-500 uppercase mb-2 ml-1 italic">Quantité vivante reçue *</label>
                            <input type="number" name="qty_alive" value="{{ $plan->planned_quantity }}" min="1" required
                                   class="w-full p-5 bg-slate-50 rounded-3xl border-none font-black text-4xl text-slate-800 shadow-inner italic appearance-none leading-none text-center">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-red-500 uppercase mb-2 ml-1 italic">Mortalité transport</label>
                            <input type="number" name="qty_dead" value="0" min="0"
                                   class="w-full p-5 bg-slate-50 rounded-3xl border-none font-black text-4xl text-slate-800 shadow-inner italic appearance-none leading-none text-center">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-blue-600 uppercase mb-2 ml-1 italic">Prix unitaire (GNF)</label>
                            <input type="number" name="buy_price_per_unit" value="0" min="0"
                                   class="w-full p-5 bg-slate-50 rounded-2xl border-none font-black text-2xl text-blue-700 shadow-inner italic leading-none text-center">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic">Responsable du lot *</label>
                            <select name="employee_id" required
                                    class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic outline-none">
                                <option value="">-- Sélectionner --</option>
                                @foreach($employees as $e)
                                    <option value="{{ $e->id }}">{{ $e->first_name }} {{ $e->last_name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="bg-emerald-50 p-6 rounded-[2.5rem] border border-emerald-200 mb-8 flex items-start gap-4">
                    <div class="w-12 h-12 bg-emerald-500 rounded-xl flex items-center justify-center text-white shrink-0 text-lg"><i class="fa-solid fa-magic-wand-sparkles"></i></div>
                    <div>
                        <p class="text-[10px] font-black text-emerald-700 uppercase tracking-widest mb-1">Création automatique du lot</p>
                        <p class="text-[9px] text-emerald-600 normal-case">En validant, un lot réel sera automatiquement créé dans le bâtiment {{ $plan->building->name }} avec les données saisies. Le protocole et la souche seront repris de la planification.</p>
                    </div>
                </div>

                <button type="submit" class="w-full bg-emerald-500 text-white font-black py-8 rounded-[2rem] hover:bg-emerald-600 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl border-none cursor-pointer">
                    <i class="fas fa-rocket mr-2"></i> Activer la bande & Créer le lot
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
