<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left italic font-bold">
            <div>
                <h2 class="text-3xl font-black text-slate-800 tracking-tighter uppercase italic leading-none">
                    {{ __('Registre des Suivis Quotidiens') }}
                </h2>
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mt-3 italic leading-none">
                    {{ __("Pointage technique & Consommation — Registre des Suivis Quotidiens") }}
                </p>
            </div>
            <a href="{{ route('batches.index') }}" class="group flex items-center justify-center w-12 h-12 bg-white border border-slate-200 text-slate-400 hover:text-slate-900 rounded-2xl transition-all shadow-sm no-underline">
                <i class="fas fa-times group-hover:rotate-90 transition-transform"></i>
            </a>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <form action="{{ route('daily-checks.index') }}" method="GET" class="mb-8 bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex flex-wrap gap-4 items-center text-left">
                <div class="flex-1 min-w-[200px]">
                    <label class="text-[8px] uppercase text-slate-400 ml-2 mb-1 block font-black">{{ __("Rechercher un Lot") }}</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('CODE DU LOT...') }}" class="w-full bg-slate-50 border-none rounded-xl text-[10px] font-black uppercase shadow-inner focus:ring-2 focus:ring-blue-500 transition-all italic">
                </div>
                <div class="w-48">
                    <label class="text-[8px] uppercase text-slate-400 ml-2 mb-1 block font-black">{{ __("Trier par") }}</label>
                    <select name="sort" onchange="this.form.submit()" class="w-full bg-slate-50 border-none rounded-xl text-[10px] font-black uppercase shadow-inner focus:ring-2 focus:ring-blue-500 transition-all italic appearance-none cursor-pointer">
                        <option value="latest" {{ request('sort') == 'latest' ? 'selected' : '' }}>{{ __("Plus récents") }}</option>
                        <option value="critical" {{ request('sort') == 'critical' ? 'selected' : '' }}>{{ __("Mortalité critique") }}</option>
                    </select>
                </div>
            </form>

            <div class="bg-white rounded-[3rem] shadow-sm border border-slate-100 overflow-hidden text-left">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100 text-[10px] font-black uppercase text-slate-400 italic tracking-widest">
                            <th class="px-8 py-6">📅 {{ __("Date") }}</th>
                            <th class="px-8 py-6">📦 {{ __("Lot / Bâtiment") }}</th>
                            <th class="px-8 py-6 text-center text-red-500">💀 {{ __("Mortalité") }}</th>
                            <th class="px-8 py-6 text-center text-blue-500">🌾 {{ __("Aliment (Kg)") }}</th>
                            <th class="px-8 py-6 text-center text-cyan-500">💧 {{ __("Eau (L)") }}</th>
                            @canany(['elevage.M', 'elevage.S'])
                            <th class="px-8 py-6 text-right">{{ __("Actions") }}</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($dailyChecks as $check)
                        <tr class="hover:bg-slate-50/50 transition-colors group">
                            <td class="px-8 py-6 text-sm text-slate-800">
                                <span class="bg-slate-100 px-3 py-1 rounded-lg font-black text-[11px]">{{ \Carbon\Carbon::parse($check->check_date)->format('d/m/Y') }}</span>
                            </td>
                            <td class="px-8 py-6">
                                <div class="flex flex-col">
                                    <span class="text-sm font-black text-slate-700 leading-none group-hover:text-blue-600 transition-colors">{{ $check->batch->code }}</span>
                                    <span class="text-[9px] text-slate-400 uppercase tracking-widest mt-1">{{ $check->batch->building->name }}</span>
                                </div>
                            </td>
                            <td class="px-8 py-6 text-center">
                                <span @class([
                                    'text-lg font-black italic',
                                    'text-red-600 animate-pulse' => $check->mortality > ($check->batch->current_quantity * 0.01),
                                    'text-slate-800' => $check->mortality > 0 && $check->mortality <= ($check->batch->current_quantity * 0.01),
                                    'text-slate-300' => $check->mortality == 0
                                ])>
                                    {{ $check->mortality }}
                                </span>
                            </td>
                            <td class="px-8 py-6 text-center">
                                <div class="flex flex-col items-center">
                                    <span class="text-blue-600 text-lg font-black italic">{{ number_format($check->feed_consumed, 1) }}</span>
                                    @if($check->feed_type)
                                        <span class="px-2 py-0.5 bg-blue-50 text-blue-400 rounded text-[7px] uppercase tracking-tighter mt-1">{{ str_replace(['Chair ', 'Ponte '], '', $check->feed_type) }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-8 py-6 text-center text-cyan-600 font-black text-lg italic">
                                {{ $check->water_consumed ?? '-' }}
                            </td>
                            <td class="px-8 py-6 text-right">
                                <div class="flex justify-end gap-2">
                                    @can('elevage.M')
                                    <a href="{{ route('daily-checks.edit', $check->id) }}" class="p-3 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-xl transition-all shadow-sm hover:shadow-md" title="{{ __('Modifier') }}">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
                                    @endcan

                                    @can('elevage.S')
                                    <form action="{{ route('daily-checks.destroy', $check->id) }}" method="POST" onsubmit="return confirm({{ Js::from(__("⚠️ Action irréversible. Le stock d'aliment sera restitué. Supprimer ?")) }})">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="p-3 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-xl transition-all shadow-sm hover:shadow-md cursor-pointer" title="{{ __('Supprimer') }}">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-8 py-32 text-center">
                                <div class="flex flex-col items-center opacity-10">
                                    <i class="fa-solid fa-clipboard-list text-8xl mb-4"></i>
                                    <p class="uppercase text-sm tracking-[0.5em] font-black italic">{{ __("Registre Vierge") }}</p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            
            <div class="mt-10 px-4">
                {{ $dailyChecks->links() }}
            </div>
        </div>
    </div>
</x-app-layout>