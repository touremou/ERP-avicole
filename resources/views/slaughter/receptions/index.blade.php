<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Réceptions du vif')" :subtitle="__('CCP 1 — Contrôle ante-mortem à l\'arrivée')" icon="fa-truck-ramp-box" accent="rose">
            <x-slot name="actions">
                @can('abattoir.C')
                <a href="{{ route('slaughter.receptions.create') }}" class="bg-rose-500 text-white px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-rose-600 transition-all shadow-lg italic no-underline"><i class="fa-solid fa-plus mr-1"></i> {{ __("Nouvelle réception") }}</a>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- FILTRES --}}
            <form method="GET" class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm mb-6 grid grid-cols-2 md:grid-cols-5 gap-3 items-end">
                <div class="space-y-1">
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Du") }}</label>
                    <input type="date" name="from" value="{{ request('from') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none">
                </div>
                <div class="space-y-1">
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Au") }}</label>
                    <input type="date" name="to" value="{{ request('to') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none">
                </div>
                <div class="space-y-1">
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Éleveur") }}</label>
                    <select name="provider_id" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black uppercase shadow-inner outline-none">
                        <option value="">{{ __("Tous") }}</option>
                        @foreach($providers as $p)
                            <option value="{{ $p->id }}" @selected(request('provider_id') == $p->id)>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="space-y-1">
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Décision") }}</label>
                    <select name="decision" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black uppercase shadow-inner outline-none">
                        <option value="">{{ __("Toutes") }}</option>
                        <option value="accepte" @selected(request('decision') === 'accepte')>{{ __("Accepté") }}</option>
                        <option value="accepte_avec_decote" @selected(request('decision') === 'accepte_avec_decote')>{{ __("Accepté avec décote") }}</option>
                        <option value="refuse" @selected(request('decision') === 'refuse')>{{ __("Refusé") }}</option>
                    </select>
                </div>
                <button type="submit" class="bg-slate-900 text-white p-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-rose-600 transition-all border-none cursor-pointer italic"><i class="fa-solid fa-filter mr-1"></i> {{ __("Filtrer") }}</button>
            </form>

            {{-- TABLEAU --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-5 py-4 text-left">{{ __("Date") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Éleveur") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Origine / Coût") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Annoncé / Reçu / Écarté") }}</th>
                            <th class="px-3 py-4 text-right">{{ __("Poids vif") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("État sanitaire") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Diète") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Décision") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Motif") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Certificat") }}</th>
                            <th class="px-5 py-4 text-left">{{ __("Contrôleur") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($receptions as $r)
                        <tr @class(['hover:bg-slate-50/50 transition-all', 'bg-red-50/30' => $r->isRefused()])>
                            <td class="px-5 py-4">
                                <p class="text-xs font-black text-slate-900">{{ $r->reception_date->format(setting('general.date_format', 'd/m/Y')) }}</p>
                                @if($r->arrived_at)<p class="text-[7px] text-slate-400">{{ __("Arrivée") }} {{ $r->arrived_at->format('H:i') }}</p>@endif
                            </td>
                            <td class="px-3 py-4 text-[10px] font-black text-slate-700 uppercase">{{ $r->provider?->name ?? '—' }}</td>
                            <td class="px-3 py-4">
                                @if($r->origin === 'facon')
                                    <span class="text-[8px] font-black uppercase px-2 py-1 rounded-full bg-indigo-50 text-indigo-600">🤝 {{ __("À façon") }}</span>
                                @else
                                    <span class="text-[8px] font-black uppercase px-2 py-1 rounded-full bg-emerald-50 text-emerald-600">🛒 {{ __("Achat") }}</span>
                                    @if($r->purchase_total_cost)
                                        <p class="text-[10px] font-black text-slate-900 mt-1">{{ number_format($r->purchase_total_cost, 0, ',', ' ') }} {{ currency() }}</p>
                                        @if($r->supplierInvoice)
                                            @can('depenses.L')
                                            <a href="{{ route('purchases.show', $r->supplierInvoice->id) }}" class="text-[7px] font-black uppercase tracking-widest no-underline {{ $r->supplierInvoice->status === 'valide' ? 'text-emerald-600' : 'text-amber-600' }} hover:underline">
                                                <i class="fa-solid fa-file-invoice-dollar"></i> {{ $r->supplierInvoice->reference }} · {{ __($r->supplierInvoice->status) }}
                                            </a>
                                            @endcan
                                        @endif
                                    @else
                                        <p class="text-[7px] text-slate-400 uppercase tracking-widest mt-1">{{ __("prix à saisir au bureau") }}</p>
                                    @endif
                                @endif
                            </td>
                            <td class="px-3 py-4 text-center text-[10px] font-black text-slate-600">
                                {{ $r->announced_quantity ?? '—' }} / <span class="text-slate-900">{{ $r->received_quantity }}</span> / <span class="{{ $r->rejected_quantity > 0 ? 'text-red-600' : 'text-slate-400' }}">{{ $r->rejected_quantity }}</span>
                            </td>
                            <td class="px-3 py-4 text-right text-[10px] font-black text-slate-600">{{ number_format($r->total_live_weight_kg, 1) }} kg</td>
                            <td class="px-3 py-4 text-center">
                                <span @class(['text-[8px] font-black uppercase px-2 py-1 rounded-full',
                                    'bg-emerald-50 text-emerald-600' => $r->sanitary_state === 'conforme',
                                    'bg-amber-50 text-amber-600'     => $r->sanitary_state === 'reserves',
                                    'bg-red-50 text-red-600'         => $r->sanitary_state === 'non_conforme'])>
                                    {{ str_replace('_', ' ', $r->sanitary_state) }}
                                </span>
                            </td>
                            <td class="px-3 py-4 text-center text-[9px] font-black uppercase text-slate-500">{{ $r->fasting_respected }}</td>
                            <td class="px-3 py-4 text-center">
                                <span @class(['text-[8px] font-black uppercase px-2 py-1 rounded-full',
                                    'bg-emerald-50 text-emerald-600' => $r->decision === 'accepte',
                                    'bg-amber-50 text-amber-600'     => $r->decision === 'accepte_avec_decote',
                                    'bg-red-100 text-red-700'        => $r->decision === 'refuse'])>
                                    {{ str_replace('_', ' ', $r->decision) }}
                                </span>
                            </td>
                            <td class="px-3 py-4 text-[9px] text-slate-500 font-bold max-w-[180px]">{{ $r->decision_reason ?: '—' }}</td>
                            <td class="px-3 py-4 text-center">
                                @if($r->doc_photo_path)
                                    <a href="{{ media_url($r->doc_photo_path) }}" target="_blank" class="text-blue-500 hover:text-blue-700 no-underline" title="{{ __('Voir le certificat') }}"><i class="fa-solid fa-file-image"></i></a>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <p class="text-[9px] font-black text-slate-600 uppercase">{{ $r->controller?->name ?? '—' }}</p>
                                <p class="text-[7px] text-slate-400">{{ __("Relevé") }} {{ $r->releve_at?->format('d/m H:i') ?? '—' }} · {{ __("Sync") }} {{ $r->synced_at?->format('d/m H:i') ?? '—' }}</p>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="11" class="px-8 py-16 text-center">
                                <i class="fa-solid fa-truck-ramp-box text-slate-200 text-3xl mb-4 block"></i>
                                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">{{ __("Aucune réception enregistrée") }}</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div class="mt-6">{{ $receptions->links() }}</div>
        </div>
    </div>
</x-app-layout>
