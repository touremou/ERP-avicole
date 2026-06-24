<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <a href="{{ route('utilities.dashboard') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Sources d'Eau") }}</h2>
                    <p class="text-[10px] font-black text-cyan-600 uppercase tracking-[0.2em] mt-2 italic">{{ __("SEEG, forage, citernes — Configuration") }}</p>
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
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
                @foreach($sources as $source)
                <div @class(['p-6 rounded-[2.5rem] border shadow-sm',
                    'bg-red-50 border-red-200' => $source->is_low,
                    'bg-white border-slate-100' => !$source->is_low])>
                    <div class="flex justify-between items-start mb-4">
                        <div class="flex items-center gap-3">
                            <div @class(['w-10 h-10 rounded-xl flex items-center justify-center text-white',
                                'bg-blue-500' => $source->type === 'seeg',
                                'bg-emerald-500' => $source->type === 'forage',
                                'bg-cyan-500' => $source->type === 'citerne',
                                'bg-amber-500' => $source->type === 'camion'])>
                                <i class="fa-solid {{ $source->type === 'seeg' ? 'fa-faucet' : ($source->type === 'forage' ? 'fa-arrow-up-from-ground-water' : ($source->type === 'citerne' ? 'fa-droplet' : 'fa-truck-droplet')) }}"></i>
                            </div>
                            <div>
                                <p class="text-sm font-black text-slate-900 uppercase italic">{{ $source->name }}</p>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ $source->type_label }} — {{ __(":count relevé(s)", ['count' => $source->readings_count]) }}</p>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <span @class(['text-[8px] font-black uppercase px-3 py-1 rounded-full',
                                'bg-emerald-50 text-emerald-600' => $source->quality_status === 'bon',
                                'bg-amber-50 text-amber-600' => $source->quality_status === 'acceptable',
                                'bg-red-50 text-red-600' => $source->quality_status === 'traitement_requis'])>
                                {{ str_replace('_', ' ', $source->quality_status) }}
                            </span>
                            
                            {{-- 💡 ACTIONS : MODIFIER / SUPPRIMER --}}
                            <div class="flex items-center gap-1 border-l border-slate-200/50 pl-2">
                                <a href="{{ route('utilities.water.sources.edit', $source->id) }}" class="w-6 h-6 rounded-lg bg-slate-100 text-slate-400 hover:bg-blue-500 hover:text-white flex items-center justify-center transition-all shadow-sm" title="{{ __('Modifier') }}">
                                    <i class="fa-solid fa-pen text-[9px]"></i>
                                </a>
                                <form method="POST" action="{{ route('utilities.water.sources.destroy', $source->id) }}" onsubmit="return confirm('{{ __("Êtes-vous sûr de vouloir supprimer définitivement cette source d'eau ?") }}');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="w-6 h-6 rounded-lg bg-slate-100 text-slate-400 hover:bg-red-500 hover:text-white flex items-center justify-center transition-all shadow-sm border-none cursor-pointer" title="{{ __('Supprimer') }}">
                                        <i class="fa-solid fa-trash text-[9px]"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    @if($source->type === 'citerne' && $source->capacity_liters)
                        @php $pct = $source->current_level_percent ?? 0; @endphp
                        <div class="w-full bg-slate-200 rounded-full h-4 overflow-hidden mb-2">
                            <div @class(['h-4 rounded-full transition-all', $pct < 30 ? 'bg-red-500' : ($pct < 50 ? 'bg-amber-500' : 'bg-cyan-500')]) style="width: {{ $pct }}%"></div>
                        </div>
                        <p class="text-[8px] text-slate-400">{{ number_format($source->current_level_liters ?? 0) }} / {{ number_format($source->capacity_liters) }} L ({{ round($pct) }}%)</p>
                    @endif
                </div>
                @endforeach
            </div>

            {{-- FORMULAIRE AJOUT --}}
            @can('ressources.C')
            <div class="bg-cyan-50 p-8 rounded-[3rem] border border-cyan-200">
                <h3 class="text-[10px] font-black text-cyan-600 uppercase tracking-widest mb-6 flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> {{ __("Ajouter une source d'eau") }}
                </h3>
                <form method="POST" action="{{ route('utilities.water.sources.store') }}" class="space-y-4">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Nom") }} *</label>
                            <input type="text" name="name" required placeholder="{{ __('Citerne Bât. A, Forage principal...') }}"
                                class="w-full bg-white border-none rounded-2xl p-4 text-sm font-black shadow-sm outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Type") }} *</label>
                            <select name="type" required class="w-full bg-white border-none rounded-2xl p-4 text-xs font-black uppercase shadow-sm outline-none appearance-none">
                                <option value="seeg">{{ __("SEEG (Réseau)") }}</option>
                                <option value="forage">{{ __("Forage") }}</option>
                                <option value="citerne">{{ __("Citerne") }}</option>
                                <option value="camion">{{ __("Camion-citerne") }}</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Capacité (litres)") }}</label>
                            <input type="number" name="capacity_liters" min="0" placeholder="{{ __('Ex: 10000') }}"
                                class="w-full bg-white border-none rounded-2xl p-4 text-sm font-black shadow-sm outline-none">
                        </div>
                    </div>
                    <label class="flex items-center gap-3 cursor-pointer ml-2">
                        <input type="checkbox" name="is_default" value="1" class="rounded border-slate-200 text-cyan-500 focus:ring-cyan-400">
                        <span class="text-[9px] font-black uppercase text-slate-500 tracking-widest italic">
                            {{ __("Source par défaut de la ferme") }}
                            <span class="text-slate-400 normal-case font-bold">— {{ __("utilisée pour les bâtiments sans citerne affectée") }}</span>
                        </span>
                    </label>
                    <button type="submit" class="bg-cyan-500 text-white px-8 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-cyan-600 transition-all border-none cursor-pointer shadow-lg">
                        <i class="fa-solid fa-faucet-drip mr-2"></i> {{ __("Enregistrer") }}
                    </button>
                </form>
            </div>
            @endcan
        </div>
    </div>
</x-app-layout>