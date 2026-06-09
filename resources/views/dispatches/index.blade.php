<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <div class="w-14 h-14 bg-orange-500 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl rotate-3">
                    <i class="fa-solid fa-truck-fast text-xl"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">Expéditions</h2>
                    <p class="text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] mt-2 italic">
                        Transfert de garde — Triple validation anti-fraude
                    </p>
                </div>
            </div>
            <div class="flex gap-4">
                @if($stats['in_dispute'] > 0)
                <a href="{{ route('dispatches.discrepancies') }}" class="bg-red-500 text-white px-6 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-red-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline animate-pulse">
                    <i class="fa-solid fa-triangle-exclamation"></i> {{ $stats['in_dispute'] }} litige(s)
                </a>
                @endif
                @can('C')
                <a href="{{ route('dispatches.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-orange-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-truck-ramp-box"></i> Nouvelle Expédition
                </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @foreach(['success', 'warning', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['mb-8 p-5 rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success',
                        'bg-amber-500 text-white' => $msg === 'warning',
                        'bg-red-500 text-white' => $msg === 'error',
                    ])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : ($msg === 'warning' ? 'triangle-exclamation' : 'circle-xmark') }} mr-3 text-lg"></i>
                        {{ session($msg) }}
                    </div>
                @endif
            @endforeach

            {{-- STATS --}}
            <div class="grid grid-cols-3 gap-4 mb-8">
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">Aujourd'hui</p>
                    <p class="text-2xl font-black text-slate-900">{{ $stats['today'] }}</p>
                </div>
                <div class="bg-amber-50 p-5 rounded-[2rem] border border-amber-200 text-center">
                    <p class="text-[8px] font-black text-amber-500 uppercase tracking-widest mb-1">En attente de réception</p>
                    <p class="text-2xl font-black text-amber-600">{{ $stats['pending'] }}</p>
                </div>
                <div @class(['p-5 rounded-[2rem] border text-center', $stats['in_dispute'] > 0 ? 'bg-red-50 border-red-200' : 'bg-emerald-50 border-emerald-200'])>
                    <p class="text-[8px] font-black uppercase tracking-widest mb-1 {{ $stats['in_dispute'] > 0 ? 'text-red-500' : 'text-emerald-500' }}">Litiges ouverts</p>
                    <p class="text-2xl font-black {{ $stats['in_dispute'] > 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ $stats['in_dispute'] }}</p>
                </div>
            </div>

            {{-- FILTRES --}}
            <form method="GET" class="mb-8 flex flex-wrap gap-3 items-center">
                <select name="status" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none">
                    <option value="">Tous statuts</option>
                    <option value="prepare" {{ request('status') === 'prepare' ? 'selected' : '' }}>Préparé</option>
                    <option value="expedie" {{ request('status') === 'expedie' ? 'selected' : '' }}>Expédié</option>
                    <option value="en_route" {{ request('status') === 'en_route' ? 'selected' : '' }}>En route</option>
                    <option value="receptionne" {{ request('status') === 'receptionne' ? 'selected' : '' }}>Réceptionné</option>
                    <option value="clos" {{ request('status') === 'clos' ? 'selected' : '' }}>Clos</option>
                </select>
                <button type="submit" class="bg-slate-900 text-white px-5 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest border-none cursor-pointer">Filtrer</button>
            </form>

            {{-- TABLEAU --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                                <th class="px-6 py-5 text-left">N° Expédition</th>
                                <th class="px-4 py-5 text-left">Destination</th>
                                <th class="px-4 py-5 text-left">Chauffeur</th>
                                <th class="px-4 py-5 text-center">Date</th>
                                <th class="px-4 py-5 text-center">Statut</th>
                                <th class="px-4 py-5 text-center">Réception</th>
                                <th class="px-6 py-5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($dispatches as $dispatch)
                            <tr class="hover:bg-slate-50/50 transition-all">
                                <td class="px-6 py-4">
                                    <a href="{{ route('dispatches.show', $dispatch) }}" class="no-underline">
                                        <p class="text-xs font-black text-slate-900 uppercase">{{ $dispatch->dispatch_number }}</p>
                                        <p class="text-[8px] text-slate-400 font-black uppercase tracking-widest">{{ $dispatch->dispatcher->name ?? '' }}</p>
                                    </a>
                                </td>
                                <td class="px-4 py-4 text-[10px] font-black text-slate-700 uppercase">{{ $dispatch->destination }}</td>
                                <td class="px-4 py-4">
                                    <p class="text-[10px] font-black text-slate-700">{{ $dispatch->driver_name }}</p>
                                    <p class="text-[8px] text-slate-400">{{ $dispatch->vehicle_plate ?? '' }}</p>
                                </td>
                                <td class="px-4 py-4 text-center text-[10px] font-black text-slate-500">{{ $dispatch->dispatch_date->format('d/m/Y') }}</td>
                                <td class="px-4 py-4 text-center">
                                    <span @class([
                                        'text-[8px] font-black uppercase px-3 py-1 rounded-full tracking-widest',
                                        'bg-slate-100 text-slate-500' => $dispatch->status === 'prepare',
                                        'bg-blue-50 text-blue-600' => $dispatch->status === 'expedie',
                                        'bg-amber-50 text-amber-600' => $dispatch->status === 'en_route',
                                        'bg-emerald-50 text-emerald-600' => $dispatch->status === 'receptionne',
                                        'bg-slate-900 text-white' => $dispatch->status === 'clos',
                                    ])>{{ str_replace('_', ' ', $dispatch->status) }}</span>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    @if($dispatch->reception)
                                        <span @class([
                                            'text-[8px] font-black uppercase px-3 py-1 rounded-full',
                                            'bg-emerald-50 text-emerald-600' => $dispatch->reception->status === 'valide',
                                            'bg-red-50 text-red-600' => $dispatch->reception->status === 'litige',
                                            'bg-amber-50 text-amber-600' => $dispatch->reception->status === 'en_attente',
                                        ])>{{ $dispatch->reception->status === 'litige' ? '⚠ LITIGE' : ($dispatch->reception->status === 'valide' ? '✓ OK' : 'En attente') }}</span>
                                    @else
                                        <span class="text-[8px] font-black text-slate-300 uppercase">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right flex gap-2 justify-end">
                                    @if(!$dispatch->reception && in_array($dispatch->status, ['expedie', 'en_route']))
                                        <a href="{{ route('dispatches.reception.create', $dispatch) }}" class="text-emerald-500 hover:text-emerald-700 no-underline text-xs" title="Réceptionner">
                                            <i class="fa-solid fa-clipboard-check"></i>
                                        </a>
                                    @endif
                                    <a href="{{ route('dispatches.show', $dispatch) }}" class="text-slate-400 hover:text-slate-700 no-underline text-xs" title="Détails">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="px-8 py-16 text-center">
                                    <i class="fa-solid fa-truck text-slate-200 text-3xl mb-4 block"></i>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">Aucune expédition</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6">{{ $dispatches->withQueryString()->links() }}</div>
        </div>
    </div>
</x-app-layout>
