<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <x-back />
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ $dispatch->dispatch_number }}</h2>
                    <p class="text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] mt-2 italic">
                        {{ $dispatch->destination }} — {{ $dispatch->dispatch_date->translatedFormat('d F Y') }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('dispatches.label', $dispatch->id) }}" target="_blank"
                   class="bg-indigo-50 text-indigo-700 px-6 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-indigo-600 hover:text-white transition-all italic flex items-center gap-2 no-underline"
                   title="{{ __("Étiquette QR de traçabilité") }}">
                    <i class="fa-solid fa-qrcode"></i> {{ __("Étiquette") }}
                </a>
            @if(!$dispatch->reception && in_array($dispatch->status, ['expedie', 'en_route']))
                {{-- Réception ouverte au RÉCEPTEUR DÉSIGNÉ ou à un responsable
                     logistique (droit M) en secours. L'anti-fraude (expéditeur ≠
                     récepteur) est appliquée à la validation. --}}
                @if(auth()->user()->can('logistique.M') || $dispatch->intended_receiver_id === auth()->id())
                <a href="{{ route('dispatches.reception.create', $dispatch) }}" class="bg-emerald-500 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-emerald-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-clipboard-check"></i> {{ __("Saisir la Réception") }}
                </a>
                @endif
            @endif
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- INFOS TRANSPORT --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                    <div>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Chauffeur") }}</p>
                        <p class="text-sm font-black text-slate-900 uppercase">{{ $dispatch->driver_name }}</p>
                        <p class="text-[9px] text-slate-500">{{ $dispatch->driver_phone ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Véhicule") }}</p>
                        <p class="text-sm font-black text-slate-900">{{ $dispatch->vehicle_plate ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Expédié par") }}</p>
                        <p class="text-sm font-black text-slate-900">{{ $dispatch->dispatcher->name ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Récepteur désigné") }}</p>
                        <p class="text-sm font-black {{ $dispatch->intended_receiver_id ? 'text-emerald-600' : 'text-slate-400' }}">
                            {{ $dispatch->intendedReceiver->name ?? __('Non désigné') }}
                        </p>
                    </div>
                    <div>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Statut") }}</p>
                        <span @class([
                            'text-[9px] font-black uppercase px-4 py-2 rounded-full inline-block',
                            'bg-blue-50 text-blue-600' => $dispatch->status === 'expedie',
                            'bg-amber-50 text-amber-600' => $dispatch->status === 'en_route',
                            'bg-emerald-50 text-emerald-600' => $dispatch->status === 'receptionne',
                            'bg-slate-900 text-white' => $dispatch->status === 'clos',
                        ])>{{ str_replace('_', ' ', $dispatch->status) }}</span>
                    </div>
                </div>
            </div>

            {{-- LIGNES EXPÉDIÉES --}}
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden mb-6">
                <div class="px-8 py-5 bg-slate-50 border-b border-slate-100">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2">
                        <i class="fa-solid fa-boxes-stacked text-orange-500"></i> {{ __("Marchandise expédiée") }}
                    </h3>
                </div>
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                            <th class="px-6 py-3 text-left">{{ __("Produit") }}</th>
                            <th class="px-4 py-3 text-center">{{ __("Qté expédiée") }}</th>
                            <th class="px-4 py-3 text-center">{{ __("Unité") }}</th>
                            <th class="px-4 py-3 text-center">{{ __("État départ") }}</th>
                            @if($dispatch->reception)
                                <th class="px-4 py-3 text-center text-emerald-600">{{ __("Qté reçue") }}</th>
                                <th class="px-4 py-3 text-center text-amber-600">{{ __("Endommagé") }}</th>
                                <th class="px-4 py-3 text-center text-red-600">{{ __("Manquant") }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($dispatch->items as $item)
                        @php $recItem = $dispatch->reception?->items->firstWhere('dispatch_item_id', $item->id); @endphp
                        <tr @class(['bg-red-50/30' => $recItem && $recItem->quantity_missing > 0])>
                            <td class="px-6 py-4">
                                <p class="text-xs font-black text-slate-800 uppercase">{{ $item->product_name }}</p>
                                <p class="text-[8px] text-slate-400 uppercase tracking-widest">{{ $item->type_label }}</p>
                            </td>
                            <td class="px-4 py-4 text-center text-sm font-black text-slate-900">{{ $item->quantity_dispatched }}</td>
                            <td class="px-4 py-4 text-center text-[9px] font-black text-slate-500 uppercase">{{ $item->unit }}</td>
                            <td class="px-4 py-4 text-center">
                                <span @class([
                                    'text-[8px] font-black uppercase px-2 py-1 rounded-full',
                                    'bg-emerald-50 text-emerald-600' => $item->condition_at_dispatch === 'bon',
                                    'bg-amber-50 text-amber-600' => $item->condition_at_dispatch === 'moyen',
                                    'bg-red-50 text-red-600' => $item->condition_at_dispatch === 'fragile',
                                ])>{{ $item->condition_at_dispatch }}</span>
                            </td>
                            @if($dispatch->reception)
                                <td class="px-4 py-4 text-center text-sm font-black text-emerald-600">{{ $recItem?->quantity_received ?? '—' }}</td>
                                <td class="px-4 py-4 text-center text-sm font-black {{ ($recItem?->quantity_damaged ?? 0) > 0 ? 'text-amber-600' : 'text-slate-300' }}">{{ $recItem?->quantity_damaged ?? 0 }}</td>
                                <td class="px-4 py-4 text-center text-sm font-black {{ ($recItem?->quantity_missing ?? 0) > 0 ? 'text-red-600' : 'text-slate-300' }}">
                                    {{ $recItem?->quantity_missing ?? 0 }}
                                    @if(($recItem?->quantity_missing ?? 0) > 0)
                                        <i class="fa-solid fa-triangle-exclamation text-red-400 ml-1"></i>
                                    @endif
                                </td>
                            @endif
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- RÉCEPTION --}}
            @if($dispatch->reception)
            <div @class([
                'p-8 rounded-[2.5rem] border shadow-sm mb-6',
                'bg-emerald-50 border-emerald-200' => $dispatch->reception->status === 'valide',
                'bg-red-50 border-red-200' => $dispatch->reception->status === 'litige',
                'bg-amber-50 border-amber-200' => $dispatch->reception->status === 'en_attente',
            ])>
                <div class="flex justify-between items-center">
                    <div>
                        <h3 class="text-[10px] font-black uppercase tracking-widest mb-2 {{ $dispatch->reception->status === 'litige' ? 'text-red-600' : 'text-emerald-600' }}">
                            <i class="fa-solid fa-clipboard-check mr-1"></i> {{ __("Réception") }} {{ $dispatch->reception->reception_number }}
                        </h3>
                        <p class="text-xs font-black text-slate-700">
                            {{ __("Reçu par") }} <strong>{{ $dispatch->reception->receiver->name ?? '—' }}</strong>
                            {{ __("le") }} {{ $dispatch->reception->reception_date->translatedFormat('d F Y') }}
                        </p>
                    </div>
                    <span @class([
                        'text-[10px] font-black uppercase px-5 py-2 rounded-full',
                        'bg-emerald-500 text-white' => $dispatch->reception->status === 'valide',
                        'bg-red-500 text-white animate-pulse' => $dispatch->reception->status === 'litige',
                    ])>{{ $dispatch->reception->status === 'litige' ? __("⚠ LITIGE") : __("✓ VALIDÉ") }}</span>
                </div>
            </div>
            @endif

            {{-- RAPPORT D'ÉCART --}}
            @if($dispatch->discrepancyReport)
            @php $report = $dispatch->discrepancyReport; @endphp
            <div class="bg-white p-8 rounded-[2.5rem] border-2 {{ $report->severity === 'critique' ? 'border-red-400' : ($report->severity === 'attention' ? 'border-amber-400' : 'border-slate-200') }} shadow-sm">
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h3 class="text-lg font-black uppercase italic tracking-tighter {{ $report->severity === 'critique' ? 'text-red-600' : 'text-amber-600' }}">
                            <i class="fa-solid fa-triangle-exclamation mr-2"></i> {{ __("Rapport d'Écart") }}
                        </h3>
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-1">
                            {{ __("Signalé par") }} {{ $report->reporter->name ?? '—' }} — {{ __("Taux d'écart") }} : {{ $report->discrepancy_rate }}%
                        </p>
                    </div>
                    <span @class([
                        'text-[9px] font-black uppercase px-4 py-2 rounded-full',
                        'bg-red-500 text-white' => $report->severity === 'critique',
                        'bg-amber-500 text-white' => $report->severity === 'attention',
                        'bg-slate-200 text-slate-600' => $report->severity === 'normal',
                    ])>{{ $report->severity }}</span>
                </div>

                <div class="grid grid-cols-4 gap-4 mb-6">
                    <div class="bg-slate-50 p-4 rounded-2xl text-center">
                        <p class="text-[8px] font-black text-slate-400 uppercase mb-1">{{ __("Expédié") }}</p>
                        <p class="text-lg font-black text-slate-900">{{ $report->total_dispatched }}</p>
                    </div>
                    <div class="bg-emerald-50 p-4 rounded-2xl text-center">
                        <p class="text-[8px] font-black text-emerald-500 uppercase mb-1">{{ __("Reçu") }}</p>
                        <p class="text-lg font-black text-emerald-600">{{ $report->total_received }}</p>
                    </div>
                    <div class="bg-amber-50 p-4 rounded-2xl text-center">
                        <p class="text-[8px] font-black text-amber-500 uppercase mb-1">{{ __("Endommagé") }}</p>
                        <p class="text-lg font-black text-amber-600">{{ $report->total_damaged }}</p>
                    </div>
                    <div class="bg-red-50 p-4 rounded-2xl text-center">
                        <p class="text-[8px] font-black text-red-500 uppercase mb-1">{{ __("Manquant") }}</p>
                        <p class="text-lg font-black text-red-600">{{ $report->total_missing }}</p>
                    </div>
                </div>

                {{-- Résolution --}}
                @if($report->is_resolved)
                    <div class="bg-slate-50 p-5 rounded-2xl">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-2">{{ __("Résolution") }}</p>
                        <span @class([
                            'text-[9px] font-black uppercase px-3 py-1 rounded-full',
                            'bg-emerald-50 text-emerald-600' => $report->resolution === 'justifie',
                            'bg-red-50 text-red-600' => $report->resolution === 'injustifie',
                        ])>{{ $report->resolution }}</span>
                        <p class="text-xs text-slate-600 mt-2">{{ $report->resolution_notes }}</p>
                        <p class="text-[8px] text-slate-400 mt-2">{{ __("Par") }} {{ $report->resolver->name ?? '—' }} — {{ $report->resolved_at?->translatedFormat('d/m/Y H:i') }}</p>
                    </div>
                @else
                    @can('logistique.S')
                    <form method="POST" action="{{ route('dispatches.discrepancy.resolve', $report) }}" class="bg-red-50 p-5 rounded-2xl mt-4">
                        @csrf @method('PUT')
                        <p class="text-[9px] font-black text-red-600 uppercase tracking-widest mb-3">{{ __("Résoudre cet écart") }}</p>
                        <div class="flex gap-3 mb-3">
                            <select name="resolution" required class="bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none flex-1">
                                <option value="">{{ __("Décision...") }}</option>
                                <option value="justifie">{{ __("Justifié (casse, décès transport)") }}</option>
                                <option value="injustifie">{{ __("Injustifié (vol / fraude)") }}</option>
                                <option value="enquete">{{ __("Enquête en cours") }}</option>
                            </select>
                        </div>
                        <textarea name="resolution_notes" required placeholder="{{ __("Détails de la résolution...") }}" rows="2"
                            class="w-full bg-white border-none rounded-xl p-3 text-xs font-bold shadow-sm outline-none mb-3"></textarea>
                        <button type="submit" class="bg-slate-900 text-white px-6 py-3 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-red-600 transition-all border-none cursor-pointer">
                            <i class="fa-solid fa-gavel mr-1"></i> {{ __("Valider la résolution") }}
                        </button>
                    </form>
                    @endcan
                @endif
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
