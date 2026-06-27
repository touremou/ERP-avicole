<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <div class="w-14 h-14 bg-teal-500 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl rotate-3">
                    <i class="fa-solid fa-file-invoice text-xl"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Registre des Ventes") }}</h2>
                    <p class="text-[10px] font-black text-teal-600 uppercase tracking-[0.2em] mt-2 italic">
                        {{ $stats['today_count'] }} {{ __("vente(s) aujourd'hui") }} — {{ number_format($stats['today_total'], 0, ',', ' ') }} {{ currency() }}
                    </p>
                </div>
            </div>
            <div class="flex gap-4">
                <div class="bg-white px-5 py-3 rounded-[1.5rem] border border-slate-100 text-right shadow-sm">
                    <p class="text-[8px] font-black text-rose-400 uppercase italic mb-1">{{ __("Impayés") }}</p>
                    <p class="text-sm font-black text-slate-900">{{ number_format($stats['unpaid_total'], 0, ',', ' ') }} <small class="text-[8px] opacity-40">{{ currency() }}</small></p>
                </div>
                <a href="{{ route('products.index') }}" class="bg-white border border-slate-200 text-slate-600 px-6 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:border-teal-500 hover:text-teal-600 transition-all shadow-sm italic flex items-center gap-2 no-underline" title="{{ __('Catalogue d\'articles') }}">
                    <i class="fa-solid fa-box-open"></i> {{ __("Catalogue") }}
                </a>
                <a href="{{ route('sales.receivables') }}" class="bg-white border border-slate-200 text-slate-600 px-6 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:border-rose-500 hover:text-rose-600 transition-all shadow-sm italic flex items-center gap-2 no-underline" title="{{ __('Recouvrement / relances') }}">
                    <i class="fa-solid fa-hand-holding-dollar"></i> {{ __("Recouvrement") }}
                </a>
                @can('commerce.M')
                <a href="{{ route('sales.price-lists') }}" class="bg-white border border-slate-200 text-slate-600 px-6 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:border-teal-500 hover:text-teal-600 transition-all shadow-sm italic flex items-center gap-2 no-underline" title="{{ __('Groupes de prix (tarifs)') }}">
                    <i class="fa-solid fa-tags"></i> {{ __("Tarifs") }}
                </a>
                @endcan
                @can('commerce.C')
                <a href="{{ route('sales.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-teal-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-plus"></i> {{ __("Nouvelle Vente") }}
                </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @if(session('success'))
                <div class="mb-8 p-5 bg-emerald-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-check-double mr-3 text-lg"></i> {{ session('success') }}
                </div>
            @endif

            {{-- FILTRES --}}
            <form method="GET" class="mb-8 flex flex-wrap gap-3 items-center">
                <select name="status" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none">
                    <option value="">{{ __("Tous statuts") }}</option>
                    <option value="brouillon" {{ request('status') === 'brouillon' ? 'selected' : '' }}>{{ __("Brouillon") }}</option>
                    <option value="valide" {{ request('status') === 'valide' ? 'selected' : '' }}>{{ __("Validé") }}</option>
                    <option value="livre" {{ request('status') === 'livre' ? 'selected' : '' }}>{{ __("Livré") }}</option>
                    <option value="annule" {{ request('status') === 'annule' ? 'selected' : '' }}>{{ __("Annulé") }}</option>
                </select>
                <select name="payment_status" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none">
                    <option value="">{{ __("Tous paiements") }}</option>
                    <option value="impaye" {{ request('payment_status') === 'impaye' ? 'selected' : '' }}>{{ __("Impayé") }}</option>
                    <option value="partiel" {{ request('payment_status') === 'partiel' ? 'selected' : '' }}>{{ __("Partiel") }}</option>
                    <option value="solde" {{ request('payment_status') === 'solde' ? 'selected' : '' }}>{{ __("Soldé") }}</option>
                </select>
                <select name="client_id" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none">
                    <option value="">{{ __("Tous clients") }}</option>
                    @foreach($clients as $c)
                        <option value="{{ $c->id }}" {{ request('client_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                    @endforeach
                </select>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black shadow-sm outline-none">
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black shadow-sm outline-none">
                <button type="submit" class="bg-slate-900 text-white px-5 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest border-none cursor-pointer">{{ __("Filtrer") }}</button>
            </form>

            {{-- TABLEAU --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                                <th class="px-6 py-5 text-left">{{ __("Référence") }}</th>
                                <th class="px-4 py-5 text-left">{{ __("Client") }}</th>
                                <th class="px-4 py-5 text-center">{{ __("Date") }}</th>
                                <th class="px-4 py-5 text-right">{{ __("Total TTC") }}</th>
                                <th class="px-4 py-5 text-center">{{ __("Statut") }}</th>
                                <th class="px-4 py-5 text-center">{{ __("Paiement") }}</th>
                                <th class="px-6 py-5 text-right">{{ __("Actions") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($sales as $sale)
                            <tr class="hover:bg-slate-50/50 transition-all">
                                <td class="px-6 py-4">
                                    <a href="{{ route('sales.show', $sale) }}" class="no-underline">
                                        <p class="text-xs font-black text-slate-900 uppercase">{{ $sale->reference }}</p>
                                        <p class="text-[8px] text-slate-400 font-black uppercase">{{ $sale->type === 'facture' ? __("Facture TVA") : __("BL") }}</p>
                                    </a>
                                </td>
                                <td class="px-4 py-4 text-[10px] font-black text-slate-700 uppercase">{{ $sale->client->name }}</td>
                                <td class="px-4 py-4 text-center text-[10px] font-black text-slate-500">{{ $sale->sale_date->format('d/m/Y') }}</td>
                                <td class="px-4 py-4 text-right text-sm font-black text-slate-900">{{ number_format($sale->total_amount, 0, ',', ' ') }}</td>
                                <td class="px-4 py-4 text-center">
                                    <span @class([
                                        'text-[8px] font-black uppercase px-3 py-1 rounded-full tracking-widest',
                                        'bg-slate-100 text-slate-500' => $sale->status === 'brouillon',
                                        'bg-emerald-50 text-emerald-600' => $sale->status === 'valide',
                                        'bg-blue-50 text-blue-600' => $sale->status === 'livre',
                                        'bg-red-50 text-red-500' => $sale->status === 'annule',
                                    ])>{{ $sale->status }}</span>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span @class([
                                        'text-[8px] font-black uppercase px-3 py-1 rounded-full',
                                        'bg-red-50 text-red-600' => $sale->payment_status === 'impaye',
                                        'bg-amber-50 text-amber-600' => $sale->payment_status === 'partiel',
                                        'bg-emerald-50 text-emerald-600' => $sale->payment_status === 'solde',
                                    ])>{{ $sale->payment_status === 'solde' ? __("Soldé") : ($sale->payment_status === 'partiel' ? __("Partiel") : __("Impayé")) }}</span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('sales.show', $sale) }}" class="text-slate-400 hover:text-teal-600 no-underline"><i class="fa-solid fa-eye"></i></a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="px-8 py-16 text-center">
                                    <i class="fa-solid fa-file-circle-xmark text-slate-200 text-3xl mb-4 block"></i>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">{{ __("Aucune vente enregistrée") }}</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6">{{ $sales->withQueryString()->links() }}</div>
        </div>
    </div>
</x-app-layout>
