<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <x-back />
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ $client->name }}</h2>
                    <p class="text-[10px] font-black text-teal-600 uppercase tracking-[0.2em] mt-2 italic">
                        {{ $client->client_id }} — {{ ucfirst(str_replace('_', '/', $client->category)) }}
                    </p>
                </div>
            </div>
            <div class="flex gap-3">
                @can('commerce.C')
                <a href="{{ route('sales.create', ['client_id' => $client->id]) }}" class="bg-teal-500 text-white px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-teal-600 transition-all shadow-lg italic no-underline flex items-center gap-2">
                    <i class="fa-solid fa-cart-plus"></i> {{ __("Nouvelle Vente") }}
                </a>
                @endcan
                
                <a href="{{ route('clients.statement', $client) }}" class="bg-white border border-slate-200 px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest text-slate-600 hover:bg-slate-50 transition-all no-underline flex items-center gap-2">
                    <i class="fa-solid fa-file-invoice-dollar"></i> {{ __("Relevé") }}
                </a>

                @can('commerce.M')
                <a href="{{ route('clients.edit', $client) }}" class="bg-white border border-slate-200 px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest text-slate-600 hover:bg-slate-50 transition-all no-underline flex items-center gap-2">
                    <i class="fa-solid fa-pen"></i> {{ __("Modifier") }}
                </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @if(session('success'))
                <div class="mb-8 p-5 bg-emerald-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-check-double mr-3 text-lg"></i> {{ session('success') }}
                </div>
            @endif

            {{-- STATS --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Total achats") }}</p>
                    <p class="text-lg font-black text-slate-900">{{ number_format($stats['total_purchases'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-400">{{ setting('general.currency', 'GNF') }}</p>
                </div>
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-emerald-500 uppercase tracking-widest mb-1">{{ __("Total payé") }}</p>
                    <p class="text-lg font-black text-emerald-600">{{ number_format($stats['total_paid'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-400">{{ setting('general.currency', 'GNF') }}</p>
                </div>
                <div @class(['p-5 rounded-[2rem] border shadow-sm text-center', $client->balance > 0 ? 'bg-red-50 border-red-200' : 'bg-emerald-50 border-emerald-200'])>
                    <p class="text-[8px] font-black uppercase tracking-widest mb-1 {{ $client->balance > 0 ? 'text-red-500' : 'text-emerald-500' }}">{{ __("Solde dû") }}</p>
                    <p class="text-xl font-black {{ $client->balance > 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ number_format($client->balance, 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-400">{{ setting('general.currency', 'GNF') }}</p>
                </div>
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Nb ventes") }}</p>
                    <p class="text-xl font-black text-slate-900">{{ $stats['sales_count'] }}</p>
                </div>
            </div>

            {{-- FICHE CLIENT --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                <div class="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-5 flex items-center gap-2">
                        <i class="fa-solid fa-id-card text-teal-500"></i> {{ __("Informations") }}
                    </h3>
                    <div class="space-y-4">
                        <div class="flex justify-between">
                            <span class="text-[9px] font-black text-slate-400 uppercase">{{ __("Type") }}</span>
                            <span class="text-xs font-black text-slate-800 uppercase">{{ $client->type }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-[9px] font-black text-slate-400 uppercase">{{ __("Catégorie") }}</span>
                            <span @class([
                                'text-[9px] font-black uppercase px-3 py-1 rounded-full',
                                'bg-blue-50 text-blue-600' => $client->category === 'grossiste',
                                'bg-emerald-50 text-emerald-600' => $client->category === 'detaillant',
                                'bg-purple-50 text-purple-600' => $client->category === 'hotel_restaurant',
                                'bg-amber-50 text-amber-600' => $client->category === 'revendeur',
                                'bg-slate-50 text-slate-500' => $client->category === 'autre',
                            ])>{{ ucfirst(str_replace('_', '/', $client->category)) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-[9px] font-black text-slate-400 uppercase">{{ __("Téléphone") }}</span>
                            <span class="text-xs font-black text-slate-800">{{ $client->phone ?? '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-[9px] font-black text-slate-400 uppercase">{{ __("Email") }}</span>
                            <span class="text-xs font-black text-slate-800">{{ $client->email ?? '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-[9px] font-black text-slate-400 uppercase">{{ __("Adresse") }}</span>
                            <span class="text-xs font-black text-slate-800 text-right max-w-[60%]">{{ $client->address ?? '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-[9px] font-black text-slate-400 uppercase">{{ __("Statut") }}</span>
                            <span @class([
                                'text-[9px] font-black uppercase px-3 py-1 rounded-full',
                                'bg-emerald-50 text-emerald-600' => $client->status === 'actif',
                                'bg-amber-50 text-amber-600' => $client->status === 'suspendu',
                                'bg-red-50 text-red-600' => $client->status === 'blackliste',
                            ])>{{ $client->status }}</span>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-5 flex items-center gap-2">
                        <i class="fa-solid fa-building-columns text-purple-500"></i> {{ __("Fiscal & Crédit") }}
                    </h3>
                    <div class="space-y-4">
                        <div class="flex justify-between">
                            <span class="text-[9px] font-black text-slate-400 uppercase">{{ __("NIF") }}</span>
                            <span class="text-xs font-black text-slate-800">{{ $client->nif ?? '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-[9px] font-black text-slate-400 uppercase">{{ __("RCCM") }}</span>
                            <span class="text-xs font-black text-slate-800">{{ $client->rccm ?? '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-[9px] font-black text-slate-400 uppercase">{{ __("Plafond crédit") }}</span>
                            <span class="text-xs font-black text-purple-600">
                                {{ $client->credit_limit > 0 ? number_format($client->credit_limit, 0, ',', ' ') . ' ' . setting('general.currency', 'GNF') : __("Illimité") }}
                            </span>
                        </div>
                        @if($client->credit_limit > 0)
                        <div class="flex justify-between">
                            <span class="text-[9px] font-black text-slate-400 uppercase">{{ __("Crédit disponible") }}</span>
                            <span class="text-xs font-black {{ $client->is_over_limit ? 'text-red-600' : 'text-emerald-600' }}">
                                {{ $client->is_over_limit ? __("DÉPASSÉ") : number_format($client->available_credit, 0, ',', ' ') . ' ' . setting('general.currency', 'GNF') }}
                            </span>
                        </div>
                        <div class="mt-3">
                            @php $pct = $client->credit_limit > 0 ? min(100, ($client->balance / $client->credit_limit) * 100) : 0; @endphp
                            <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden">
                                <div @class(['h-3 rounded-full transition-all', $pct > 90 ? 'bg-red-500' : ($pct > 70 ? 'bg-amber-500' : 'bg-emerald-500')]) style="width: {{ $pct }}%"></div>
                            </div>
                            <p class="text-[8px] font-black text-slate-400 mt-1 text-right">{{ round($pct) }}% {{ __("utilisé") }}</p>
                        </div>
                        @endif
                    </div>

                    @if($client->notes)
                    <div class="mt-6 p-4 bg-slate-50 rounded-2xl">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Notes") }}</p>
                        <p class="text-xs text-slate-600">{{ $client->notes }}</p>
                    </div>
                    @endif
                </div>
            </div>

            {{-- HISTORIQUE VENTES --}}
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="px-8 py-5 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2">
                        <i class="fa-solid fa-file-invoice text-teal-500"></i> {{ __("Dernières ventes") }}
                    </h3>
                    @can('commerce.C')
                    <a href="{{ route('sales.create', ['client_id' => $client->id]) }}" class="text-[9px] font-black text-teal-500 uppercase tracking-widest no-underline hover:text-teal-700">
                        + {{ __("Nouvelle vente") }}
                    </a>
                    @endcan
                </div>
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                            <th class="px-6 py-3 text-left">{{ __("Référence") }}</th>
                            <th class="px-4 py-3 text-center">{{ __("Date") }}</th>
                            <th class="px-4 py-3 text-right">{{ __("Total") }}</th>
                            <th class="px-4 py-3 text-right">{{ __("Payé") }}</th>
                            <th class="px-4 py-3 text-center">{{ __("Statut") }}</th>
                            <th class="px-6 py-3 text-center">{{ __("Paiement") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($client->sales as $sale)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-6 py-4">
                                <a href="{{ route('sales.show', $sale) }}" class="text-xs font-black text-slate-900 uppercase no-underline hover:text-teal-600">{{ $sale->reference }}</a>
                            </td>
                            <td class="px-4 py-4 text-center text-[10px] font-black text-slate-500">{{ $sale->sale_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-4 text-right text-xs font-black text-slate-900">{{ number_format($sale->total_amount, 0, ',', ' ') }}</td>
                            <td class="px-4 py-4 text-right text-xs font-black text-emerald-600">{{ number_format($sale->paid_amount, 0, ',', ' ') }}</td>
                            <td class="px-4 py-4 text-center">
                                <span @class([
                                    'text-[8px] font-black uppercase px-2 py-1 rounded-full',
                                    'bg-slate-100 text-slate-500' => $sale->status === 'brouillon',
                                    'bg-emerald-50 text-emerald-600' => $sale->status === 'valide',
                                    'bg-blue-50 text-blue-600' => $sale->status === 'livre',
                                    'bg-red-50 text-red-500' => $sale->status === 'annule',
                                ])>{{ $sale->status }}</span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span @class([
                                    'text-[8px] font-black uppercase px-2 py-1 rounded-full',
                                    'bg-red-50 text-red-600' => $sale->payment_status === 'impaye',
                                    'bg-amber-50 text-amber-600' => $sale->payment_status === 'partiel',
                                    'bg-emerald-50 text-emerald-600' => $sale->payment_status === 'solde',
                                ])>{{ $sale->payment_status === 'solde' ? __("Soldé") : ($sale->payment_status === 'partiel' ? __("Partiel") : __("Impayé")) }}</span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-8 py-10 text-center">
                                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">{{ __("Aucune vente enregistrée pour ce client") }}</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
