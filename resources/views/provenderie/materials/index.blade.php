<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-center gap-6">
            <div class="flex items-center gap-4 text-left">
                <div class="w-14 h-14 bg-amber-500 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl rotate-3">
                    <i class="fa-solid fa-seedling text-xl"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">
                        {{ __("Matières Premières") }}
                    </h2>
                    <p class="text-[10px] font-bold text-amber-600 uppercase tracking-[0.3em] mt-2 italic leading-none">
                        {{ __("Provenderie • Inventaire & Labo") }}
                    </p>
                </div>
            </div>

            {{-- Permission C : Ajout de nouvel ingrédient --}}
            @can('provenderie.C')
            <button onclick="document.getElementById('modalAddMaterial').classList.remove('hidden')"
                class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] text-[10px] font-black uppercase italic tracking-widest shadow-2xl hover:bg-amber-500 transition-all active:scale-95">
                <i class="fa-solid fa-plus mr-2 text-amber-400"></i> {{ __("Nouvel Ingrédient") }}
            </button>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold">
            
            {{-- 📊 STATS RAPIDES --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10 text-left">
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm relative overflow-hidden group">
                    <div class="absolute -right-2 -top-2 opacity-5 text-slate-900"><i class="fa-solid fa-vault text-6xl"></i></div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 italic">{{ __("Valeur Totale Stock") }}</p>
                    <p class="text-4xl font-black tracking-tighter leading-none text-slate-900">
                        {{ number_format($materials->sum(fn($m) => $m->stock_qty * $m->unit_cost), 0, ',', ' ') }}
                        <small class="text-xs opacity-40 font-black italic">GNF</small>
                    </p>
                </div>
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm relative overflow-hidden">
                    <p class="text-[10px] font-black text-red-500 uppercase tracking-widest mb-2 italic">{{ __("Alertes Rupture") }}</p>
                    <p class="text-4xl font-black text-slate-900 tracking-tighter leading-none">{{ $materials->filter(fn($m) => $m->stock_qty <= $m->alert_threshold)->count() }}</p>
                </div>
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <p class="text-[10px] font-black text-amber-500 uppercase tracking-widest mb-2 italic">{{ __("Ingrédients Actifs") }}</p>
                    <p class="text-4xl font-black text-slate-900 tracking-tighter leading-none">{{ $materials->count() }}</p>
                </div>
            </div>

            {{-- 📋 GRILLE DES MATIÈRES --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 text-left">
                @forelse($materials as $material)
                    @php
                        $stockPercentage = ($material->alert_threshold > 0) ? ($material->stock_qty / ($material->alert_threshold * 4)) * 100 : 100;
                        $isLow = $material->stock_qty <= $material->alert_threshold;
                    @endphp
                    <div class="bg-white p-8 rounded-[3.5rem] border-2 {{ $isLow ? 'border-red-100 shadow-red-50' : 'border-slate-50 shadow-sm' }} hover:shadow-2xl transition-all relative overflow-hidden group">
                        
                        {{-- Menu Options (Permissions M et S) --}}
                        <div class="absolute top-8 right-8 flex gap-2">
                            @can('provenderie.M')
                            <button onclick="openEditModal({{ json_encode($material) }})" class="w-8 h-8 rounded-full bg-slate-50 text-slate-400 hover:bg-blue-500 hover:text-white transition-all flex items-center justify-center shadow-sm">
                                <i class="fa-solid fa-pen-to-square text-[10px]"></i>
                            </button>
                            @endcan
                            
                            @can('provenderie.S')
                            <form action="{{ route('raw-materials.destroy', $material->id) }}" method="POST" onsubmit="return confirm({{ Js::from(__('Supprimer cet ingrédient ? Cela pourrait affecter l\'historique des productions.')) }})">
                                @csrf @method('DELETE')
                                <button type="submit" class="w-8 h-8 rounded-full bg-slate-50 text-slate-400 hover:bg-red-500 hover:text-white transition-all flex items-center justify-center shadow-sm">
                                    <i class="fa-solid fa-trash text-[10px]"></i>
                                </button>
                            </form>
                            @endcan
                        </div>

                        <div class="flex justify-between items-start mb-6 pr-16">
                            <div @class([
                                'w-12 h-12 rounded-2xl flex items-center justify-center text-white shadow-lg group-hover:rotate-12 transition-transform',
                                'bg-red-500 animate-pulse' => $isLow,
                                'bg-slate-900' => !$isLow
                            ])>
                                <i class="fa-solid fa-box-open text-sm"></i>
                            </div>
                            <div class="text-right">
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1 italic leading-none">{{ __("P.M.P Actuel") }}</p>
                                <p class="text-lg font-black text-slate-800 leading-none italic">{{ number_format($material->unit_cost, 0, ',', ' ') }} <small class="text-[10px] text-blue-500">GNF/kg</small></p>
                            </div>
                        </div>

                        <h3 class="text-2xl font-black text-slate-900 uppercase italic tracking-tighter mb-1 leading-none">{{ $material->name }}</h3>
                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-6 italic leading-none">{{ __("Unité") }} : {{ strtoupper($material->unit) }}</p>

                        <div class="space-y-3">
                            <div class="flex justify-between items-end leading-none">
                                <p class="text-4xl font-black tracking-tighter italic leading-none {{ $isLow ? 'text-red-600' : 'text-slate-900' }}">{{ number_format($material->stock_qty, 1, ',', ' ') }}</p>
                                <p class="text-[10px] font-black text-slate-400 uppercase italic mb-1">{{ __("En Stock") }}</p>
                            </div>
                            <div class="w-full bg-slate-100 h-3 rounded-full overflow-hidden shadow-inner p-0.5">
                                <div class="h-full rounded-full transition-all duration-1000 {{ $isLow ? 'bg-red-500' : 'bg-amber-500' }}" style="width: {{ min($stockPercentage, 100) }}%"></div>
                            </div>
                        </div>

                        <div class="mt-8 pt-6 border-t border-slate-50 flex flex-wrap justify-between items-center gap-2">
                            {{-- Permission C : Réception (Achat) --}}
                            @can('provenderie.C')
                            <button onclick="openReorderModal({{ $material->id }}, {{ Js::from($material->name) }})"
                                class="flex-1 text-[9px] font-black text-blue-600 uppercase italic tracking-widest hover:text-slate-900 transition-colors">
                                <i class="fa-solid fa-truck-ramp-box mr-1"></i> {{ __("Réception") }}
                            </button>
                            @endcan
                            
                            {{-- Permission M : Analyse Labo --}}
                            @can('provenderie.M')
                            <button onclick="openLaboModal({{ $material->id }}, {{ Js::from($material->name) }}, {{ $material->energy_kcal ?: 0 }}, {{ $material->protein_rate ?: 0 }})"
                                class="flex-1 text-[9px] font-black text-amber-600 uppercase italic tracking-widest hover:text-slate-900 transition-colors border-l border-slate-100 pl-2">
                                <i class="fa-solid fa-flask mr-1"></i> {{ __("Labo") }}
                            </button>
                            @endcan

                            {{-- Permission S : Correction/Perte --}}
                            @can('provenderie.S')
                            <button onclick="openLossModal({{ $material->id }}, {{ Js::from($material->name) }})"
                                class="w-full mt-2 text-[9px] font-black text-red-400 uppercase italic tracking-widest hover:text-red-600 transition-colors border-t border-slate-50 pt-2">
                                <i class="fa-solid fa-right-from-bracket mr-1"></i> {{ __("Ajuster Stock (Perte/Vente)") }}
                            </button>
                            @endcan
                        </div>
                    </div>
                @empty
                    <div class="col-span-full py-20 bg-white rounded-[4rem] border-2 border-dashed border-slate-100 text-center">
                        <i class="fa-solid fa-seedling text-4xl text-slate-100 mb-4"></i>
                        <p class="text-slate-300 uppercase italic font-black text-xs">{{ __("Aucun ingrédient en catalogue") }}</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- MODALS : Identiques à vos structures originales mais avec classes CSS uniformisées --}}
    {{-- MODAL AJOUT --}}
    <div id="modalAddMaterial" class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-lg rounded-[3.5rem] shadow-2xl p-10 italic relative overflow-hidden">
            <h3 class="text-2xl font-black text-slate-900 uppercase italic tracking-tighter mb-8">{{ __("Nouveau Composant") }}</h3>
            <form action="{{ route('raw-materials.store') }}" method="POST" class="space-y-5 text-left relative">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="col-span-full">
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic">{{ __("Désignation") }}</label>
                        <input type="text" name="name" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black uppercase text-slate-800 shadow-inner italic focus:ring-2 focus:ring-amber-500">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic">{{ __("Unité") }}</label>
                        <select name="unit" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none">
                            <option value="kg">{{ __("Kilogramme (kg)") }}</option>
                            <option value="L">{{ __("Litre (L)") }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic">{{ __("Alerte (kg)") }}</label>
                        <input type="number" name="alert_threshold" value="500" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                    </div>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="flex-[2] bg-slate-900 text-white font-black py-5 rounded-2xl shadow-xl uppercase italic hover:bg-amber-600 transition-colors">{{ __("Enregistrer") }}</button>
                    <button type="button" onclick="document.getElementById('modalAddMaterial').classList.add('hidden')" class="flex-1 bg-slate-100 text-slate-400 rounded-2xl uppercase font-black text-[10px]">{{ __("Fermer") }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 📝 MODAL ÉDITION --}}
    <div id="modalEditMaterial" class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-lg rounded-[3.5rem] shadow-2xl p-10 italic relative overflow-hidden">
            {{-- Bouton Fermer (X) --}}
            <button onclick="el('modalEditMaterial').classList.add('hidden')" class="absolute top-8 right-8 text-slate-300 hover:text-slate-900 transition-colors">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
            <div class="absolute right-0 top-0 w-32 h-32 bg-blue-50 rounded-bl-full opacity-50 -mr-10 -mt-10"></div>
            <h3 class="text-2xl font-black text-slate-900 uppercase italic tracking-tighter mb-8 relative">{{ __("Modifier Ingrédient") }}</h3>

            <form id="edit_form" method="POST" class="space-y-5 text-left relative">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="col-span-full">
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic">{{ __("Désignation") }}</label>
                        <input type="text" name="name" id="edit_name" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black uppercase text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic">{{ __("Unité") }}</label>
                        <select name="unit" id="edit_unit" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none">
                            <option value="kg">kg</option>
                            <option value="L">{{ __("Litre") }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic">{{ __("Alerte (kg)") }}</label>
                        <input type="number" name="alert_threshold" id="edit_threshold" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                    </div>
                </div>

                <div class="bg-blue-50/50 p-6 rounded-[2.5rem] border border-blue-100 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[8px] font-black text-blue-400 uppercase mb-2 ml-2 italic text-center">{{ __("Qté Actuelle (kg)") }}</label>
                            <input type="number" step="0.1" min="0" placeholder="0.0" name="stock_qty" id="edit_stock_qty" required class="w-full bg-white border-2 border-blue-200 rounded-2xl p-4 font-black text-2xl text-slate-900 text-center italic shadow-lg">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-blue-600 uppercase mb-2 ml-2 text-center italic">{{ __("Coût/kg (GNF)") }}</label>
                            <input type="number" min="0" placeholder="0.0" name="unit_cost" id="edit_unit_cost" required class="w-full bg-white border-2 border-emerald-200 rounded-2xl p-4 font-black text-2xl text-emerald-600 text-center italic shadow-lg">
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic">{{ __("Énergie (kcal)") }}</label>
                        <input type="number" min="0" placeholder="0" name="energy_kcal" id="edit_energy" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic">{{ __("Protéines (%)") }}</label>
                        <input type="number" step="0.1" min="0" placeholder="0.0" name="protein_rate" id="edit_protein" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center">
                    </div>
                </div>

                <div class="flex gap-3">
                    <button type="submit" class="flex-[2] bg-slate-900 text-white font-black py-5 rounded-2xl shadow-xl uppercase italic">{{ __("Mettre à jour") }}</button>
                    <button type="button" onclick="el('modalEditMaterial').classList.add('hidden')" class="flex-1 bg-slate-100 text-slate-400 rounded-2xl uppercase font-black text-[10px]">{{ __("Fermer") }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 📉 MODAL SORTIE (PERTE / VENTE) --}}
    <div id="modalLoss" class="fixed inset-0 bg-slate-900/90 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-[3.5rem] shadow-2xl p-10 italic relative overflow-hidden text-left">
            <button onclick="el('modalLoss').classList.add('hidden')" class="absolute top-8 right-8 text-slate-300 hover:text-slate-900 transition-colors">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>

            <h3 id="loss_title" class="text-2xl font-black text-slate-900 uppercase italic tracking-tighter mb-8 leading-none">{{ __("Sortie de Stock") }}</h3>

            <form id="loss_form" method="POST" class="space-y-6">
                @csrf @method('PUT')

                <div class="bg-red-50 p-6 rounded-[2.5rem] border border-red-100 space-y-4">
                    <div>
                        <label class="block text-[10px] font-black text-red-600 uppercase mb-2 ml-2 italic">{{ __("Quantité à retirer (kg)") }}</label>
                        <input type="number" step="0.1" min="0" placeholder="0.0" name="qty" required class="w-full bg-white border-2 border-red-200 rounded-2xl p-4 font-black text-3xl text-red-600 text-center italic shadow-lg">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic">{{ __("Motif de la sortie") }}</label>
                    <select name="reason" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none">
                        <option value="perte">{{ __("PERTE / AVARIE (MOISISSURE)") }}</option>
                        <option value="vol">{{ __("ÉCART D'INVENTAIRE / VOL") }}</option>
                        <option value="vente">{{ __("VENTE DIRECTE (SANS TRANSFORMATION)") }}</option>
                        <option value="don">{{ __("DON / AUTRE") }}</option>
                    </select>
                </div>

                <div class="flex gap-3 pt-4">
                    <button type="submit" class="flex-[2] bg-red-600 text-white font-black py-5 rounded-2xl shadow-xl uppercase italic hover:bg-red-700 transition-all">{{ __("Confirmer la sortie") }}</button>
                    <button type="button" onclick="el('modalLoss').classList.add('hidden')" class="flex-1 bg-slate-100 text-slate-400 rounded-2xl uppercase font-black text-[10px]">{{ __("Annuler") }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 🔬 MODAL LABO --}}
    <div id="modalLabo" class="fixed inset-0 bg-slate-900/90 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-[3.5rem] shadow-2xl p-10 italic relative overflow-hidden text-left">
            {{-- Bouton Fermer (X) --}}
            <button onclick="el('modalLabo').classList.add('hidden')" class="absolute top-8 right-8 text-slate-300 hover:text-slate-900 transition-colors">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
            <div class="absolute left-0 top-0 w-2 h-full bg-amber-500"></div>
            <h3 id="labo_title" class="text-2xl font-black text-slate-900 uppercase italic tracking-tighter mb-8 leading-none italic">{{ __("Analyse Labo") }}</h3>
            <form id="labo_form" method="POST" class="space-y-6">
                @csrf @method('PUT')
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic">{{ __("Énergie (kcal/kg)") }}</label>
                        <input type="number" min="0" placeholder="0" name="energy_kcal" id="labo_energy" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-xl text-center italic shadow-inner">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic">{{ __("Protéines (%)") }}</label>
                        <input type="number" step="0.1" min="0" placeholder="0.0" name="protein_rate" id="labo_protein" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-xl text-center italic shadow-inner text-blue-600">
                    </div>
                </div>
                <button type="submit" class="w-full bg-slate-900 text-white font-black py-5 rounded-2xl shadow-xl italic uppercase">{{ __("Enregistrer Valeurs") }}</button>
            </form>
        </div>
    </div>

    {{-- MODAL RÉCEPTION --}}
    <div id="modalReorder" class="fixed inset-0 bg-slate-900/90 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-lg rounded-[3.5rem] shadow-2xl p-10 relative italic text-left overflow-hidden">
            <button onclick="document.getElementById('modalReorder').classList.add('hidden')" class="absolute top-8 right-8 text-slate-300 hover:text-slate-900 transition-colors">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
            <h3 id="reorder_title" class="text-2xl font-black text-slate-900 uppercase italic tracking-tighter mb-8 leading-none">{{ __("Réception Magasin") }}</h3>
            <form id="reorder_form" method="POST" class="space-y-6">
                @csrf @method('PUT')
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic tracking-widest">{{ __("Fournisseur") }}</label>
                    <select name="provider_id" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none">
                        <option value="">{{ __("-- CHOISIR LE FOURNISSEUR --") }}</option>
                        @foreach($providers as $p) <option value="{{ $p->id }}">{{ strtoupper($p->name) }}</option> @endforeach
                    </select>
                </div>
                <div class="bg-blue-50 p-6 rounded-[2.5rem] border border-blue-100 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[8px] font-black text-blue-400 uppercase mb-2 ml-2 italic">{{ __("Nb sacs") }}</label>
                            <input type="number" id="nb_sacs" min="0" placeholder="0" oninput="convertSacsToKg()" class="w-full bg-white border-none rounded-xl p-3 font-black text-lg text-slate-800 shadow-sm text-center italic">
                        </div>
                        <div>
                            <label class="block text-[8px] font-black text-blue-400 uppercase mb-2 ml-2 italic">{{ __("Format") }}</label>
                            <select id="poids_sac" onchange="convertSacsToKg()" class="w-full bg-white border-none rounded-xl p-3 font-black text-sm text-slate-800 shadow-sm italic appearance-none text-center">
                                <option value="50">{{ __("50 KG") }}</option>
                                <option value="25">{{ __("25 KG") }}</option>
                            </select>
                        </div>
                    </div>
                    <input type="number" step="0.1" min="0" placeholder="{{ __('Total kg') }}" name="added_qty" id="final_qty" required oninput="calculateUnitCost()" class="w-full bg-white border-2 border-blue-200 rounded-2xl p-4 font-black text-3xl text-blue-600 text-center italic shadow-lg">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic tracking-widest">{{ __("Montant Facturé (GNF)") }}</label>
                    <input type="number" min="0" placeholder="0" name="purchase_price" id="total_purchase_price" required oninput="calculateUnitCost()" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-2xl text-slate-800 shadow-inner text-center italic text-blue-600">
                    <p class="text-center mt-3 text-[9px] text-slate-400 uppercase italic font-black">{{ __("Coût unitaire estimé") }} : <span id="unit_cost_display" class="text-blue-500">0</span> GNF/kg</p>
                </div>
                <button type="submit" class="w-full bg-slate-900 text-white font-black py-5 rounded-2xl shadow-xl uppercase italic hover:bg-blue-600 transition-colors">{{ __("Valider l'entrée") }}</button>
            </form>
        </div>
    </div>

    <script>
        function el(id) { return document.getElementById(id); }

        function openEditModal(material) {
            el('edit_form').action = `/provenderie/materials/${material.id}`;
            el('edit_name').value = material.name;
            el('edit_unit').value = material.unit;
            el('edit_threshold').value = material.alert_threshold;
            el('edit_stock_qty').value = material.stock_qty;
            el('edit_unit_cost').value = material.unit_cost;
            el('edit_energy').value = material.energy_kcal;
            el('edit_protein').value = material.protein_rate;
            el('modalEditMaterial').classList.remove('hidden');
        }

        function openReorderModal(id, name) {
            el('reorder_title').innerText = `📦 ${@json(__('RÉCEPTION'))} : ${name}`;
            el('reorder_form').action = `/provenderie/materials/${id}/add-stock`;
            el('modalReorder').classList.remove('hidden');
        }

        function openLossModal(id, name) {
            el('loss_title').innerText = `📉 ${@json(__('AJUSTEMENT'))} : ${name}`;
            el('loss_form').action = `/provenderie/materials/${id}/remove-stock`;
            el('modalLoss').classList.remove('hidden');
        }

        function openLaboModal(id, name, energy, protein) {
            el('labo_title').innerText = `🔬 ${@json(__('ANALYSE'))} : ${name}`;
            el('labo_form').action = `/provenderie/materials/${id}/nutrition`;
            el('labo_energy').value = energy;
            el('labo_protein').value = protein;
            el('modalLabo').classList.remove('hidden');
        }

        function convertSacsToKg() {
            const nb = parseFloat(el('nb_sacs').value) || 0;
            const unit = parseFloat(el('poids_sac').value) || 1;
            el('final_qty').value = (nb * unit).toFixed(1);
            calculateUnitCost();
        }

        function calculateUnitCost() {
            const total = parseFloat(el('total_purchase_price').value) || 0;
            const qty = parseFloat(el('final_qty').value) || 0;
            const display = el('unit_cost_display');
            display.innerText = (qty > 0) ? Math.round(total / qty).toLocaleString() : @json(__("0"));
        }
    </script>
</x-app-layout>