<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left italic font-bold">
            <div>
                <h2 class="text-3xl font-black text-slate-800 tracking-tighter uppercase italic leading-none">
                    {{ __('Référentiel des Normes') }}
                </h2>
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mt-3 italic leading-none">
                    Objectifs de performance par espèce et souche génétique
                </p>
            </div>
            <a href="{{ route('batches.index') }}" class="group flex items-center justify-center w-12 h-12 bg-white border border-slate-200 text-slate-400 hover:text-slate-900 rounded-2xl transition-all shadow-sm no-underline">
                <i class="fas fa-times group-hover:rotate-90 transition-transform"></i>
            </a>
        </div>
    </x-slot>

    <style>[x-cloak] { display: none !important; }</style>

    <div class="py-12" x-data="{ 
        openAdd: false, 
        openEdit: false, 
        openDelete: false, 
        openImport: false,
        currentNorm: { id: '', week_number: '', phase_name: 'Démarrage', target_weight: '', target_laying_rate: '', target_feed_daily: '', target_water_daily: '', model_name: '' },
        resetNorm() {
            this.currentNorm = { id: '', week_number: '', phase_name: 'Démarrage', target_weight: '', target_laying_rate: '', target_feed_daily: '', target_water_daily: '', model_name: '' };
        }
    }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold">

            {{-- 1. SÉLECTEUR DE TYPE & ACTIONS STRATÉGIQUES --}}
            <div class="flex flex-col lg:flex-row items-center gap-6 mb-10 text-left">
                <div class="bg-slate-100 p-2 rounded-[2.5rem] flex flex-wrap gap-2 shadow-inner border border-slate-200/50">
                    @foreach($batchTypes as $t)
                        @php
                            $meta = \App\Http\Controllers\Admin\ProductionNormController::TYPE_META[$t]
                                ?? ['label' => ucfirst($t), 'icon' => '📋', 'color' => 'slate'];
                        @endphp
                        <a href="?type={{ $t }}"
                           @class([
                               "px-6 py-3 rounded-[1.5rem] text-[10px] font-black uppercase tracking-widest transition-all no-underline border border-transparent",
                               "bg-{$meta['color']}-600 text-white shadow-lg" => $type == $t,
                               "bg-white text-slate-500 hover:text-slate-900" => $type != $t
                           ])>
                            {{ $meta['icon'] }} {{ $meta['label'] }}
                        </a>
                    @endforeach
                </div>
                @can('elevage.C')
                <div class="lg:ml-auto flex gap-3 w-full lg:w-auto">
                    <button @click="openImport = true" class="flex-1 lg:flex-none bg-emerald-100 text-emerald-700 px-8 py-4 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-emerald-600 hover:text-white transition-all shadow-sm border border-emerald-200 cursor-pointer">
                        <i class="fas fa-file-import mr-2"></i> Import CSV
                    </button>
                    <button @click="resetNorm(); openAdd = true" class="flex-1 lg:flex-none bg-slate-900 text-white px-8 py-4 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all shadow-2xl border-none cursor-pointer">
                        <i class="fas fa-plus-circle mr-2"></i> Ajouter Norme
                    </button>
                </div>
                @endcan
            </div>

            {{-- 2. TABLEAU DES NORMES (L) --}}
            <div class="bg-white rounded-[4rem] shadow-2xl border border-slate-100 overflow-hidden font-bold text-left italic">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-900 border-b border-slate-800 font-black uppercase text-slate-400 text-[10px] tracking-[0.2em] italic">
                            <th class="px-10 py-8">Semaine / Souche</th>
                            <th class="px-6 py-8">Phase Bio</th>
                            <th class="px-6 py-8 text-center">Poids Cible</th>
                            <th class="px-6 py-8 text-center">Taux Ponte</th>
                            <th class="px-6 py-8 text-center">Ration / Hydrat.</th>
                            <th class="px-10 py-8 text-right">Contrôle</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($norms as $norm)
                        <tr class="group hover:bg-slate-50 transition-all italic font-black">
                            <td class="px-10 py-8">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 bg-slate-100 rounded-xl flex items-center justify-center text-slate-900 group-hover:bg-blue-600 group-hover:text-white transition-colors shadow-inner">
                                        <span class="text-sm">S{{ $norm->week_number }}</span>
                                    </div>
                                    <div>
                                        <p class="text-slate-900 font-black text-lg leading-none uppercase tracking-tighter italic">Semaine {{ $norm->week_number }}</p>
                                        <p class="text-[9px] text-blue-500 uppercase mt-2 tracking-widest font-black italic">
                                            Modèle: {{ $norm->model_name ?: 'STANDARD' }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-8">
                                <span class="px-3 py-1 bg-slate-100 text-slate-500 rounded-lg text-[9px] font-black uppercase italic border border-slate-200">
                                    {{ $norm->phase_name }}
                                </span>
                            </td>
                            <td class="px-6 py-8 text-center">
                                <p class="text-xl font-black text-slate-900 italic tracking-tighter">{{ number_format($norm->target_weight) }}<small class="text-[10px] ml-1 opacity-40">G</small></p>
                            </td>
                            <td class="px-6 py-8 text-center">
                                <p class="text-xl font-black text-emerald-600 italic tracking-tighter">{{ $norm->target_laying_rate }}<small class="text-[10px] ml-1 opacity-60">%</small></p>
                            </td>
                            <td class="px-6 py-8 text-center">
                                <div class="inline-flex flex-col gap-1">
                                    <span class="text-orange-600 text-[11px] bg-orange-50 px-2 py-0.5 rounded-md font-black italic border border-orange-100">{{ $norm->target_feed_daily ?? 0 }}g <small class="opacity-50 italic">alim</small></span>
                                    <span class="text-blue-500 text-[11px] bg-blue-50 px-2 py-0.5 rounded-md font-black italic border border-blue-100">{{ $norm->target_water_daily ?? 0 }}ml <small class="opacity-50 italic">eau</small></span>
                                </div>
                            </td>
                            <td class="px-10 py-8 text-right">
                                <div class="flex justify-end gap-3">
                                    <button @click="currentNorm = {{ $norm->toJson() }}; openEdit = true" class="w-10 h-10 bg-white border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-600 rounded-xl transition-all shadow-sm cursor-pointer border-none">
                                        <i class="fas fa-pen-nib text-xs"></i>
                                    </button>
                                    <button @click="currentNorm = {{ $norm->toJson() }}; openDelete = true" class="w-10 h-10 bg-white border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-600 rounded-xl transition-all shadow-sm cursor-pointer border-none">
                                        <i class="fas fa-trash-can text-xs"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-10 py-32 text-center">
                                <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
                                    <i class="fas fa-layer-group text-3xl text-slate-200"></i>
                                </div>
                                <p class="text-slate-300 font-black uppercase text-[12px] tracking-[0.4em] italic">Référentiel vide pour ce secteur</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 3. MODALE UNIFIÉE (AJOUT/ÉDITION) --}}
        @can('elevage.C')
        <div x-show="openAdd || openEdit" 
             x-transition.opacity 
             class="fixed inset-0 z-[100] flex items-center justify-center p-6 bg-slate-900/90 backdrop-blur-md" x-cloak>
            
            <div @click.away="openAdd = false; openEdit = false" 
                 class="bg-white w-full max-w-3xl rounded-[4rem] shadow-2xl overflow-hidden p-12 italic text-left font-bold relative">
                
                <button @click="openAdd = false; openEdit = false" class="absolute top-10 right-10 text-slate-200 hover:text-rose-500 border-none bg-transparent cursor-pointer text-2xl">
                    <i class="fas fa-circle-xmark"></i>
                </button>

                <h3 class="text-3xl font-black text-slate-900 uppercase italic tracking-tighter mb-10 leading-none" 
                    x-text="openEdit ? 'Audit & Mise à jour' : 'Initialiser une Norme'"></h3>
                
                <form :action="openEdit ? '{{ url('admin/norms') }}/' + currentNorm.id : '{{ route('admin.norms.store') }}'" method="POST" class="space-y-8">
                    @csrf
                    <template x-if="openEdit">
                        <input type="hidden" name="_method" value="PUT">
                    </template>
                    
                    <input type="hidden" name="batch_type" value="{{ $type }}">

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase text-slate-400 ml-4 italic">Souche Génétique</label>
                            <input type="text" name="model_name" x-model="currentNorm.model_name" placeholder="ROSS 308, COBB..." class="w-full bg-slate-50 border-none rounded-2xl p-5 text-xs uppercase font-black shadow-inner outline-none focus:ring-4 focus:ring-blue-500/10">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase text-slate-400 ml-4 italic">Numéro Semaine</label>
                            <input type="number" min="0" name="week_number" x-model="currentNorm.week_number" required class="w-full bg-slate-50 border-none rounded-2xl p-5 text-base font-black shadow-inner outline-none text-blue-600">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase text-slate-400 ml-4 italic">Stade de Croissance</label>
                            <select name="phase_name" x-model="currentNorm.phase_name" class="w-full bg-slate-50 border-none rounded-2xl p-5 text-xs uppercase font-black shadow-inner outline-none cursor-pointer">
                                <option value="Démarrage">🐣 Démarrage</option>
                                <option value="Croissance">🐓 Croissance</option>
                                <option value="Pré-ponte">🐔 Pré-ponte</option>
                                <option value="Ponte">🥚 Ponte</option>
                                <option value="Finition">🍗 Finition</option>
                                <option value="Réforme">🚜 Réforme</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-6 bg-slate-50 p-8 rounded-[3rem] border border-slate-100 shadow-inner">
                        <div class="space-y-2 text-center">
                            <label class="text-[9px] font-black uppercase text-slate-400 italic">Poids (g)</label>
                            <input type="number" min="0" name="target_weight" x-model="currentNorm.target_weight" class="w-full bg-white border-none rounded-xl p-4 text-sm font-black text-center shadow-sm">
                        </div>
                        <div class="space-y-2 text-center">
                            <label class="text-[9px] font-black uppercase text-emerald-500 italic">Ponte (%)</label>
                            <input type="number" min="0" step="0.1" name="target_laying_rate" x-model="currentNorm.target_laying_rate" class="w-full bg-white border-none rounded-xl p-4 text-sm font-black text-center shadow-sm text-emerald-600">
                        </div>
                        <div class="space-y-2 text-center">
                            <label class="text-[9px] font-black uppercase text-orange-500 italic">Alim (g/j)</label>
                            <input type="number" min="0" step="0.1" name="target_feed_daily" x-model="currentNorm.target_feed_daily" class="w-full bg-white border-none rounded-xl p-4 text-sm font-black text-center shadow-sm text-orange-600">
                        </div>
                        <div class="space-y-2 text-center">
                            <label class="text-[9px] font-black uppercase text-blue-500 italic">Eau (ml/j)</label>
                            <input type="number" min="0" step="0.1" name="target_water_daily" x-model="currentNorm.target_water_daily" class="w-full bg-white border-none rounded-xl p-4 text-sm font-black text-center shadow-sm text-blue-600">
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-slate-900 text-white py-6 rounded-3xl text-[11px] font-black uppercase tracking-[0.3em] hover:bg-blue-600 transition shadow-2xl border-none cursor-pointer italic" 
                            x-text="openEdit ? 'ACTUALISER LE RÉFÉRENTIEL' : 'ENREGISTRER LA NORME'"></button>
                </form>
            </div>
        </div>
        @endcan

        @can('elevage.S')
        {{-- 4. MODALE IMPORT CSV --}}
        <div x-show="openImport" 
             x-transition.opacity 
             class="fixed inset-0 z-[100] flex items-center justify-center p-6 bg-slate-900/90 backdrop-blur-md" x-cloak>
            
            <div @click.away="openImport = false" class="bg-white w-full max-w-xl rounded-[4rem] shadow-2xl p-12 italic text-left font-bold relative">
                <button @click="openImport = false" class="absolute top-10 right-10 text-slate-200 hover:text-rose-500 border-none bg-transparent cursor-pointer text-2xl font-black">
                    <i class="fas fa-times"></i>
                </button>

                <h3 class="text-3xl font-black text-slate-900 uppercase italic tracking-tighter mb-4 leading-none">Importation Massive</h3>
                <p class="text-[10px] text-slate-400 uppercase font-black mb-10 tracking-widest leading-relaxed">
                    Fichier .csv : week_number, phase_name, target_weight, target_laying_rate, target_feed_daily, target_water_daily, model_name
                </p>
                
                <form action="{{ route('admin.norms.import') }}" method="POST" enctype="multipart/form-data" class="space-y-8">
                    @csrf
                    <input type="hidden" name="batch_type" value="{{ $type }}">
                    <div class="border-4 border-dashed border-slate-100 rounded-[3rem] p-12 text-center hover:border-emerald-400 hover:bg-emerald-50 transition-all group cursor-pointer relative">
                        <input type="file" name="file" accept=".csv" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" id="csv_file_input">
                        <i class="fas fa-file-csv text-6xl text-slate-100 group-hover:text-emerald-500 transition-colors mb-6 block"></i>
                        <p class="text-[11px] font-black uppercase text-slate-400 group-hover:text-emerald-700 tracking-widest">Glissez votre fichier ici</p>
                    </div>
                    <button type="submit" class="w-full bg-emerald-600 text-white py-6 rounded-3xl text-[11px] font-black uppercase tracking-[0.2em] hover:bg-slate-900 shadow-2xl border-none cursor-pointer italic transition-all">
                        DÉMARRER LE TRAITEMENT
                    </button>
                </form>
            </div>
        </div>
        @endcan
    </div>
</x-app-layout>