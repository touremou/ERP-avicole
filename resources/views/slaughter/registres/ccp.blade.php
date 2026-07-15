<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Registre CCP')" :subtitle="__('Points critiques HACCP 1-4 — registre opposable')" icon="fa-shield-halved" accent="rose">
            <x-slot name="actions">
                @can('abattoir.C')
                <a href="{{ route('slaughter.registres.ccp.create') }}" class="bg-rose-500 text-white px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-rose-600 transition-all shadow-lg italic no-underline"><i class="fa-solid fa-plus mr-1"></i> {{ __("Saisir un relevé") }}</a>
                @endcan
                <a href="{{ route('slaughter.registres.export', array_filter(['type' => 'ccp', 'from' => request('from'), 'to' => request('to')])) }}" class="bg-slate-900 text-white px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-slate-700 transition-all shadow-lg italic no-underline"><i class="fa-solid fa-file-pdf mr-1"></i> {{ __("Export PDF") }}</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- FILTRES --}}
            <form method="GET" class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm mb-6 grid grid-cols-2 md:grid-cols-4 gap-3 items-end">
                <div class="space-y-1">
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Point critique") }}</label>
                    <select name="ccp" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black uppercase shadow-inner outline-none">
                        <option value="">{{ __("Tous") }}</option>
                        @foreach(\App\Models\CcpRecord::CCPS as $ccp)
                            <option value="{{ $ccp }}" @selected(request('ccp') === $ccp)>{{ \App\Models\CcpRecord::labelFor($ccp) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="space-y-1">
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Du") }}</label>
                    <input type="date" name="from" value="{{ request('from') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none">
                </div>
                <div class="space-y-1">
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Au") }}</label>
                    <input type="date" name="to" value="{{ request('to') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none">
                </div>
                <button type="submit" class="bg-slate-900 text-white p-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-rose-600 transition-all border-none cursor-pointer italic"><i class="fa-solid fa-filter mr-1"></i> {{ __("Filtrer") }}</button>
            </form>

            {{-- TABLEAU --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-5 py-4 text-left">{{ __("CCP") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Ordre / Équipement") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Mesures") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Conforme") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Action corrective") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Opérateur") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Relevé le") }}</th>
                            <th class="px-5 py-4 text-center">{{ __("Synchronisé le") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($records as $rec)
                        <tr @class(['hover:bg-slate-50/50 transition-all', 'bg-red-50/30' => ! $rec->conforme])>
                            <td class="px-5 py-4 text-[10px] font-black text-slate-800 uppercase">{{ \App\Models\CcpRecord::labelFor($rec->ccp) }}</td>
                            <td class="px-3 py-4">
                                @if($rec->slaughterOrder)
                                    <p class="text-[10px] font-black text-slate-700 uppercase">
                                        {{ $rec->slaughterOrder->order_number }}
                                        @if($rec->slaughterOrder->isBlocked())
                                            <span class="text-[7px] font-black text-red-700 bg-red-100 px-2 py-0.5 rounded-full ml-1">{{ __("BLOQUÉ") }}</span>
                                        @endif
                                    </p>
                                @endif
                                @if($rec->equipment_ref)<p class="text-[8px] text-slate-400 font-black">{{ $rec->equipment_ref }}</p>@endif
                                @if(! $rec->slaughterOrder && ! $rec->equipment_ref)<span class="text-slate-300">—</span>@endif
                            </td>
                            <td class="px-3 py-4 text-[9px] text-slate-600 font-bold">
                                @foreach($rec->mesures ?? [] as $k => $v)
                                    <p class="m-0">{{ str_replace('_', ' ', $k) }} : <span class="text-slate-900 font-black">{{ is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}</span></p>
                                @endforeach
                            </td>
                            <td class="px-3 py-4 text-center">
                                @if($rec->conforme)
                                    <span class="text-[8px] font-black text-emerald-600 bg-emerald-50 px-2 py-1 rounded-full uppercase">{{ __("Conforme") }}</span>
                                @else
                                    <span class="text-[8px] font-black text-red-700 bg-red-100 px-2 py-1 rounded-full uppercase animate-pulse">{{ __("NON CONFORME") }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-4 text-[9px] text-slate-500 font-bold max-w-[200px]">{{ $rec->corrective_action ?: '—' }}</td>
                            <td class="px-3 py-4 text-[9px] font-black text-slate-600 uppercase">{{ $rec->operator?->name ?? '—' }}</td>
                            <td class="px-3 py-4 text-center text-[9px] font-black text-slate-600">{{ $rec->releve_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="px-5 py-4 text-center text-[9px] font-black text-slate-400">{{ $rec->synced_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="px-8 py-16 text-center">
                                <i class="fa-solid fa-shield-halved text-slate-200 text-3xl mb-4 block"></i>
                                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">{{ __("Aucun relevé CCP") }}</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div class="mt-6">{{ $records->links() }}</div>
        </div>
    </div>
</x-app-layout>
