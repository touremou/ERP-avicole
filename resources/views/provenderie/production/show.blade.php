<x-app-layout>
    <div class="py-12 bg-white min-h-screen">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold">
            
            {{-- ZONE D'ACTION (Masquée à l'impression) --}}
            <div class="mb-8 flex justify-between items-center print:hidden">
                <a href="{{ route('production.index') }}" class="text-slate-400 hover:text-slate-900 transition-colors uppercase text-[10px] tracking-widest flex items-center no-underline">
                    <i class="fa-solid fa-arrow-left mr-2"></i> {{ __("Retour au journal") }}
                </a>
                <div class="flex gap-4">
                    <a href="{{ route('production.label', $production->id) }}" target="_blank"
                       class="bg-lime-50 text-lime-700 px-6 py-4 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-lime-600 hover:text-white transition-all no-underline"
                       title="{{ __("Étiquette QR de traçabilité") }}">
                        <i class="fa-solid fa-qrcode mr-2"></i> {{ __("Étiquette") }}
                    </a>
                    {{-- Permission M : Clôture de la production --}}
                    @can('provenderie.M')
                        @if($production->status === 'Planifié' || $production->status === 'En cours')
                            <form action="{{ route('production.complete', $production->id) }}" method="POST" onsubmit="return confirm(@json(__('Confirmer la fin du mélange ? Les stocks et compteurs machines seront mis à jour.')))">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="bg-emerald-500 text-white px-8 py-4 rounded-2xl text-[10px] font-black uppercase tracking-widest shadow-xl shadow-emerald-500/20 hover:bg-emerald-600 transition-all">
                                    <i class="fa-solid fa-check-double mr-2"></i> {{ __("Clôturer & Stocker") }}
                                </button>
                            </form>
                        @endif
                    @endcan
                    
                    {{-- Permission L : Impression --}}
                    @can('provenderie.L')
                    <button onclick="window.print()" class="bg-slate-900 text-white px-8 py-4 rounded-2xl text-[10px] font-black uppercase tracking-widest shadow-xl hover:bg-slate-700 transition-all">
                        <i class="fa-solid fa-print mr-2"></i> {{ __("Imprimer le Bon") }}
                    </button>
                    @endcan
                </div>
            </div>

            {{-- MESSAGE DE STATUT (Visible si Terminé) --}}
            @if($production->status === 'Terminé')
                <div class="mb-8 p-6 bg-emerald-50 border-2 border-emerald-200 rounded-[2rem] flex items-center justify-between print:hidden">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 bg-emerald-500 rounded-xl flex items-center justify-center text-white shadow-lg">
                            <i class="fa-solid fa-warehouse"></i>
                        </div>
                        <div class="text-left">
                            <p class="text-[10px] text-emerald-600 uppercase leading-none font-black italic">{{ __("Production Clôturée") }}</p>
                            <p class="text-xs font-black text-slate-800 uppercase mt-1 italic tracking-tighter">{{ __("Stocks et Maintenance mis à jour") }}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] text-slate-400 uppercase leading-none italic font-black">{{ __("Coût de revient réel") }}</p>
                        <p class="text-lg font-black text-emerald-600 italic leading-none mt-1">
                            {{ number_format($production->real_cost_per_kg ?? 0, 0, ',', ' ') }} <small class="text-[10px]">{{ currency() }}/KG</small>
                        </p>
                    </div>
                </div>
            @endif

            {{-- LE BON DE PESÉE (Format Document) --}}
            <div class="border-4 border-slate-900 p-10 rounded-[3rem] relative overflow-hidden bg-white shadow-2xl print:shadow-none print:border-2">
                {{-- Entête --}}
                <div class="flex justify-between items-start border-b-4 border-slate-900 pb-8 mb-8 text-left">
                    <div>
                        <h1 class="text-4xl font-black text-slate-900 uppercase italic tracking-tighter leading-none">{{ __("Bon de Pesée") }}</h1>
                        <p class="text-sm text-slate-500 uppercase mt-2 tracking-[0.3em] font-bold">{{ __("Ordre") }} : {{ $production->batch_number ?? 'OP-'.str_pad($production->id, 5, '0', STR_PAD_LEFT) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] uppercase text-slate-400 leading-none mb-1 italic font-black">{{ __("Date d'émission") }}</p>
                        <p class="text-lg font-black text-slate-900 leading-none italic">{{ $production->created_at->format('d/m/Y H:i') }}</p>
                    </div>
                </div>

                {{-- Détails du Lot --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-12 text-left">
                    <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100">
                        <p class="text-[9px] uppercase text-slate-400 mb-2 font-black italic">{{ __("Formule active") }}</p>
                        <p class="text-xl font-black text-slate-900 uppercase italic leading-tight tracking-tighter">{{ $production->formula->name ?? __("N/A") }}</p>
                    </div>

                    <div class="bg-slate-900 p-6 rounded-3xl text-white shadow-xl">
                        <p class="text-[9px] uppercase text-slate-400 mb-2 font-black italic opacity-70">{{ __("Masse Totale Cible") }}</p>
                        <p class="text-3xl font-black italic leading-none tracking-tighter">{{ number_format($production->quantity_produced, 0, ',', ' ') }} <small class="text-sm uppercase">kg</small></p>
                        <p class="text-[10px] uppercase mt-2 opacity-50 font-black italic">{{ number_format($production->quantity_produced / 50, 1) }} {{ __("Sacs (50kg)") }}</p>
                    </div>

                    <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100">
                        <p class="text-[9px] uppercase text-slate-400 mb-2 font-black italic">{{ __("Ligne de Production") }}</p>
                        <div class="flex flex-col gap-1">
                            @if($production->machines && $production->machines->count() > 0)
                                @foreach($production->machines as $m)
                                    <p class="text-xs font-black text-slate-800 uppercase italic leading-none flex items-center">
                                        <i class="fa-solid fa-gear mr-1.5 text-[8px] text-emerald-500"></i> {{ $m->name }}
                                    </p>
                                @endforeach
                            @else
                                <p class="text-xs font-black text-slate-800 uppercase italic leading-none flex items-center">
                                    <i class="fa-solid fa-gear mr-1.5 text-[8px] text-slate-300"></i> {{ $production->machine->name ?? __("Standard") }}
                                </p>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- TABLEAU DE DOSAGE --}}
                <div class="mb-6 flex items-center gap-4">
                    <h3 class="text-xs font-black uppercase tracking-[0.4em] text-slate-400 italic leading-none">{{ __("Instructions de dosage (MP)") }}</h3>
                    <div class="flex-1 h-px bg-slate-100"></div>
                </div>
                
                <table class="w-full mb-12 text-left">
                    <thead>
                        <tr class="border-b-2 border-slate-200">
                            <th class="py-4 text-left text-[10px] uppercase font-black italic">{{ __("Ingrédient") }}</th>
                            <th class="py-4 text-center text-[10px] uppercase font-black italic px-4">{{ __("Part %") }}</th>
                            <th class="py-4 text-right text-[10px] uppercase font-black italic bg-slate-50 rounded-t-xl px-4">{{ __("Poids à Peser") }}</th>
                            <th class="py-4 text-center text-[10px] uppercase font-black italic px-4">{{ __("Visa") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($production->formula->items as $item)
                        @php
                            $toWeigh = ($item->percentage / 100) * $production->quantity_produced;
                        @endphp
                        <tr class="group">
                            <td class="py-6">
                                <p class="text-lg font-black text-slate-900 uppercase italic leading-none tracking-tighter">{{ $item->rawMaterial->name }}</p>
                                <p class="text-[8px] text-slate-400 uppercase mt-1 font-bold italic tracking-widest">{{ __("Silo / Magasin MP") }}</p>
                            </td>
                            <td class="py-6 text-center text-slate-400 font-black italic">{{ number_format($item->percentage, 1) }}%</td>
                            <td class="py-6 text-right bg-slate-50/50 px-4">
                                <span class="text-2xl font-black text-slate-900 italic tracking-tighter">{{ number_format($toWeigh, 1, ',', ' ') }}</span>
                                <small class="text-xs uppercase text-slate-400 ml-1">kg</small>
                            </td>
                            <td class="py-6 text-center px-4">
                                <div class="w-8 h-8 border-2 border-slate-200 rounded-lg mx-auto flex items-center justify-center">
                                    @if($production->status === 'Terminé')
                                        <i class="fa-solid fa-check text-emerald-500"></i>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-4 border-slate-900">
                            <td colspan="2" class="py-6 text-right uppercase text-[10px] font-black italic tracking-widest pr-8">{{ __("Total Mélange Net :") }}</td>
                            <td class="py-6 text-right font-black text-2xl italic tracking-tighter bg-slate-900 text-white px-4 rounded-b-xl shadow-lg">
                                {{ number_format($production->quantity_produced, 0, ',', ' ') }} <small class="text-xs uppercase">kg</small>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>

                {{-- Signatures --}}
                <div class="grid grid-cols-2 gap-12 mt-12 pt-12 border-t-2 border-dashed border-slate-100 italic">
                    <div class="text-center">
                        <p class="text-[8px] uppercase text-slate-400 mb-10 font-black tracking-widest">{{ __("Opérateur de Pesée") }} ({{ $production->user->name ?? __("Admin") }})</p>
                        {{-- <p class="text-[8px] uppercase text-slate-400 mb-10 font-black tracking-widest">Opérateur de Pesée (@if($prod->supervisor)
                                                    {{ strtoupper($prod->supervisor->first_name) }} {{ strtoupper($prod->supervisor->last_name) }}
                                                @else
                                                    {{ $prod->user->name ?? 'SYSTÈME' }}
                                                @endif)</p> --}}
                        <div class="h-px bg-slate-200 w-48 mx-auto"></div>
                    </div>
                    <div class="text-center">
                        <p class="text-[8px] uppercase text-slate-400 mb-10 font-black tracking-widest">{{ __("Responsable Provenderie") }}</p>
                        <div class="h-px bg-slate-200 w-48 mx-auto"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        @media print {
            body { background: white !important; -webkit-print-color-adjust: exact !important; }
            nav, .print\:hidden, header { display: none !important; }
            .py-12 { padding: 0 !important; }
            .max-w-4xl { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .rounded-\[3rem\], .rounded-3xl { border-radius: 0 !important; }
            .border-4 { border-width: 2px !important; border-color: black !important; }
            .bg-slate-900 { background-color: #0f172a !important; color: white !important; }
            .bg-slate-50 { background-color: #f8fafc !important; }
            .text-emerald-600 { color: #059669 !important; }
            .shadow-xl, .shadow-2xl { shadow: none !important; }
        }
    </style>
</x-app-layout>