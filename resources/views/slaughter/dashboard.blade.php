<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <div class="w-14 h-14 bg-rose-500 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl rotate-3">
                    <i class="fa-solid fa-industry text-xl"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Abattoir") }}</h2>
                    <p class="text-[10px] font-black text-rose-600 uppercase tracking-[0.2em] mt-2 italic">{{ __("Abattage, Découpe & Transformation") }}</p>
                </div>
            </div>
            <div class="flex gap-3">
                @can('abattoir.C')
                <a href="{{ route('slaughter.orders.create') }}" class="bg-slate-900 text-white px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-rose-600 transition-all shadow-xl italic no-underline flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> {{ __("Nouvel Ordre") }}
                </a>
                <a href="{{ route('slaughter.transform.form') }}" class="bg-amber-500 text-white px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-amber-600 transition-all shadow-xl italic no-underline flex items-center gap-2">
                    <i class="fa-solid fa-fire"></i> {{ __("Transformation") }}
                </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['mb-8 p-5 rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic', 'bg-emerald-500 text-white' => $msg === 'success', 'bg-red-500 text-white' => $msg === 'error'])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : 'circle-xmark' }} mr-3 text-lg"></i> {{ session($msg) }}
                    </div>
                @endif
            @endforeach

            {{-- ALERTES PÉREMPTION --}}
            @if($expiring->count() > 0)
            <div class="mb-6 p-5 bg-red-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-widest flex items-center gap-3 animate-pulse">
                <i class="fa-solid fa-clock text-lg"></i>
                {{ __(":count produit(s) proche(s) de la date de péremption !", ['count' => $expiring->count()]) }}
            </div>
            @endif

            {{-- KPI --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Sujets abattus") }}</p>
                    <p class="text-2xl font-black text-slate-900">{{ number_format($kpi['total_slaughtered']) }}</p>
                    <p class="text-[8px] text-slate-400">{{ __(":days derniers jours", ['days' => setting('abattoir.kpi_days', 30)]) }}</p>
                </div>

                {{-- ⚙️ PARAMÉTRAGE DYNAMIQUE : Seuils Rendement --}}
                <div @class(['p-5 rounded-[2rem] border shadow-sm text-center',
                    'bg-emerald-50 border-emerald-200' => $kpi['avg_yield'] >= setting('abattoir.yield_target_min', 70),
                    'bg-amber-50 border-amber-200' => $kpi['avg_yield'] >= setting('abattoir.yield_alert_min', 65) && $kpi['avg_yield'] < setting('abattoir.yield_target_min', 70),
                    'bg-red-50 border-red-200' => $kpi['avg_yield'] < setting('abattoir.yield_alert_min', 65)])>
                    <p class="text-[8px] font-black uppercase tracking-widest mb-1 {{ $kpi['avg_yield'] >= setting('abattoir.yield_target_min', 70) ? 'text-emerald-500' : 'text-amber-500' }}">{{ __("Rendement carcasse") }}</p>
                    <p class="text-2xl font-black {{ $kpi['avg_yield'] >= setting('abattoir.yield_target_min', 70) ? 'text-emerald-600' : 'text-amber-600' }}">{{ $kpi['avg_yield'] }}%</p>
                    <p class="text-[8px] text-slate-400">{{ __("norme") }} : {{ setting('abattoir.yield_target_min', 70) }}-{{ setting('abattoir.yield_target_max', 75) }}%</p>
                </div>

                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Poids carcasse") }}</p>
                    <p class="text-2xl font-black text-slate-900">{{ number_format($kpi['total_carcass_kg']) }}</p>
                    <p class="text-[8px] text-slate-400">kg</p>
                </div>

                {{-- ⚙️ PARAMÉTRAGE DYNAMIQUE : Tolérance Saisie --}}
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-red-400 uppercase tracking-widest mb-1">{{ __("Taux saisie") }}</p>
                    <p class="text-2xl font-black {{ $kpi['condemnation_rate'] > setting('abattoir.condemnation_tolerance', 2) ? 'text-red-600' : 'text-slate-900' }}">{{ $kpi['condemnation_rate'] }}%</p>
                    <p class="text-[8px] text-slate-400">{{ __("seuil") }} : &lt; {{ setting('abattoir.condemnation_tolerance', 2) }}%</p>
                </div>

                {{-- ⚙️ PARAMÉTRAGE DYNAMIQUE : Tolérance Perte --}}
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Perte découpe") }}</p>
                    <p class="text-2xl font-black text-slate-900">{{ $kpi['avg_cutting_loss'] }}%</p>
                    <p class="text-[8px] text-slate-400">{{ __("seuil") }} : &lt; {{ setting('abattoir.tolerance_cutting_loss', 10) }}%</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                {{-- ORDRES EN ATTENTE --}}
                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2">
                            <i class="fa-solid fa-list-check text-rose-500"></i> {{ __("Ordres en attente") }}
                        </h3>
                    </div>
                    <div class="divide-y divide-slate-50">
                        @forelse($pendingOrders as $order)
                        <div class="px-6 py-4 flex justify-between items-center">
                            <div>
                                <p class="text-xs font-black text-slate-900 uppercase">{{ $order->order_number }}</p>
                                {{-- ⚙️ PARAMÉTRAGE DYNAMIQUE : Date --}}
                                <p class="text-[8px] text-slate-400 font-black">{{ $order->batch->code ?? '—' }} — {{ __(":qty sujets", ['qty' => $order->planned_quantity]) }} — {{ $order->planned_date->format(setting('general.date_format', 'd/m/Y')) }}</p>
                            </div>
                            @can('abattoir.M')
                            <a href="{{ route('slaughter.execute.form', $order) }}" class="bg-rose-500 text-white px-4 py-2 rounded-xl font-black text-[8px] uppercase tracking-widest no-underline hover:bg-rose-600">{{ __("Exécuter") }}</a>
                            @endcan
                        </div>
                        @empty
                        <div class="px-6 py-8 text-center"><p class="text-[10px] text-slate-400 uppercase font-black">{{ __("Aucun ordre en attente") }}</p></div>
                        @endforelse
                    </div>
                </div>

                {{-- STOCK PRODUITS FINIS --}}
                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2">
                            <i class="fa-solid fa-boxes-stacked text-emerald-500"></i> {{ __("Stock Produits Finis") }}
                        </h3>
                        <a href="{{ route('slaughter.finished') }}" class="text-[9px] font-black text-emerald-500 no-underline uppercase tracking-widest hover:text-emerald-700">{{ __("Voir tout") }} →</a>
                    </div>
                    <div class="divide-y divide-slate-50">
                        @forelse($finishedProducts as $fp)
                        <div class="px-6 py-3 flex justify-between items-center">
                            <div>
                                <p class="text-[10px] font-black text-slate-800 uppercase">{{ $fp->product_name }}</p>
                                <p class="text-[8px] text-slate-400 font-black uppercase">{{ $fp->type_label }} — {{ $fp->storage_location }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-black {{ $fp->is_low ? 'text-red-600' : 'text-slate-900' }}">{{ number_format($fp->current_quantity_kg, 1) }} kg</p>
                                @if($fp->is_expired)
                                    <span class="text-[7px] font-black text-red-600 bg-red-50 px-2 py-0.5 rounded-full">{{ __("PÉRIMÉ") }}</span>
                                @elseif($fp->is_expiring_soon)
                                    <span class="text-[7px] font-black text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full">{{ __("EXPIRE BIENTÔT") }}</span>
                                @endif
                            </div>
                        </div>
                        @empty
                        <div class="px-6 py-8 text-center"><p class="text-[10px] text-slate-400 uppercase font-black">{{ __("Stock vide") }}</p></div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- DERNIERS ABATTAGES --}}
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="px-6 py-4 bg-slate-50 border-b border-slate-100">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest">{{ __("Abattages récents") }}</h3>
                </div>
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                            <th class="px-6 py-3 text-left">{{ __("N° Ordre") }}</th>
                            <th class="px-4 py-3 text-left">{{ __("Lot") }}</th>
                            <th class="px-4 py-3 text-center">{{ __("Sujets") }}</th>
                            <th class="px-4 py-3 text-right">{{ __("Poids vif") }}</th>
                            <th class="px-4 py-3 text-right">{{ __("Carcasse") }}</th>
                            <th class="px-4 py-3 text-center">{{ __("Rendement") }}</th>
                            <th class="px-6 py-3 text-center">{{ __("Date") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($recentResults as $order)
                        <tr class="hover:bg-slate-50/50">
                            <td class="px-6 py-3 text-xs font-black text-slate-900 uppercase">{{ $order->order_number }}</td>
                            <td class="px-4 py-3 text-[10px] font-black text-slate-700">{{ $order->batch->code ?? '—' }}</td>
                            <td class="px-4 py-3 text-center text-sm font-black text-slate-900">{{ $order->actual_quantity }}</td>
                            <td class="px-4 py-3 text-right text-[10px] font-black text-slate-600">{{ number_format($order->total_live_weight_kg, 1) }} kg</td>
                            <td class="px-4 py-3 text-right text-[10px] font-black text-slate-600">{{ $order->result ? number_format($order->result->total_carcass_weight_kg, 1) . ' kg' : '—' }}</td>
                            <td class="px-4 py-3 text-center">
                                @if($order->result)
                                {{-- ⚙️ PARAMÉTRAGE DYNAMIQUE : Seuils Rendement Tableau --}}
                                <span @class(['text-[10px] font-black px-2 py-1 rounded-full',
                                    'bg-emerald-50 text-emerald-600' => $order->result->carcass_yield_percent >= setting('abattoir.yield_target_min', 70),
                                    'bg-amber-50 text-amber-600' => $order->result->carcass_yield_percent >= setting('abattoir.yield_alert_min', 65) && $order->result->carcass_yield_percent < setting('abattoir.yield_target_min', 70),
                                    'bg-red-50 text-red-600' => $order->result->carcass_yield_percent < setting('abattoir.yield_alert_min', 65)])>
                                    {{ $order->result->carcass_yield_percent }}%
                                </span>
                                @endif
                            </td>
                            {{-- ⚙️ PARAMÉTRAGE DYNAMIQUE : Date --}}
                            <td class="px-6 py-3 text-center text-[10px] font-black text-slate-500">{{ $order->actual_date?->format(setting('general.date_format', 'd/m/Y')) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>