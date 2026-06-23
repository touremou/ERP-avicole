<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left italic font-bold">
            <div>
                <h2 class="text-3xl font-black text-slate-800 tracking-tighter uppercase italic leading-none">
                    {{ __('Référentiel des Normes') }}
                </h2>
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mt-3 italic leading-none">
                    {{ __("Objectifs de performance par espèce et souche génétique") }}
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
        currentNorm: { id: '', species_id: '', week_number: '', phase_name: 'Démarrage', target_weight: '', target_laying_rate: '', target_feed_daily: '', target_water_daily: '', model_name: '' },
        resetNorm() {
            this.currentNorm = { id: '', species_id: '', week_number: '', phase_name: 'Démarrage', target_weight: '', target_laying_rate: '', target_feed_daily: '', target_water_daily: '', model_name: '' };
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

                {{-- Filtre par souche génétique (model_name) au sein du secteur actif --}}
                @if($models->isNotEmpty())
                <div class="relative w-full sm:w-auto">
                    <select onchange="window.location.href = '?type={{ $type }}' + (this.value ? '&model=' + encodeURIComponent(this.value) : '')"
                            class="appearance-none w-full sm:w-auto bg-white border border-slate-200 rounded-[1.5rem] pl-12 pr-10 py-3.5 text-[10px] font-black uppercase tracking-widest text-slate-600 shadow-sm cursor-pointer outline-none focus:ring-4 focus:ring-blue-500/10 italic">
                        <option value="" @selected($model === '')>🧬 {{ __("Toutes les souches") }} ({{ $models->count() }})</option>
                        @foreach($models as $m)
                            <option value="{{ $m }}" @selected($model === $m)>{{ $m }}</option>
                        @endforeach
                    </select>
                    <i class="fas fa-dna absolute left-5 top-1/2 -translate-y-1/2 text-slate-300 pointer-events-none text-xs"></i>
                    <i class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-300 pointer-events-none text-[9px]"></i>
                </div>
                @if($model !== '')
                <a href="?type={{ $type }}" class="flex items-center gap-2 text-[9px] font-black uppercase tracking-widest text-rose-500 hover:text-rose-700 transition-colors no-underline">
                    <i class="fas fa-times-circle"></i> {{ __("Réinitialiser") }}
                </a>
                @endif
                @endif
                @can('admin.S')
                <div class="lg:ml-auto flex flex-col gap-2 w-full sm:w-auto">
                    <button @click="openImport = true" class="w-full bg-emerald-100 text-emerald-700 px-5 py-2.5 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-emerald-600 hover:text-white transition-all shadow-sm border border-emerald-200 cursor-pointer text-left sm:text-center">
                        <i class="fas fa-file-import mr-2"></i> {{ __("Import CSV") }}
                    </button>
                    <button @click="resetNorm(); openAdd = true" class="w-full bg-slate-900 text-white px-5 py-2.5 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all shadow-md border-none cursor-pointer text-left sm:text-center">
                        <i class="fas fa-plus-circle mr-2"></i> {{ __("Ajouter Norme") }}
                    </button>
                </div>
                @endcan
            </div>

            {{-- 2. TABLEAU DES NORMES (L) — vue bureau (≥ lg) --}}
            <div class="hidden lg:block bg-white rounded-[4rem] shadow-2xl border border-slate-100 overflow-hidden font-bold text-left italic">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-900 border-b border-slate-800 font-black uppercase text-slate-400 text-[10px] tracking-[0.2em] italic">
                            <th class="px-8 py-8">{{ __("Semaine / Souche") }}</th>
                            <th class="px-4 py-8">{{ __("Phase Bio") }}</th>
                            <th class="px-4 py-8 text-center">{{ __("Poids Cible") }}</th>
                            <th class="px-4 py-8 text-center">{{ __("Taux Ponte") }}</th>
                            <th class="px-4 py-8 text-center">{{ __("Ration / Hydrat.") }}</th>
                            <th class="px-8 py-8 text-right">{{ __("Contrôle") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($norms as $norm)
                        <tr class="group hover:bg-slate-50 transition-all italic font-black">
                            <td class="px-8 py-8">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 bg-slate-100 rounded-xl flex items-center justify-center text-slate-900 group-hover:bg-blue-600 group-hover:text-white transition-colors shadow-inner shrink-0">
                                        <span class="text-sm">S{{ $norm->week_number }}</span>
                                    </div>
                                    <div>
                                        <p class="text-slate-900 font-black text-lg leading-none uppercase tracking-tighter italic">{{ __("Semaine") }} {{ $norm->week_number }}</p>
                                        <p class="text-[9px] text-blue-500 uppercase mt-2 tracking-widest font-black italic">
                                            {{ __("Modèle") }}: {{ $norm->model_name ?: 'STANDARD' }}
                                        </p>
                                        <p class="text-[9px] uppercase mt-1 tracking-widest font-black italic {{ $norm->species ? 'text-slate-400' : 'text-amber-500' }}">
                                            {{ $norm->species ? $norm->species->icon.' '.$norm->species->name_fr : '🌐 '.__('Générique') }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-8">
                                <span class="px-3 py-1 bg-slate-100 text-slate-500 rounded-lg text-[9px] font-black uppercase italic border border-slate-200">
                                    {{ $norm->phase_name }}
                                </span>
                            </td>
                            <td class="px-4 py-8 text-center">
                                <p class="text-xl font-black text-slate-900 italic tracking-tighter">{{ number_format($norm->target_weight) }}<small class="text-[10px] ml-1 opacity-40">G</small></p>
                            </td>
                            <td class="px-4 py-8 text-center">
                                <p class="text-xl font-black text-emerald-600 italic tracking-tighter">{{ $norm->target_laying_rate }}<small class="text-[10px] ml-1 opacity-60">%</small></p>
                            </td>
                            <td class="px-4 py-8 text-center">
                                <div class="inline-flex flex-col gap-1">
                                    <span class="text-orange-600 text-[11px] bg-orange-50 px-2 py-0.5 rounded-md font-black italic border border-orange-100">{{ $norm->target_feed_daily ?? 0 }}g <small class="opacity-50 italic">{{ __("alim") }}</small></span>
                                    <span class="text-blue-500 text-[11px] bg-blue-50 px-2 py-0.5 rounded-md font-black italic border border-blue-100">{{ $norm->target_water_daily ?? 0 }}ml <small class="opacity-50 italic">{{ __("eau") }}</small></span>
                                </div>
                            </td>
                            <td class="px-8 py-8 text-right">
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
                                <p class="text-slate-300 font-black uppercase text-[12px] tracking-[0.4em] italic">{{ __("Référentiel vide pour ce secteur") }}</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- 2bis. CARTES DES NORMES — vue mobile / tablette (< lg), sans scroll latéral --}}
            <div class="lg:hidden space-y-5">
                @forelse($norms as $norm)
                <div class="bg-white rounded-[2.5rem] shadow-lg border border-slate-100 p-6 italic font-black">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-4 min-w-0">
                            <div class="w-12 h-12 bg-slate-100 rounded-xl flex items-center justify-center text-slate-900 shadow-inner shrink-0">
                                <span class="text-sm">S{{ $norm->week_number }}</span>
                            </div>
                            <div class="min-w-0">
                                <p class="text-slate-900 font-black text-base leading-none uppercase tracking-tighter italic truncate">{{ __("Semaine") }} {{ $norm->week_number }}</p>
                                <p class="text-[9px] text-blue-500 uppercase mt-1.5 tracking-widest font-black italic truncate">
                                    {{ $norm->model_name ?: 'STANDARD' }}
                                </p>
                            </div>
                        </div>
                        @can('admin.S')
                        <div class="flex gap-2 shrink-0">
                            <button @click="currentNorm = {{ $norm->toJson() }}; openEdit = true" class="w-10 h-10 bg-white border border-slate-200 text-slate-400 hover:text-blue-600 rounded-xl transition-all shadow-sm cursor-pointer">
                                <i class="fas fa-pen-nib text-xs"></i>
                            </button>
                            @can('admin.S')
                            <button @click="currentNorm = {{ $norm->toJson() }}; openDelete = true" class="w-10 h-10 bg-white border border-slate-200 text-slate-400 hover:text-rose-600 rounded-xl transition-all shadow-sm cursor-pointer">
                                <i class="fas fa-trash-can text-xs"></i>
                            </button>
                            @endcan
                        </div>
                        @endcan
                    </div>

                    <div class="mt-5">
                        <span class="px-3 py-1 bg-slate-100 text-slate-500 rounded-lg text-[9px] font-black uppercase italic border border-slate-200">
                            {{ $norm->phase_name }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-3 mt-5">
                        <div class="bg-slate-50 rounded-2xl p-4 text-center border border-slate-100">
                            <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-1">{{ __("Poids cible") }}</p>
                            <p class="text-lg font-black text-slate-900 italic tracking-tighter">{{ number_format($norm->target_weight) }}<small class="text-[9px] ml-1 opacity-40">G</small></p>
                        </div>
                        <div class="bg-slate-50 rounded-2xl p-4 text-center border border-slate-100">
                            <p class="text-[8px] font-black uppercase text-emerald-500 tracking-widest mb-1">{{ __("Taux ponte") }}</p>
                            <p class="text-lg font-black text-emerald-600 italic tracking-tighter">{{ $norm->target_laying_rate }}<small class="text-[9px] ml-1 opacity-60">%</small></p>
                        </div>
                        <div class="bg-orange-50 rounded-2xl p-4 text-center border border-orange-100">
                            <p class="text-[8px] font-black uppercase text-orange-500 tracking-widest mb-1">{{ __("Ration") }}</p>
                            <p class="text-base font-black text-orange-600 italic">{{ $norm->target_feed_daily ?? 0 }}<small class="text-[9px] ml-1 opacity-50">g/j</small></p>
                        </div>
                        <div class="bg-blue-50 rounded-2xl p-4 text-center border border-blue-100">
                            <p class="text-[8px] font-black uppercase text-blue-500 tracking-widest mb-1">{{ __("Hydratation") }}</p>
                            <p class="text-base font-black text-blue-600 italic">{{ $norm->target_water_daily ?? 0 }}<small class="text-[9px] ml-1 opacity-50">ml/j</small></p>
                        </div>
                    </div>
                </div>
                @empty
                <div class="bg-white rounded-[2.5rem] shadow-lg border border-slate-100 px-8 py-20 text-center">
                    <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
                        <i class="fas fa-layer-group text-3xl text-slate-200"></i>
                    </div>
                    <p class="text-slate-300 font-black uppercase text-[11px] tracking-[0.3em] italic">Référentiel vide pour ce secteur</p>
                </div>
                @endforelse
            </div>
        </div>

        {{-- 3. MODALE UNIFIÉE (AJOUT/ÉDITION) --}}
        @can('admin.S')
        <div x-show="openAdd || openEdit" 
             x-transition.opacity 
             class="fixed inset-0 z-[100] flex items-center justify-center p-6 bg-slate-900/90 backdrop-blur-md" x-cloak>
            
            <div @click.away="openAdd = false; openEdit = false"
                 class="bg-white w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-[2.5rem] md:rounded-[4rem] shadow-2xl p-7 md:p-12 italic text-left font-bold relative">

                <button @click="openAdd = false; openEdit = false" class="absolute top-7 right-7 md:top-10 md:right-10 text-slate-200 hover:text-rose-500 border-none bg-transparent cursor-pointer text-2xl">
                    <i class="fas fa-circle-xmark"></i>
                </button>

                <h3 class="text-2xl md:text-3xl font-black text-slate-900 uppercase italic tracking-tighter mb-8 md:mb-10 leading-none pr-10"
                    x-text="openEdit ? @json(__('Audit & Mise à jour')) : @json(__('Initialiser une Norme'))"></h3>
                
                <form :action="openEdit ? '{{ url('admin/norms') }}/' + currentNorm.id : '{{ route('admin.norms.store') }}'" method="POST" class="space-y-8">
                    @csrf
                    <template x-if="openEdit">
                        <input type="hidden" name="_method" value="PUT">
                    </template>
                    
                    <input type="hidden" name="batch_type" value="{{ $type }}">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase text-slate-400 ml-4 italic">{{ __("Espèce") }}</label>
                            <select name="species_id" x-model="currentNorm.species_id" class="w-full bg-slate-50 border-none rounded-2xl p-5 text-xs uppercase font-black shadow-inner outline-none cursor-pointer focus:ring-4 focus:ring-blue-500/10">
                                <option value="">🌐 {{ __("Générique (toutes espèces)") }}</option>
                                @foreach($species as $sp)
                                    <option value="{{ $sp->id }}">{{ $sp->icon }} {{ $sp->name_fr }}</option>
                                @endforeach
                            </select>
                            <p class="text-[8px] text-slate-300 ml-4 uppercase font-bold">{{ __("* Limite la souche à cette espèce dans les lots") }}</p>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase text-slate-400 ml-4 italic">{{ __("Souche Génétique") }}</label>
                            <input type="text" name="model_name" x-model="currentNorm.model_name" placeholder="ROSS 308, COBB..." class="w-full bg-slate-50 border-none rounded-2xl p-5 text-xs uppercase font-black shadow-inner outline-none focus:ring-4 focus:ring-blue-500/10">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase text-slate-400 ml-4 italic">{{ __("Numéro Semaine") }}</label>
                            <input type="number" min="0" name="week_number" x-model="currentNorm.week_number" required class="w-full bg-slate-50 border-none rounded-2xl p-5 text-base font-black shadow-inner outline-none text-blue-600">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase text-slate-400 ml-4 italic">{{ __("Stade de Croissance") }}</label>
                            <select name="phase_name" x-model="currentNorm.phase_name" class="w-full bg-slate-50 border-none rounded-2xl p-5 text-xs uppercase font-black shadow-inner outline-none cursor-pointer">
                                <option value="Démarrage">🐣 {{ __("Démarrage") }}</option>
                                <option value="Croissance">🐓 {{ __("Croissance") }}</option>
                                <option value="Pré-ponte">🐔 {{ __("Pré-ponte") }}</option>
                                <option value="Ponte">🥚 {{ __("Ponte") }}</option>
                                <option value="Finition">🍗 {{ __("Finition") }}</option>
                                <option value="Réforme">🚜 {{ __("Réforme") }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-6 bg-slate-50 p-8 rounded-[3rem] border border-slate-100 shadow-inner">
                        <div class="space-y-2 text-center">
                            <label class="text-[9px] font-black uppercase text-slate-400 italic">{{ __("Poids (g)") }}</label>
                            <input type="number" min="0" name="target_weight" x-model="currentNorm.target_weight" class="w-full bg-white border-none rounded-xl p-4 text-sm font-black text-center shadow-sm">
                        </div>
                        <div class="space-y-2 text-center">
                            <label class="text-[9px] font-black uppercase text-emerald-500 italic">{{ __("Ponte (%)") }}</label>
                            <input type="number" min="0" step="0.1" name="target_laying_rate" x-model="currentNorm.target_laying_rate" class="w-full bg-white border-none rounded-xl p-4 text-sm font-black text-center shadow-sm text-emerald-600">
                        </div>
                        <div class="space-y-2 text-center">
                            <label class="text-[9px] font-black uppercase text-orange-500 italic">{{ __("Alim (g/j)") }}</label>
                            <input type="number" min="0" step="0.1" name="target_feed_daily" x-model="currentNorm.target_feed_daily" class="w-full bg-white border-none rounded-xl p-4 text-sm font-black text-center shadow-sm text-orange-600">
                        </div>
                        <div class="space-y-2 text-center">
                            <label class="text-[9px] font-black uppercase text-blue-500 italic">{{ __("Eau (ml/j)") }}</label>
                            <input type="number" min="0" step="0.1" name="target_water_daily" x-model="currentNorm.target_water_daily" class="w-full bg-white border-none rounded-xl p-4 text-sm font-black text-center shadow-sm text-blue-600">
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-slate-900 text-white py-6 rounded-3xl text-[11px] font-black uppercase tracking-[0.3em] hover:bg-blue-600 transition shadow-2xl border-none cursor-pointer italic"
                            x-text="openEdit ? @json(__('ACTUALISER LE RÉFÉRENTIEL')) : @json(__('ENREGISTRER LA NORME'))"></button>
                </form>
            </div>
        </div>
        @endcan

        @can('admin.S')
        {{-- 4. MODALE SUPPRESSION --}}
        <div x-show="openDelete"
             x-transition.opacity
             class="fixed inset-0 z-[100] flex items-center justify-center p-6 bg-slate-900/90 backdrop-blur-md" x-cloak>

            <div @click.away="openDelete = false" class="bg-white w-full max-w-md rounded-[2.5rem] md:rounded-[4rem] shadow-2xl p-7 md:p-12 italic text-center font-bold relative">
                <div class="w-16 h-16 bg-rose-50 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
                    <i class="fas fa-trash-can text-2xl text-rose-500"></i>
                </div>
                <h3 class="text-2xl font-black text-slate-900 uppercase italic tracking-tighter mb-3 leading-none">{{ __("Supprimer la norme ?") }}</h3>
                <p class="text-[10px] text-slate-400 uppercase font-black mb-8 tracking-widest leading-relaxed">
                    {{ __("Semaine") }} <span x-text="currentNorm.week_number"></span> —
                    <span x-text="currentNorm.model_name || 'STANDARD'"></span>.
                    {{ __("Cette action est irréversible.") }}
                </p>

                <div class="flex gap-3">
                    <button @click="openDelete = false" type="button" class="flex-1 bg-slate-100 text-slate-500 py-5 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-slate-200 transition border-none cursor-pointer italic">
                        {{ __("Annuler") }}
                    </button>
                    <form :action="'{{ url('admin/norms') }}/' + currentNorm.id" method="POST" class="flex-1">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="w-full bg-rose-600 text-white py-5 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-rose-700 transition shadow-xl border-none cursor-pointer italic">
                            {{ __("Supprimer") }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- 5. MODALE IMPORT CSV --}}
        <div x-show="openImport" 
             x-transition.opacity 
             class="fixed inset-0 z-[100] flex items-center justify-center p-6 bg-slate-900/90 backdrop-blur-md" x-cloak>
            
            <div @click.away="openImport = false" class="bg-white w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-[2.5rem] md:rounded-[4rem] shadow-2xl p-7 md:p-12 italic text-left font-bold relative">
                <button @click="openImport = false" class="absolute top-7 right-7 md:top-10 md:right-10 text-slate-200 hover:text-rose-500 border-none bg-transparent cursor-pointer text-2xl font-black">
                    <i class="fas fa-times"></i>
                </button>

                <h3 class="text-2xl md:text-3xl font-black text-slate-900 uppercase italic tracking-tighter mb-4 leading-none pr-10">{{ __("Importation Massive") }}</h3>
                <p class="text-[10px] text-slate-400 uppercase font-black mb-10 tracking-widest leading-relaxed">
                    {{ __("Fichier .csv : week_number, phase_name, target_weight, target_laying_rate, target_feed_daily, target_water_daily, model_name") }}
                </p>
                
                <form action="{{ route('admin.norms.import') }}" method="POST" enctype="multipart/form-data" class="space-y-8">
                    @csrf
                    <input type="hidden" name="batch_type" value="{{ $type }}">
                    <div class="border-4 border-dashed border-slate-100 rounded-[3rem] p-12 text-center hover:border-emerald-400 hover:bg-emerald-50 transition-all group cursor-pointer relative">
                        <input type="file" name="file" accept=".csv" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" id="csv_file_input">
                        <i class="fas fa-file-csv text-6xl text-slate-100 group-hover:text-emerald-500 transition-colors mb-6 block"></i>
                        <p class="text-[11px] font-black uppercase text-slate-400 group-hover:text-emerald-700 tracking-widest">{{ __("Glissez votre fichier ici") }}</p>
                    </div>
                    <button type="submit" class="w-full bg-emerald-600 text-white py-6 rounded-3xl text-[11px] font-black uppercase tracking-[0.2em] hover:bg-slate-900 shadow-2xl border-none cursor-pointer italic transition-all">
                        {{ __("DÉMARRER LE TRAITEMENT") }}
                    </button>
                </form>
            </div>
        </div>
        @endcan
    </div>
</x-app-layout>