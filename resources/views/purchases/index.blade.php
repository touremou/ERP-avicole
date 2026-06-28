<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Achats fournisseurs')" :subtitle="__('Dettes & règlements fournisseurs')" icon="fa-file-invoice-dollar" accent="rose">
            <x-slot name="actions">
                @can('depenses.C')
                <a href="{{ route('purchases.create') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-rose-500 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-rose-600 transition-all no-underline shadow-lg italic">
                    <i class="fa-solid fa-plus"></i> {{ __("Nouvel achat") }}
                </a>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            <x-flash />

            {{-- Synthèse --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Total achats") }}</p>
                    <p class="text-xl font-black text-slate-800 leading-none">{{ number_format($stats['total_billed'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Total réglé") }}</p>
                    <p class="text-xl font-black text-emerald-600 leading-none">{{ number_format($stats['total_paid'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Dette en cours") }}</p>
                    <p class="text-xl font-black {{ $stats['total_due'] > 0 ? 'text-rose-600' : 'text-slate-800' }} leading-none">{{ number_format($stats['total_due'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border {{ $stats['overdue'] > 0 ? 'border-rose-200' : 'border-slate-100' }} shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("En retard") }}</p>
                    <p class="text-xl font-black {{ $stats['overdue'] > 0 ? 'text-rose-600' : 'text-slate-800' }} leading-none">{{ number_format($stats['overdue'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ $stats['overdue_count'] }} {{ __("échéance(s)") }}</p>
                </div>
            </div>

            {{-- Filtres --}}
            <form method="GET" class="bg-white p-4 rounded-[2rem] border border-slate-100 shadow-sm grid grid-cols-1 md:grid-cols-4 gap-3">
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('Libellé…') }}" class="bg-slate-50 border-none rounded-xl p-3 text-[10px] font-black outline-none uppercase">
                <select name="provider_id" class="bg-slate-50 border-none rounded-xl p-3 text-[10px] font-black outline-none uppercase">
                    <option value="">{{ __("Tous fournisseurs") }}</option>
                    @foreach($providers as $p)
                        <option value="{{ $p->id }}" @selected(($filters['provider_id'] ?? '') == $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
                <select name="status" class="bg-slate-50 border-none rounded-xl p-3 text-[10px] font-black outline-none uppercase">
                    <option value="">{{ __("Tous statuts") }}</option>
                    @foreach(['brouillon' => 'Brouillon', 'valide' => 'Validé', 'annule' => 'Annulé'] as $k => $v)
                        <option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $v }}</option>
                    @endforeach
                </select>
                <button type="submit" class="bg-slate-900 text-white rounded-xl p-3 text-[10px] font-black uppercase tracking-widest hover:bg-rose-600 transition-all border-none cursor-pointer italic">{{ __("Filtrer") }}</button>
            </form>

            {{-- Liste --}}
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-6 py-3 text-left">{{ __("Réf.") }}</th>
                            <th class="px-3 py-3 text-left">{{ __("Date") }}</th>
                            <th class="px-3 py-3 text-left">{{ __("Fournisseur") }}</th>
                            <th class="px-3 py-3 text-left">{{ __("Libellé") }}</th>
                            <th class="px-3 py-3 text-right">{{ __("Montant") }}</th>
                            <th class="px-3 py-3 text-right">{{ __("Reste") }}</th>
                            <th class="px-6 py-3 text-center">{{ __("Statut") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($invoices as $inv)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-6 py-3 text-[10px] font-black"><a href="{{ route('purchases.show', $inv) }}" class="text-rose-600 no-underline hover:text-rose-800">{{ $inv->reference }}</a></td>
                            <td class="px-3 py-3 text-[10px] font-black text-slate-400 whitespace-nowrap">{{ $inv->invoice_date->format('d/m/Y') }}</td>
                            <td class="px-3 py-3 text-[10px] font-black text-slate-600 uppercase">{{ $inv->provider->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-[10px] font-black text-slate-500">{{ Str::limit($inv->label, 28) }}</td>
                            <td class="px-3 py-3 text-right text-[10px] font-black text-slate-700">{{ number_format($inv->total_amount, 0, ',', ' ') }}</td>
                            <td class="px-3 py-3 text-right text-[10px] font-black {{ $inv->remaining_amount > 0 && $inv->status !== 'annule' ? 'text-rose-600' : 'text-slate-300' }}">{{ $inv->status === 'annule' ? '—' : number_format($inv->remaining_amount, 0, ',', ' ') }}</td>
                            <td class="px-6 py-3 text-center">
                                @php $ps = $inv->payment_status; @endphp
                                <span @class(['text-[7px] font-black uppercase px-2 py-1 rounded-full',
                                    'bg-slate-100 text-slate-500' => $inv->status === 'brouillon',
                                    'bg-rose-50 text-rose-600' => $inv->status === 'valide' && $ps === 'impaye',
                                    'bg-amber-50 text-amber-600' => $inv->status === 'valide' && $ps === 'partiel',
                                    'bg-emerald-50 text-emerald-600' => $inv->status === 'valide' && $ps === 'solde',
                                    'bg-slate-100 text-slate-400 line-through' => $inv->status === 'annule'])>
                                    {{ $inv->status === 'valide' ? $ps : $inv->status }}
                                </span>
                                @if($inv->status === 'valide' && $inv->remaining_amount > 0.01 && $inv->due_date && $inv->due_date->isPast())
                                    <span class="block mt-1 text-[7px] font-black uppercase text-rose-600"><i class="fa-solid fa-clock mr-0.5"></i>{{ __("En retard") }}</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="px-6 py-10 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ __("Aucun achat fournisseur.") }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $invoices->links() }}</div>
        </div>
    </div>
</x-app-layout>
