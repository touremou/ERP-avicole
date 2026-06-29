<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center gap-4 text-left">
                <div class="w-12 h-12 bg-slate-900 rounded-2xl flex items-center justify-center text-white shadow-lg rotate-3">
                    <i class="fa-solid fa-magnifying-glass-chart text-xl text-blue-400"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-black text-slate-800 uppercase italic tracking-tighter leading-none italic">{{ __("Analyse Article") }}</h2>
                    <p class="text-[9px] font-bold text-blue-500 uppercase mt-1 tracking-widest italic leading-none">
                        {{ $stock->item_name }} • {{ strtoupper($stock->category) }}
                    </p>
                </div>
            </div>
            <div class="flex gap-3">
                {{-- Permission C : Démarque / ajustement d'inventaire --}}
                @can('logistique.C')
                <a href="{{ route('stock-adjustments.create', ['stock_id' => $stock->id]) }}" class="bg-white border border-slate-200 text-slate-600 px-5 py-3 rounded-2xl text-[10px] font-black uppercase italic hover:bg-orange-50 hover:text-orange-600 transition-all shadow-sm no-underline">
                    <i class="fa-solid fa-sliders mr-2 text-orange-500"></i> {{ __("Démarque") }}
                </a>
                @endcan

                {{-- Permission M : Édition de la fiche --}}
                <a href="{{ route('stocks.label', $stock->id) }}" target="_blank" class="bg-white border border-slate-200 text-slate-600 px-5 py-3 rounded-2xl text-[10px] font-black uppercase italic hover:bg-slate-50 transition-all shadow-sm no-underline" title="{{ __('Étiquette de rayon (QR)') }}">
                    <i class="fa-solid fa-qrcode mr-2 text-slate-700"></i> {{ __("Étiquette") }}
                </a>

                @can('logistique.M')
                <a href="{{ route('stocks.edit', $stock->id) }}" class="bg-white border border-slate-200 text-slate-600 px-5 py-3 rounded-2xl text-[10px] font-black uppercase italic hover:bg-slate-50 transition-all shadow-sm no-underline">
                    <i class="fa-solid fa-pen mr-2 text-blue-500"></i> {{ __("Ajuster Fiche") }}
                </a>
                @endcan
                
                {{-- Permission S : Suppression (Destructive) --}}
                @can('logistique.S')
                <form action="{{ route('stocks.destroy', $stock->id) }}" method="POST" onsubmit="return confirm('{{ __("🚨 Action Irréversible : Supprimer cet article et purger tout son historique ?") }}');">
                    @csrf @method('DELETE')
                    <button type="submit" class="bg-rose-50 text-rose-500 border border-rose-100 px-5 py-3 rounded-2xl text-[10px] font-black uppercase italic hover:bg-rose-500 hover:text-white transition-all shadow-sm">
                        <i class="fa-solid fa-trash-can mr-2"></i> {{ __("Supprimer") }}
                    </button>
                </form>
                @endcan

                <a href="{{ route('stocks.index', ['category' => $stock->category]) }}" class="bg-slate-100 text-slate-500 px-5 py-3 rounded-2xl text-[10px] font-black uppercase italic hover:bg-slate-200 transition-all no-underline">
                    {{ __("Retour") }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold text-left">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            
            {{-- 1. KPI CARDS --}}
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                {{-- POSITION ACTUELLE --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm relative overflow-hidden group">
                    <p class="text-[10px] font-black text-slate-400 uppercase mb-2 italic leading-none">{{ __("Position Actuelle") }}</p>
                    <h3 class="text-4xl font-black text-slate-900 tracking-tighter leading-none">
                        {{ number_format($stock->current_quantity, (in_array($stock->unit, ['KG', 'Alvéole']) ? 2 : 0), ',', ' ') }} 
                        <span class="text-xs text-slate-400 uppercase italic">{{ $stock->unit }}</span>
                    </h3>
                    
                    @if($stock->unit == 'Alvéole')
                        <p class="text-[9px] text-blue-500 mt-3 uppercase font-black italic bg-blue-50 px-3 py-1 rounded-lg inline-block">
                            = {{ floor($stock->current_quantity) }} {{ __("Alv.") }} + {{ round(($stock->current_quantity - floor($stock->current_quantity)) * setting('general.eggs_per_tray', 30)) }} {{ __("Œufs") }}
                        </p>
                    @elseif($stock->category == 'conso' && $stock->unit == 'KG')
                        <p class="text-[9px] text-emerald-600 mt-3 uppercase font-black italic bg-emerald-50 px-3 py-1 rounded-lg inline-block">
                            ≈ {{ number_format($stock->current_quantity / 50, 1) }} {{ __("Sacs (50kg)") }}
                        </p>
                    @endif
                    <i class="fa-solid fa-boxes-stacked absolute -right-2 -bottom-2 text-slate-50 text-5xl group-hover:scale-110 transition-transform"></i>
                </div>

                {{-- FLUX ENTRANT --}}
                <div class="bg-emerald-500 p-8 rounded-[3rem] text-white shadow-xl shadow-emerald-500/20 relative overflow-hidden">
                    <p class="text-[10px] font-black text-emerald-100 uppercase mb-2 italic text-left leading-none">{{ __("Entrées (30j)") }}</p>
                    <h3 class="text-4xl font-black tracking-tighter leading-none">+{{ number_format($stats['total_in'], 1, ',', ' ') }}</h3>
                    <p class="text-[8px] opacity-70 uppercase italic tracking-widest mt-1">{{ $stock->unit }}</p>
                    <i class="fa-solid fa-arrow-trend-up absolute right-6 bottom-6 opacity-20 text-3xl"></i>
                </div>

                {{-- FLUX SORTANT --}}
                <div class="bg-rose-500 p-8 rounded-[3rem] text-white shadow-xl shadow-rose-500/20 relative overflow-hidden">
                    <p class="text-[10px] font-black text-rose-100 uppercase mb-2 italic text-left leading-none">{{ __("Sorties (30j)") }}</p>
                    <h3 class="text-4xl font-black tracking-tighter leading-none">-{{ number_format($stats['total_out'], 1, ',', ' ') }}</h3>
                    <p class="text-[8px] opacity-70 uppercase italic tracking-widest mt-1">{{ $stock->unit }}</p>
                    <i class="fa-solid fa-arrow-trend-down absolute right-6 bottom-6 opacity-20 text-3xl"></i>
                </div>

                {{-- VALEUR FINANCIÈRE --}}
                <div class="bg-slate-900 p-8 rounded-[3rem] text-white shadow-2xl text-left border-l-8 border-amber-400">
                    <p class="text-[10px] font-black text-slate-400 uppercase mb-2 italic leading-none">{{ __("Valeur Valorisée") }}</p>
                    <h3 class="text-3xl font-black text-amber-400 tracking-tighter leading-none">
                        {{-- Valorisation au COÛT MOYEN PONDÉRÉ via l'accesseur total_value
                             (current_quantity × last_unit_price) : source unique partagée
                             avec tableaux de bord et rapports. --}}
                        {{ number_format($stock->total_value, 0, ',', ' ') }}
                        <span class="text-[10px] text-white opacity-50 uppercase font-black">{{ currency() }}</span>
                    </h3>
                    <p class="text-[8px] text-slate-500 uppercase italic mt-2 font-black leading-none tracking-widest">
                        {{ __("PU (CMP) :") }} {{ number_format($stock->last_unit_price ?? 0, 0, ',', ' ') }} / {{ $stock->unit }}
                    </p>
                </div>
            </div>

            {{-- 2. HISTORIQUE DÉTAILLÉ (L) --}}
            <div class="bg-white rounded-[3.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="p-8 border-b border-slate-50 flex justify-between items-center bg-slate-50/30">
                    <h4 class="text-sm font-black uppercase tracking-widest text-slate-800 italic leading-none">📋 {{ __("Registre des mouvements de stock") }}</h4>
                    <span class="px-4 py-2 bg-white border border-slate-100 rounded-full text-[8px] font-black text-slate-400 uppercase italic tracking-widest shadow-sm">
                        {{ __("Audit :") }} {{ $movements->count() }} {{ __("lignes") }}
                    </span>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[9px] font-black text-slate-400 uppercase tracking-widest italic bg-slate-50/50">
                                <th class="px-8 py-5">{{ __("Horodatage") }}</th>
                                <th class="px-4 py-5 text-center">{{ __("Opérateur") }}</th>
                                <th class="px-4 py-5 text-center">{{ __("Flux") }}</th>
                                <th class="px-4 py-5 text-center">{{ __("Impact") }}</th>
                                <th class="px-8 py-5 text-right italic">{{ __("Justification") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($movements as $m)
                                <tr class="hover:bg-slate-50/80 transition-all italic font-bold text-xs">
                                    <td class="px-8 py-6 font-black text-slate-400 uppercase text-[10px] leading-tight">
                                        {{ $m->created_at->format('d M Y') }} <br>
                                        <span class="text-slate-300 font-normal italic lowercase text-[8px]">{{ $m->created_at->format('H:i') }}</span>
                                    </td>
                                    <td class="px-4 py-6 text-center italic text-slate-600 text-[10px]">
                                        <div class="flex flex-col items-center">
                                            <span class="uppercase font-black leading-none">{{ $m->user->name ?? __("Système") }}</span>
                                            <span class="text-[7px] text-slate-300 mt-1 uppercase">{{ $m->user->role ?? 'N/A' }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-6 text-center">
                                        <span @class([
                                            'px-4 py-1.5 rounded-full text-[8px] font-black italic uppercase tracking-widest border', 
                                            'bg-emerald-100 text-emerald-600 border-emerald-200' => $m->type == 'in',
                                            'bg-rose-100 text-rose-600 border-rose-200' => $m->type == 'out',
                                            'bg-slate-100 text-slate-500 border-slate-200' => $m->type == 'adjustment'])>
                                            <i class="fa-solid {{ $m->type == 'in' ? 'fa-plus' : ($m->type == 'out' ? 'fa-minus' : 'fa-equals') }} mr-1 text-[7px]"></i>
                                            {{ $m->type }}
                                        </span>
                                    </td>
                                    <td @class([
                                        'px-4 py-6 text-center font-black text-sm tracking-tighter', 
                                        'text-emerald-500' => $m->type == 'in', 
                                        'text-rose-500' => $m->type == 'out',
                                        'text-slate-900' => $m->type == 'adjustment'
                                    ])>
                                        {{ $m->type == 'in' ? '+' : ($m->type == 'out' ? '-' : '•') }} 
                                        {{ number_format(abs($m->quantity), 2, ',', ' ') }}
                                        <span class="text-[8px] opacity-40 ml-1 font-black">{{ $stock->unit }}</span>
                                    </td>
                                    <td class="px-8 py-6 text-right text-slate-400 italic text-[10px] leading-tight max-w-xs">
                                        {{ $m->notes ?? __("Aucune note renseignée") }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-8 py-20 text-center">
                                        <i class="fa-solid fa-folder-open text-slate-100 text-4xl mb-4"></i>
                                        <p class="text-[10px] font-black text-slate-300 uppercase italic tracking-widest">{{ __("Aucun mouvement enregistré pour cet article") }}</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>