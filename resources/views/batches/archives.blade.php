<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Archives des Bandes')" :subtitle="__('Archives des bandes clôturées et réouvertures éventuelles')" icon="fa-box-archive" accent="indigo">
            <x-slot name="actions">
                {{-- FILTRE PAR BÂTIMENT (Visible pour tous ceux ayant la permission L) --}}
                <form action="{{ route('batches.archives') }}" method="GET" class="flex items-center gap-2 m-0">
                    <select name="building_id" onchange="this.form.submit()" class="bg-white border-slate-200 text-slate-600 rounded-xl text-[10px] font-black uppercase italic tracking-widest focus:ring-blue-500 shadow-sm px-4 py-3 cursor-pointer">
                        <option value="">{{ __("Tous les bâtiments") }}</option>
                        @foreach($buildings as $b)
                            <option value="{{ $b->id }}" {{ request('building_id') == $b->id ? 'selected' : '' }}>
                                🏠 {{ $b->name }}
                            </option>
                        @endforeach
                    </select>
                    @if(request('building_id'))
                        <a href="{{ route('batches.archives') }}" class="w-10 h-10 flex items-center justify-center bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white rounded-xl transition-all shadow-sm no-underline" title="{{ __("Réinitialiser le filtre") }}">
                            <i class="fas fa-times text-sm"></i>
                        </a>
                    @endif
                </form>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold">
            
            @if($errors->has('error'))
                <div class="mb-6 p-4 bg-red-500 text-white rounded-2xl shadow-lg text-xs font-black uppercase italic tracking-widest animate-bounce text-left">
                    <i class="fas fa-exclamation-triangle mr-2"></i> {{ $errors->first('error') }}
                </div>
            @endif

            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="text-[9px] font-black text-slate-400 uppercase tracking-widest bg-slate-50/50 italic">
                            <th class="px-8 py-5">{{ __("Lot / Unité") }}</th>
                            <th class="px-4 py-5">{{ __("Période") }}</th>
                            <th class="px-4 py-5 text-center text-red-500">{{ __("Mortalité") }}</th>
                            <th class="px-4 py-5 text-center">{{ __("CA Brut") }}</th>
                            <th class="px-4 py-5 text-center text-blue-600">{{ __("Performance Nette") }}</th>
                            <th class="px-8 py-5 text-right">{{ __("Actions") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($archivedBatches as $batch)
                            @php
                                $totalMorts = ($batch->qty_dead ?? 0) + $batch->dailyChecks->sum('mortality');
                                $rate = ($batch->initial_quantity > 0) ? ($totalMorts / $batch->initial_quantity) * 100 : 0;
                                
                                $chiffreAffaire = $batch->total_revenue ?? 0;
                                $coutPoussins = $batch->total_acquisition_cost ?? 0;
                                $coutAliment = $batch->feedPurchases->sum('total_price');
                                $coutSante = $batch->healthChecks->sum('cost');
                                $fraisAnnexes = $batch->additional_costs ?? 0;
                                
                                $totalDepenses = $coutPoussins + $coutAliment + $coutSante + $fraisAnnexes;
                                $realMargin = $chiffreAffaire - $totalDepenses;
                            @endphp
                            <tr class="hover:bg-slate-50/80 transition-all group">
                                <td class="px-8 py-6">
                                    <p class="font-black text-slate-800 uppercase tracking-tighter">{{ $batch->code }}</p>
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="text-[8px] px-2 py-0.5 bg-slate-100 rounded text-slate-500 uppercase">{{ $batch->type }}</span>
                                        <p class="text-[10px] font-bold text-blue-500 italic">{{ $batch->building->name ?? 'N/A' }}</p>
                                    </div>
                                </td>
                                <td class="px-4 py-6">
                                    <div class="flex flex-col">
                                        <span class="text-xs font-black text-slate-600 italic">{{ \Carbon\Carbon::parse($batch->arrival_date)->format('d/m/y') }}</span>
                                        <span class="text-[9px] text-slate-400 italic">{{ __("Clôture :") }} {{ $batch->closing_date ? \Carbon\Carbon::parse($batch->closing_date)->format('d/m/y') : $batch->updated_at->format('d/m/y') }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-6 text-center">
                                    <span class="text-sm font-black {{ $rate > 7 ? 'text-red-600' : 'text-slate-800' }}">
                                        {{ number_format($rate, 1) }}%
                                    </span>
                                </td>
                                <td class="px-4 py-6 text-center">
                                    <span class="text-xs font-bold text-slate-400 tracking-tighter">
                                        {{ number_format($batch->total_revenue ?? 0, 0, ',', ' ') }}
                                    </span>
                                </td>
                                <td class="px-4 py-6 text-center">
                                    <div class="flex flex-col items-center">
                                        <span class="text-sm font-black tracking-tighter {{ $realMargin >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                            {{ $realMargin >= 0 ? '+' : '' }}{{ number_format($realMargin, 0, ',', ' ') }} <small class="text-[10px]">{{ currency() }}</small>
                                        </span>
                                        <span @class([
                                            'text-[8px] uppercase font-black italic tracking-widest mt-0.5 px-2 py-0.5 rounded',
                                            'bg-emerald-50 text-emerald-500' => $realMargin >= 0,
                                            'bg-red-50 text-red-500' => $realMargin < 0,
                                        ])>
                                            {{ $realMargin >= 0 ? __("Bénéfice Net") : __("Perte Nette") }}
                                        </span>
                                    </div>
                                </td>

                                <td class="px-8 py-6 text-right">
                                    <div class="flex justify-end gap-2 items-center">
                                        {{-- PERMISSION M : RÉOUVERTURE DU LOT --}}
                                        @can('elevage.M')
                                            <form action="{{ route('batches.reopen', $batch->id) }}" method="POST" onsubmit="return confirm('{{ __("Réouvrir ce lot ? Le bâtiment redeviendra occupé.") }}')">
                                                @csrf
                                                @method('PUT')
                                                <button type="submit" class="p-2 text-orange-400 hover:text-orange-600 transition" title="{{ __("Réouvrir le lot") }}">
                                                    <i class="fas fa-undo-alt"></i>
                                                </button>
                                            </form>
                                        @endcan

                                        {{-- PERMISSION L : CONSULTATION --}}
                                        @can('elevage.L')
                                        <a href="{{ route('batches.show', $batch->id) }}" 
                                           title="{{ __("Consulter la fiche technique") }}"
                                           class="flex items-center gap-2 px-4 py-2 bg-slate-900 text-white rounded-xl text-[9px] font-black uppercase italic tracking-widest hover:bg-blue-600 transition-all shadow-lg shadow-slate-900/10">
                                            <i class="fas fa-eye text-xs"></i>
                                            <span>{{ __("Consulter") }}</span>
                                        </a>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-8 py-24 text-center">
                                    <p class="text-slate-300 font-black uppercase text-[11px] tracking-[0.3em] italic">{{ __("Aucun lot ne correspond à ces critères") }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            
            <div class="mt-8">
                {{ $archivedBatches->appends(request()->query())->links() }}
            </div>
        </div>
    </div>
</x-app-layout>