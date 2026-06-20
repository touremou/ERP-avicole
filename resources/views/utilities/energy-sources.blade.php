<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <a href="{{ route('utilities.dashboard') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Sources d'Énergie") }}</h2>
                    <p class="text-[10px] font-black text-amber-600 uppercase tracking-[0.2em] mt-2 italic">{{ __("EDG, Groupe Électrogène, Solaire — Configuration") }}</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['mb-8 p-5 rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success', 'bg-red-500 text-white' => $msg === 'error'])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : 'circle-xmark' }} mr-3 text-lg"></i> {{ session($msg) }}
                    </div>
                @endif
            @endforeach

            {{-- LISTE DES SOURCES --}}
            <div class="space-y-4 mb-8">
                @foreach($sources as $source)
                <div @class(['p-6 rounded-[2.5rem] border shadow-sm',
                    'bg-red-50 border-red-200' => $source->status === 'panne',
                    'bg-amber-50 border-amber-200' => $source->status === 'maintenance' || $source->needs_maintenance,
                    'bg-white border-slate-100' => $source->status === 'operationnel' && !$source->needs_maintenance])>
                    
                    <div class="flex flex-col md:flex-row justify-between items-start gap-4">
                        <div class="flex items-center gap-4">
                            <div @class(['w-12 h-12 rounded-2xl flex items-center justify-center text-white shadow-lg',
                                'bg-blue-500' => $source->type === 'edg',
                                'bg-amber-500' => $source->type === 'groupe',
                                'bg-emerald-500' => $source->type === 'solaire'])>
                                <i class="fa-solid {{ $source->type === 'edg' ? 'fa-plug' : ($source->type === 'groupe' ? 'fa-gas-pump' : 'fa-solar-panel') }} text-lg"></i>
                            </div>
                            <div>
                                <p class="text-sm font-black text-slate-900 uppercase italic">{{ $source->name }}</p>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">
                                    {{ $source->type_label }}
                                    @if($source->brand) — {{ $source->brand }} {{ $source->model }} @endif
                                    @if($source->capacity_kva) — {{ $source->capacity_kva }} kVA @endif
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <span @class(['text-[8px] font-black uppercase px-3 py-1 rounded-full',
                                'bg-emerald-50 text-emerald-600' => $source->status === 'operationnel',
                                'bg-amber-50 text-amber-600' => $source->status === 'maintenance',
                                'bg-red-50 text-red-600' => $source->status === 'panne'])>
                                {{ $source->status }}
                            </span>
                            
                            {{-- 💡 ACTIONS : MODIFIER / SUPPRIMER --}}
                            <div class="flex items-center gap-1 border-l border-slate-200/50 pl-3">
                                <a href="{{ route('utilities.energy.sources.edit', $source->id) }}" class="w-8 h-8 rounded-xl bg-white/50 text-slate-400 hover:bg-blue-500 hover:text-white flex items-center justify-center transition-all shadow-sm" title="{{ __('Modifier') }}">
                                    <i class="fa-solid fa-pen text-[10px]"></i>
                                </a>
                                <form method="POST" action="{{ route('utilities.energy.sources.destroy', $source->id) }}" onsubmit="return confirm('{{ __("Êtes-vous sûr de vouloir supprimer définitivement cette source d'énergie ?") }}');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="w-8 h-8 rounded-xl bg-white/50 text-slate-400 hover:bg-red-500 hover:text-white flex items-center justify-center transition-all shadow-sm border-none cursor-pointer" title="{{ __('Supprimer') }}">
                                        <i class="fa-solid fa-trash text-[10px]"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    @if($source->type === 'groupe')
                    <div class="grid grid-cols-4 gap-4 mt-4">
                        <div class="text-center p-3 bg-slate-50 rounded-xl">
                            <p class="text-[8px] font-black text-slate-400 uppercase">{{ __("Heures totales") }}</p>
                            <p class="text-lg font-black text-slate-900">{{ number_format($source->total_hours_run, 0) }}h</p>
                        </div>
                        <div class="text-center p-3 bg-slate-50 rounded-xl">
                            <p class="text-[8px] font-black text-slate-400 uppercase">{{ __("Avant vidange") }}</p>
                            <p class="text-lg font-black {{ $source->needs_maintenance ? 'text-amber-600' : 'text-slate-900' }}">{{ round($source->hours_before_maintenance) }}h</p>
                        </div>
                        <div class="text-center p-3 bg-slate-50 rounded-xl">
                            <p class="text-[8px] font-black text-slate-400 uppercase">{{ __("Gasoil cuve") }}</p>
                            <p class="text-lg font-black {{ $source->is_fuel_low ? 'text-red-600' : 'text-slate-900' }}">{{ number_format($source->current_fuel_level ?? 0) }}L</p>
                        </div>
                        <div class="text-center p-3 bg-slate-50 rounded-xl">
                            <p class="text-[8px] font-black text-slate-400 uppercase">{{ __("Autonomie") }}</p>
                            <p class="text-lg font-black {{ $source->is_fuel_low ? 'text-red-600 animate-pulse' : 'text-slate-900' }}">
                                {{ $source->fuel_autonomy_days ?? '—' }}j
                                @if($source->fuel_autonomy_hours !== null)
                                    <span class="text-[9px] font-black opacity-50">({{ $source->fuel_autonomy_hours }}h)</span>
                                @endif
                            </p>
                            <p class="text-[7px] text-slate-300 font-black uppercase tracking-widest mt-0.5">{{ __("Seuil :") }} {{ setting('energie.autonomy_alert_hours', 24) }}h</p>
                        </div>
                    </div>

                    @can('ressources.M')
                        @if($source->needs_maintenance || $source->status === 'maintenance')
                        <form method="POST" action="{{ route('utilities.energy.maintenance', $source) }}" class="mt-4 flex gap-3 items-end">
                            @csrf 
                            @method('PUT')
                            <input type="text" name="maintenance_notes" placeholder="{{ __('Notes de maintenance (vidange, filtres, etc.)...') }}"
                                class="flex-1 bg-white border border-amber-300 rounded-xl p-3 text-[10px] font-bold outline-none">
                            <button type="submit" class="bg-amber-500 text-white px-5 py-3 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-amber-600 transition-all border-none cursor-pointer shrink-0">
                                <i class="fa-solid fa-wrench mr-1"></i> {{ __("Maintenance effectuée") }}
                            </button>
                        </form>
                        @endif
                    @endcan
                    
                    @endif
                </div>
                @endforeach
            </div>

            {{-- FORMULAIRE AJOUT --}}
            @can('ressources.C')
            <div class="bg-amber-50 p-8 rounded-[3rem] border border-amber-200">
                <h3 class="text-[10px] font-black text-amber-600 uppercase tracking-widest mb-6 flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> {{ __("Ajouter une source d'énergie") }}
                </h3>
                <form method="POST" action="{{ route('utilities.energy.sources.store') }}" class="space-y-4">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Nom") }} *</label>
                            <input type="text" name="name" required placeholder="{{ __('Groupe Perkins 100kVA...') }}"
                                class="w-full bg-white border-none rounded-2xl p-4 text-sm font-black shadow-sm outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Type") }} *</label>
                            <select name="type" required class="w-full bg-white border-none rounded-2xl p-4 text-xs font-black uppercase shadow-sm outline-none appearance-none cursor-pointer">
                                <option value="edg">{{ __("EDG (Réseau)") }}</option>
                                <option value="groupe">{{ __("Groupe Électrogène") }}</option>
                                <option value="solaire">{{ __("Solaire") }}</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Puissance (kVA)") }}</label>
                            <input type="number" name="capacity_kva" step="0.1" min="0" placeholder="{{ __('Ex: 100') }}"
                                class="w-full bg-white border-none rounded-2xl p-4 text-sm font-black shadow-sm outline-none">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Marque") }}</label>
                            <input type="text" name="brand" placeholder="{{ __('Perkins, Caterpillar...') }}"
                                class="w-full bg-white border-none rounded-2xl p-4 text-xs font-black shadow-sm outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Modèle") }}</label>
                            <input type="text" name="model" placeholder="{{ __('1104D-44T...') }}"
                                class="w-full bg-white border-none rounded-2xl p-4 text-xs font-black shadow-sm outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Carburant") }}</label>
                            <select name="fuel_type" class="w-full bg-white border-none rounded-2xl p-4 text-xs font-black uppercase shadow-sm outline-none appearance-none cursor-pointer">
                                <option value="">{{ __("N/A (EDG/Solaire)") }}</option>
                                <option value="gasoil">{{ __("Gasoil") }}</option>
                                <option value="essence">{{ __("Essence") }}</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Capacité cuve (L)") }}</label>
                            <input type="number" name="fuel_tank_capacity" min="0" placeholder="{{ __('Ex: 500') }}"
                                class="w-full bg-white border-none rounded-2xl p-4 text-sm font-black shadow-sm outline-none">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Intervalle maintenance (heures)") }}</label>
                        <input type="number" name="maintenance_interval_hours" value="250" min="50"
                            class="w-full bg-white border-none rounded-2xl p-4 text-sm font-black shadow-sm outline-none md:max-w-xs">
                    </div>
                    <button type="submit" class="bg-amber-500 text-white px-8 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-amber-600 transition-all border-none cursor-pointer shadow-lg mt-4">
                        <i class="fa-solid fa-bolt mr-2"></i> {{ __("Enregistrer") }}
                    </button>
                </form>
            </div>
            @endcan
        </div>
    </div>
</x-app-layout>