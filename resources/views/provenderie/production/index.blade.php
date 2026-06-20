<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-center gap-6">
            <div class="flex items-center gap-4 text-left">
                <div class="w-14 h-14 bg-emerald-500 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl rotate-3">
                    <i class="fa-solid fa-industry text-xl"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">
                        {{ __("Journal de Production") }}
                    </h2>
                    <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-[0.3em] mt-2 italic leading-none">
                        {{ __("Provenderie • Historique & Ordres de Production") }}
                    </p>
                </div>
            </div>

            <div class="flex gap-4">
                {{-- Permission L : Accès au parc machines --}}
                @can('provenderie.L')
                <a href="{{ route('machines.index') }}" 
                    class="bg-slate-50 text-slate-600 border border-slate-200 px-8 py-4 rounded-[2rem] text-[10px] font-black uppercase italic tracking-widest shadow-sm hover:bg-slate-100 transition-all active:scale-95">
                    <i class="fa-solid fa-gears mr-2"></i> {{ __("Parc Machines") }}
                </a>
                @endcan

                {{-- Permission C : Création d'un nouvel ordre --}}
                @can('provenderie.C')
                <a href="{{ route('production.create') }}" 
                    class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] text-[10px] font-black uppercase italic tracking-widest shadow-2xl hover:bg-emerald-500 transition-all active:scale-95">
                    <i class="fa-solid fa-plus mr-2 text-emerald-400"></i> {{ __("Nouvel Ordre (OP)") }}
                </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold">
            
            <div class="bg-white rounded-[3.5rem] border border-slate-100 shadow-sm overflow-hidden text-left">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="p-6 text-[10px] font-black uppercase tracking-widest italic text-slate-400 leading-none">{{ __("Date & Lot") }}</th>
                            <th class="p-6 text-[10px] font-black uppercase tracking-widest italic text-slate-400 leading-none text-left">{{ __("Formulation & Détails") }}</th>
                            <th class="p-6 text-[10px] font-black uppercase tracking-widest italic text-slate-400 leading-none text-center">{{ __("Volume Produit") }}</th>
                            <th class="p-6 text-[10px] font-black uppercase tracking-widest italic text-slate-400 leading-none text-center">{{ __("Statut / Action") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($productions as $prod)
                        <tr class="hover:bg-slate-50/50 transition-colors group">
                            <td class="p-6">
                                <a href="{{ route('production.show', $prod->id) }}" class="flex items-center gap-2 group/link">
                                    <div>
                                        <p class="text-[10px] font-black text-slate-900 uppercase italic leading-none mb-1 group-hover/link:text-emerald-600 transition-colors">
                                            <i class="fa-solid fa-barcode mr-1 opacity-50"></i>
                                            {{ $prod->batch_number ?? 'OP-'.str_pad($prod->id, 5, '0', STR_PAD_LEFT) }}
                                        </p>
                                        <p class="text-[8px] font-bold text-slate-400 uppercase tracking-widest leading-none">
                                            {{ $prod->created_at->translatedFormat('d M Y • H:i') }}
                                        </p>
                                    </div>
                                </a>
                            </td>
                            <td class="p-6 text-left">
                                <div class="flex items-center gap-3">
                                    <div @class([
                                        'w-8 h-8 rounded-lg flex items-center justify-center text-[10px]',
                                        'bg-emerald-50 text-emerald-600' => $prod->status === 'Terminé',
                                        'bg-amber-50 text-amber-600' => $prod->status !== 'Terminé'
                                    ])>
                                        <i class="fa-solid fa-flask-vial"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs font-black text-slate-800 uppercase italic leading-none">{{ $prod->formula->name ?? __("Formule Inconnue") }}</p>
                                        <div class="flex flex-wrap items-center gap-1 mt-1">
                                            
                                            {{-- Liste des machines utilisées --}}
                                            @if($prod->machines && $prod->machines->count() > 0)
                                                @foreach($prod->machines as $m)
                                                    <span class="text-[7px] bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded border border-slate-200 uppercase font-black italic">
                                                        <i class="fa-solid fa-gear mr-0.5 text-[6px]"></i>{{ $m->name }}
                                                    </span>
                                                @endforeach
                                            @elseif($prod->machine)
                                                <span class="text-[7px] bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded border border-slate-200 uppercase font-black italic">
                                                    <i class="fa-solid fa-gear mr-0.5 text-[6px]"></i>{{ $prod->machine->name }}
                                                </span>
                                            @endif
                                            
                                            {{-- Responsable Production --}}
                                            <span class="text-[7px] text-emerald-600 uppercase italic leading-none ml-2 border-l border-slate-200 pl-2 flex items-center gap-1">
                                                <i class="fa-solid fa-user-check text-[6px]"></i>
                                                {{ __("Resp") }} :
                                                @if($prod->supervisor)
                                                    {{ strtoupper($prod->supervisor->first_name) }} {{ strtoupper($prod->supervisor->last_name) }}
                                                @else
                                                    {{ $prod->user->name ?? __("SYSTÈME") }}
                                                @endif
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="p-6 text-center">
                                <p class="text-xl font-black text-slate-900 italic tracking-tighter leading-none">
                                    {{ number_format($prod->quantity_produced / 50, 1) }} 
                                    <small class="text-[8px] text-slate-400 uppercase ml-1 italic font-black">{{ __("Sacs (50kg)") }}</small>
                                </p>
                                <p class="text-[8px] text-blue-500 uppercase font-black leading-none mt-2">{{ __("Masse") }} : {{ number_format($prod->quantity_produced, 0) }} kg</p>
                            </td>
                            <td class="p-6 text-center">
                                @if($prod->status === 'Terminé')
                                    <div class="flex flex-col items-center gap-1">
                                        <span class="px-4 py-1.5 bg-emerald-50 text-emerald-600 rounded-full text-[8px] font-black uppercase italic tracking-widest border border-emerald-100">
                                            <i class="fa-solid fa-circle-check mr-1"></i> {{ __("Stocké") }}
                                        </span>
                                        <p class="text-[7px] text-slate-300 italic uppercase">{{ __("Fini le") }} {{ $prod->finished_at ? $prod->finished_at->format('d/m H:i') : $prod->updated_at->format('d/m H:i') }}</p>
                                    </div>
                                @else
                                    <div class="flex flex-col items-center gap-3">
                                        <div class="flex gap-2">
                                            <span class="px-4 py-1.5 bg-amber-50 text-amber-600 rounded-full text-[8px] font-black uppercase italic tracking-widest border border-amber-100 animate-pulse">
                                                <i class="fa-solid fa-spinner fa-spin mr-1"></i> {{ __("En cours") }}
                                            </span>
                                            {{-- Accès L pour voir le bon de pesée --}}
                                            @can('provenderie.L')
                                            <a href="{{ route('production.show', $prod->id) }}" class="p-1.5 bg-slate-900 text-white rounded-lg hover:bg-emerald-500 transition-all shadow-lg" title="{{ __('Imprimer Bon de Pesée') }}">
                                                <i class="fa-solid fa-print text-[10px]"></i>
                                            </a>
                                            @endcan
                                        </div>
                                        
                                        {{-- Permission M : Finalisation de la production --}}
                                        @can('provenderie.M')
                                        <form action="{{ route('production.complete', $prod->id) }}" method="POST" onsubmit="return confirm(@json(__('Confirmer la fin de production ? Le stock sera mis à jour.')))">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="text-[9px] font-black text-white uppercase italic tracking-widest bg-emerald-500 px-5 py-2 rounded-xl hover:bg-emerald-600 transition-all shadow-xl active:scale-95">
                                                {{ __("Terminer l'ordre") }} <i class="fa-solid fa-arrow-right ml-1"></i>
                                            </button>
                                        </form>
                                        @endcan
                                    </div>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="p-20 text-center">
                                <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6">
                                    <i class="fa-solid fa-industry text-2xl text-slate-200"></i>
                                </div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] italic leading-none">{{ __("Aucun historique de production") }}</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if(method_exists($productions, 'links'))
                <div class="mt-8">
                    {{ $productions->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>