<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Expéditions')" :subtitle="__('Transfert de garde — Triple validation anti-fraude')" icon="fa-truck-fast" accent="orange">
            <x-slot name="actions">
                @if($stats['in_dispute'] > 0)
                <a href="{{ route('dispatches.discrepancies') }}" class="bg-red-500 text-white px-6 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-red-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline animate-pulse">
                    <i class="fa-solid fa-triangle-exclamation"></i> {{ __(":count litige(s)", ['count' => $stats['in_dispute']]) }}
                </a>
                @endif
                @can('logistique.C')
                <a href="{{ route('dispatches.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-orange-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-truck-ramp-box"></i> {{ __("Nouvelle Expédition") }}
                </a>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- STATS --}}
            <div class="grid grid-cols-3 gap-4 mb-8">
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Aujourd'hui") }}</p>
                    <p class="text-2xl font-black text-slate-900">{{ $stats['today'] }}</p>
                </div>
                <div class="bg-amber-50 p-5 rounded-[2rem] border border-amber-200 text-center">
                    <p class="text-[8px] font-black text-amber-500 uppercase tracking-widest mb-1">{{ __("En attente de réception") }}</p>
                    <p class="text-2xl font-black text-amber-600">{{ $stats['pending'] }}</p>
                </div>
                <div @class(['p-5 rounded-[2rem] border text-center', $stats['in_dispute'] > 0 ? 'bg-red-50 border-red-200' : 'bg-emerald-50 border-emerald-200'])>
                    <p class="text-[8px] font-black uppercase tracking-widest mb-1 {{ $stats['in_dispute'] > 0 ? 'text-red-500' : 'text-emerald-500' }}">{{ __("Litiges ouverts") }}</p>
                    <p class="text-2xl font-black {{ $stats['in_dispute'] > 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ $stats['in_dispute'] }}</p>
                </div>
            </div>

            {{-- FILTRES --}}
            <form method="GET" class="mb-8 flex flex-wrap gap-3 items-center">
                <select name="status" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none">
                    <option value="">{{ __("Tous statuts") }}</option>
                    <option value="prepare" {{ request('status') === 'prepare' ? 'selected' : '' }}>{{ __("Préparé") }}</option>
                    <option value="expedie" {{ request('status') === 'expedie' ? 'selected' : '' }}>{{ __("Expédié") }}</option>
                    <option value="en_route" {{ request('status') === 'en_route' ? 'selected' : '' }}>{{ __("En route") }}</option>
                    <option value="receptionne" {{ request('status') === 'receptionne' ? 'selected' : '' }}>{{ __("Réceptionné") }}</option>
                    <option value="clos" {{ request('status') === 'clos' ? 'selected' : '' }}>{{ __("Clos") }}</option>
                </select>
                <button type="submit" class="bg-slate-900 text-white px-5 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest border-none cursor-pointer">{{ __("Filtrer") }}</button>
            </form>

            {{-- TABLEAU --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                                <th class="px-6 py-5 text-left">{{ __("N° Expédition") }}</th>
                                <th class="px-4 py-5 text-left">{{ __("Destination") }}</th>
                                <th class="px-4 py-5 text-left">{{ __("Chauffeur") }}</th>
                                <th class="px-4 py-5 text-center">{{ __("Date") }}</th>
                                <th class="px-4 py-5 text-center">{{ __("Statut") }}</th>
                                <th class="px-4 py-5 text-center">{{ __("Réception") }}</th>
                                <th class="px-6 py-5 text-right">{{ __("Actions") }}</th>
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
                                        ])>{{ $dispatch->reception->status === 'litige' ? __("⚠ LITIGE") : ($dispatch->reception->status === 'valide' ? __("✓ OK") : __("En attente")) }}</span>
                                    @else
                                        <span class="text-[8px] font-black text-slate-300 uppercase">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right flex gap-2 justify-end">
                                    @if(!$dispatch->reception && in_array($dispatch->status, ['expedie', 'en_route']))
                                        <a href="{{ route('dispatches.reception.create', $dispatch) }}" class="text-emerald-500 hover:text-emerald-700 no-underline text-xs" title="{{ __('Réceptionner') }}">
                                            <i class="fa-solid fa-clipboard-check"></i>
                                        </a>
                                    @endif
                                    <a href="{{ route('dispatches.show', $dispatch) }}" class="text-slate-400 hover:text-slate-700 no-underline text-xs" title="{{ __('Détails') }}">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="px-8 py-16 text-center">
                                    <i class="fa-solid fa-truck text-slate-200 text-3xl mb-4 block"></i>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">{{ __("Aucune expédition") }}</p>
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
