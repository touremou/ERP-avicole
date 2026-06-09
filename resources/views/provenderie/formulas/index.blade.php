<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-center gap-6">
            <div class="flex items-center gap-4 text-left">
                <div class="w-14 h-14 bg-blue-600 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl rotate-3">
                    <i class="fa-solid fa-flask-vial text-xl"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">
                        Bibliothèque des Formules
                    </h2>
                    <p class="text-[10px] font-bold text-blue-500 uppercase tracking-[0.3em] mt-2 italic leading-none">
                        Provenderie • Recettes & Référentiels Normés
                    </p>
                </div>
            </div>

            <div class="flex gap-4">
                {{-- Permission L : Consultation du référentiel --}}
                @can('provenderie.L')
                <button onclick="document.getElementById('modalNormes').classList.remove('hidden')" 
                    class="bg-white border-2 border-slate-100 text-slate-400 px-6 py-4 rounded-[2rem] text-[10px] font-black uppercase italic tracking-widest hover:border-blue-200 hover:text-blue-500 transition-all shadow-sm">
                    <i class="fa-solid fa-book-open mr-2"></i> Référentiel Normé
                </button>
                @endcan

                {{-- Permission C : Création de nouvelle recette --}}
                @can('provenderie.C')
                <a href="{{ route('formulas.create') }}" 
                    class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] text-[10px] font-black uppercase italic tracking-widest shadow-2xl hover:bg-blue-600 transition-all active:scale-95 no-underline flex items-center">
                    <i class="fa-solid fa-plus mr-2 text-blue-400"></i> Nouvelle Recette
                </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">
            
            {{-- 📜 SECTION RÉFÉRENTIEL RAPIDE (Aperçu des cibles de prix) --}}
            <div class="mb-12 overflow-x-auto pb-4 custom-scrollbar">
                <div class="flex gap-4 min-w-max">
                    @forelse($norms as $norm)
                    <div class="bg-blue-50/50 border border-blue-100 p-4 rounded-3xl flex items-center gap-4 shadow-sm group hover:bg-white transition-all">
                        <div class="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center text-white text-xs shadow-lg group-hover:rotate-6 transition-transform">
                            <i class="fa-solid fa-check-double"></i>
                        </div>
                        <div class="text-left">
                            <p class="text-[8px] font-black text-blue-400 uppercase leading-none mb-1">{{ $norm->name }}</p>
                            <p class="text-[10px] font-black text-slate-700 uppercase italic">Cible : {{ number_format($norm->target_price_kg, 0, ',', ' ') }} GNF/kg</p>
                        </div>
                    </div>
                    @empty
                        <p class="text-[10px] text-slate-300 uppercase italic font-black">Aucune norme active dans le référentiel...</p>
                    @endforelse
                </div>
            </div>

            {{-- 📋 GRILLE DES FORMULES AVEC ANALYSE NUTRITIONNELLE --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                @forelse($formulas as $formula)
                    @php
                        // 1. Calcul du coût théorique basé sur le stock MP actuel
                        $theoreticalCost = $formula->items->sum(fn($item) => ($item->percentage / 100) * ($item->rawMaterial->unit_cost ?? 0));
                        
                        // 2. Analyse Nutritionnelle Pondérée (Réel calculé par le mélange)
                        $reelEM = $formula->items->sum(fn($item) => ($item->percentage / 100) * ($item->rawMaterial->energy_kcal ?? 0));
                        $reelPB = $formula->items->sum(fn($item) => ($item->percentage / 100) * ($item->rawMaterial->protein_rate ?? 0));

                        // 3. Matching avec le référentiel normé
                        $matchedNorm = $norms->where('animal_type', $formula->target_type)->first();
                        $targetPrice = $matchedNorm->target_price_kg ?? 4500;
                        $targetEM = $matchedNorm->target_em ?? 3000;
                        $targetPB = $matchedNorm->target_pb ?? 21;
                        $diffPrice = $theoreticalCost - $targetPrice;
                    @endphp
                    
                    <div class="bg-white rounded-[3.5rem] border border-slate-100 shadow-sm hover:shadow-2xl transition-all group relative overflow-hidden flex flex-col">
                        {{-- Indicateur de Performance Prix --}}
                        <div @class([
                            'absolute top-0 left-0 w-2 h-full transition-all',
                            'bg-emerald-500' => $diffPrice <= 0,
                            'bg-amber-400' => $diffPrice > 0 && $diffPrice < 300,
                            'bg-red-500 font-black' => $diffPrice >= 300
                        ])></div>

                        <div class="p-8 ml-2 flex-1 flex flex-col">
                            <div class="flex justify-between items-start mb-6 text-left">
                                <div>
                                    <p class="text-[9px] font-black text-blue-500 uppercase tracking-widest mb-1 italic leading-none">{{ $formula->code }}</p>
                                    <h3 class="text-2xl font-black text-slate-900 uppercase italic tracking-tighter leading-none">{{ $formula->name }}</h3>
                                </div>
                                <span class="px-3 py-1 bg-slate-900 text-white rounded-lg text-[8px] font-black uppercase italic tracking-widest shadow-sm">
                                    {{ strtoupper($formula->target_type) }}
                                </span>
                            </div>

                            {{-- ANALYSE NUTRITIONNELLE VS NORME --}}
                            <div class="space-y-4 mb-8">
                                <div class="bg-slate-50 p-5 rounded-[2rem] space-y-3 border border-slate-100 shadow-inner text-left">
                                    {{-- Énergie Métabolisable --}}
                                    <div class="space-y-1">
                                        <div class="flex justify-between text-[8px] font-black uppercase italic">
                                            <span class="text-slate-400">Énergie (EM)</span>
                                            <span class="text-slate-800">{{ number_format($reelEM, 0, ',', ' ') }} / {{ number_format($targetEM, 0, ',', ' ') }} <small>kcal</small></span>
                                        </div>
                                        <div class="h-1.5 bg-slate-200 rounded-full overflow-hidden">
                                            <div class="h-full bg-blue-600 transition-all duration-1000" style="width: {{ min(($reelEM/$targetEM)*100, 100) }}%"></div>
                                        </div>
                                    </div>

                                    {{-- Protéine Brute --}}
                                    <div class="space-y-1">
                                        <div class="flex justify-between text-[8px] font-black uppercase italic">
                                            <span class="text-slate-400">Protéines (PB)</span>
                                            <span class="text-slate-800">{{ number_format($reelPB, 1) }}% / {{ number_format($targetPB, 1) }}%</span>
                                        </div>
                                        <div class="h-1.5 bg-slate-200 rounded-full overflow-hidden">
                                            <div @class([
                                                'h-full transition-all duration-1000',
                                                'bg-emerald-500' => $reelPB >= $targetPB,
                                                'bg-red-500' => $reelPB < $targetPB
                                            ]) style="width: {{ min(($reelPB/$targetPB)*100, 100) }}%"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                {{-- INDICATEUR FINANCIER --}}
                                <div class="flex justify-between items-end px-2 text-left">
                                    <span class="text-[9px] font-black text-slate-400 uppercase italic">Coût théorique</span>
                                    <div class="text-right">
                                        <p class="text-xl font-black text-slate-900 italic tracking-tighter leading-none">{{ number_format($theoreticalCost, 0, ',', ' ') }} <small class="text-[10px]">GNF</small></p>
                                        <p @class([
                                            'text-[7px] font-black uppercase mt-1 italic',
                                            'text-emerald-600' => $diffPrice <= 0,
                                            'text-red-500' => $diffPrice > 0
                                        ])>
                                            {{ $diffPrice <= 0 ? 'Sous la norme (-' : 'Surcoût (+' }}{{ number_format(abs($diffPrice), 0, ',', ' ') }} GNF/kg)
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="flex gap-3 mt-auto">
                                @can('provenderie.C')
                                <a href="{{ route('production.create', ['formula_id' => $formula->id]) }}" 
                                    class="flex-1 bg-slate-900 text-white text-center py-4 rounded-2xl text-[9px] font-black uppercase italic tracking-widest hover:bg-emerald-500 transition-all shadow-xl active:scale-95 no-underline flex items-center justify-center">
                                    <i class="fa-solid fa-play mr-2 text-emerald-400"></i> Produire
                                </a>
                                @endcan
                                
                                <a href="{{ route('formulas.show', $formula->id) }}" class="p-4 bg-slate-50 text-slate-400 rounded-2xl hover:text-blue-600 transition-colors shadow-sm flex items-center justify-center no-underline">
                                    <i class="fa-solid fa-eye mr-1"></i> Détails
                                </a>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full py-24 bg-white rounded-[4rem] border-2 border-dashed border-slate-100 text-center text-slate-400 italic font-black uppercase text-xs">
                        <i class="fa-solid fa-folder-open text-4xl block mb-4 opacity-10"></i>
                        Aucune formule enregistrée dans la bibliothèque
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- 📘 MODAL RÉFÉRENTIEL NUTRITIONNEL --}}
    <div id="modalNormes" class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-4xl rounded-[3rem] shadow-2xl p-10 animate-in fade-in zoom-in duration-300 italic overflow-hidden relative">
            <div class="flex justify-between items-center mb-8 border-b border-slate-50 pb-6">
                <h3 class="text-2xl font-black text-slate-900 uppercase italic tracking-tighter leading-none">Normes de Fabrication</h3>
                <button onclick="document.getElementById('modalNormes').classList.add('hidden')" class="text-slate-300 hover:text-red-500 transition-colors">
                    <i class="fa-solid fa-circle-xmark text-2xl"></i>
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-h-[50vh] overflow-y-auto pr-4 mb-8 custom-scrollbar">
                @forelse($norms as $norm)
                <div class="border-2 border-slate-50 p-6 rounded-[2.5rem] bg-slate-50/50 hover:border-blue-100 transition-all text-left">
                    <div class="flex justify-between items-center mb-4">
                        <span class="px-4 py-1 bg-blue-600 text-white rounded-full text-[8px] font-black uppercase italic shadow-lg">{{ $norm->name }}</span>
                        <span class="text-[10px] font-black text-slate-400 uppercase italic tracking-tighter">{{ $norm->animal_type }}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-x-8 gap-y-4">
                        <div class="flex flex-col border-r border-slate-200">
                            <span class="text-[8px] text-slate-400 uppercase font-black mb-1 italic">EM Cible</span>
                            <span class="text-sm font-black text-slate-800 italic">{{ number_format($norm->target_em, 0, ',', ' ') }} <small>kcal</small></span>
                        </div>
                        <div class="flex flex-col text-left">
                            <span class="text-[8px] text-slate-400 uppercase font-black mb-1 italic">PB Cible</span>
                            <span class="text-sm font-black text-slate-800 italic">{{ number_format($norm->target_pb, 1) }} %</span>
                        </div>
                        <div class="flex flex-col border-r border-slate-200 border-t border-slate-100 pt-2">
                            <span class="text-[8px] text-slate-400 uppercase font-black mb-1 italic">Lysine (%)</span>
                            <span class="text-sm font-black text-slate-800 italic">{{ number_format($norm->target_lys, 2) }} %</span>
                        </div>
                        <div class="flex flex-col border-t border-slate-100 pt-2 text-left">
                            <span class="text-[8px] text-blue-500 uppercase font-black mb-1 italic">Prix Cible</span>
                            <span class="text-sm font-black text-slate-900 italic">{{ number_format($norm->target_price_kg, 0, ',', ' ') }} GNF/kg</span>
                        </div>
                    </div>
                </div>
                @empty
                    <div class="col-span-2 text-center py-10 text-slate-400 uppercase italic font-black">Aucune norme enregistrée.</div>
                @endforelse
            </div>

            {{-- IMPORT EXCEL : Permission M (Modification du référentiel) --}}
            @can('provenderie.M')
            <div class="bg-blue-50/50 p-6 rounded-[2.5rem] border border-blue-100 flex flex-col md:flex-row items-center justify-between gap-6">
                <div class="text-left">
                    <h4 class="text-xs font-black uppercase text-blue-600 italic tracking-tighter mb-1">Mettre à jour le Référentiel</h4>
                    <p class="text-[9px] font-bold text-slate-400 uppercase italic leading-none">Importation de masse via fichier Excel (.xlsx)</p>
                </div>
                
                <form action="{{ route('norms.import') }}" method="POST" enctype="multipart/form-data" id="importForm" class="flex items-center gap-4">
                    @csrf
                    <input type="file" name="file" id="fileNorms" class="hidden" onchange="document.getElementById('importForm').submit()">
                    <button type="button" onclick="document.getElementById('fileNorms').click()" 
                        class="bg-slate-900 text-white px-8 py-4 rounded-2xl text-[9px] font-black uppercase italic tracking-widest shadow-xl hover:bg-blue-600 transition-all flex items-center">
                        <i class="fa-solid fa-file-import mr-2"></i> Sélectionner le fichier
                    </button>
                    <a href="/templates/norms_template.xlsx" title="Télécharger le modèle Excel" class="w-12 h-12 bg-white text-blue-500 rounded-xl flex items-center justify-center border border-blue-100 hover:bg-blue-50 shadow-sm transition-all">
                        <i class="fa-solid fa-download"></i>
                    </a>
                </form>
            </div>
            @endcan
        </div>
    </div>
</x-app-layout>