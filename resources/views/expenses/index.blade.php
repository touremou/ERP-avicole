<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col xl:flex-row justify-between items-start xl:items-center gap-6 w-full text-left">
            <div class="flex items-center gap-5">
                <div class="w-14 h-14 bg-rose-500 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl rotate-3">
                    <i class="fa-solid fa-receipt text-xl"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Registre des Dépenses") }}</h2>
                    <p class="text-[10px] font-black text-rose-600 uppercase tracking-[0.2em] mt-2 italic">
                        {{ __("Du") }} {{ $from->format('d/m/Y') }} {{ __("au") }} {{ $to->format('d/m/Y') }}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-3 w-full sm:w-auto">
                <div class="bg-white px-6 py-4 rounded-[1.5rem] border border-slate-100 text-right shadow-sm">
                    <p class="text-[8px] font-black text-emerald-400 uppercase italic mb-1">{{ __("Validées (période)") }}</p>
                    <p class="text-base font-black text-slate-900 leading-none">{{ number_format($stats['total_valide'], 0, ',', ' ') }} <small class="text-[9px] opacity-40">{{ setting('general.currency', 'GNF') }}</small></p>
                </div>
                <div class="bg-white px-6 py-4 rounded-[1.5rem] border border-slate-100 text-right shadow-sm">
                    <p class="text-[8px] font-black text-amber-400 uppercase italic mb-1">{{ __("En attente") }} ({{ $stats['count_attente'] }})</p>
                    <p class="text-base font-black text-amber-600 leading-none">{{ number_format($stats['total_attente'], 0, ',', ' ') }} <small class="text-[9px] opacity-40">{{ setting('general.currency', 'GNF') }}</small></p>
                </div>
                @can('depenses.C')
                <a href="{{ route('expenses.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-rose-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-plus"></i> {{ __("Nouvelle Dépense") }}
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
            @if(session('error'))
                <div class="mb-8 p-5 bg-red-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-triangle-exclamation mr-3 text-lg"></i> {{ session('error') }}
                </div>
            @endif

            {{-- FILTRES --}}
            <form method="GET" class="mb-8 flex flex-wrap gap-3 items-center">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __("RECHERCHER...") }}" class="bg-white border border-slate-100 rounded-2xl pl-10 pr-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm w-48 outline-none">
                </div>
                <select name="category" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none">
                    <option value="">{{ __("Toutes catégories") }}</option>
                    @foreach($categories as $key => $label)
                        <option value="{{ $key }}" {{ request('category') === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="status" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none">
                    <option value="">{{ __("Tous statuts") }}</option>
                    <option value="en_attente" {{ request('status') === 'en_attente' ? 'selected' : '' }}>{{ __("En attente") }}</option>
                    <option value="valide" {{ request('status') === 'valide' ? 'selected' : '' }}>{{ __("Validées") }}</option>
                    <option value="annule" {{ request('status') === 'annule' ? 'selected' : '' }}>{{ __("Annulées") }}</option>
                </select>
                <input type="date" name="date_from" value="{{ $from->format('Y-m-d') }}" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none">
                <input type="date" name="date_to" value="{{ $to->format('Y-m-d') }}" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none">
                <button type="submit" class="bg-slate-900 text-white px-5 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest border-none cursor-pointer">{{ __("Filtrer") }}</button>
            </form>

            {{-- TABLEAU --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-slate-50 text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                                <th class="px-8 py-6 text-left">{{ __("Dépense") }}</th>
                                <th class="px-6 py-6 text-left">{{ __("Catégorie") }}</th>
                                <th class="px-6 py-6 text-left">{{ __("Date") }}</th>
                                <th class="px-6 py-6 text-left">{{ __("Lot") }}</th>
                                <th class="px-6 py-6 text-right">{{ __("Montant") }}</th>
                                <th class="px-6 py-6 text-center">{{ __("Statut") }}</th>
                                <th class="px-8 py-6 text-right">{{ __("Actions") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($expenses as $expense)
                            <tr class="hover:bg-slate-50/50 transition-all group">
                                <td class="px-8 py-5">
                                    <a href="{{ route('expenses.show', $expense) }}" class="no-underline group-hover:text-rose-600 transition-colors">
                                        <p class="font-black text-slate-900 text-sm uppercase italic leading-none mb-1">{{ $expense->label }}</p>
                                        <p class="text-[8px] text-slate-400 font-black uppercase tracking-widest">{{ $expense->reference }} · {{ $expense->payment_method_label }}</p>
                                    </a>
                                </td>
                                <td class="px-6 py-5">
                                    <span class="text-[8px] font-black uppercase px-3 py-1 rounded-full tracking-widest bg-rose-50 text-rose-600">{{ $expense->category_label }}</span>
                                </td>
                                <td class="px-6 py-5 text-[10px] text-slate-600 font-black">{{ $expense->expense_date->format('d/m/Y') }}</td>
                                <td class="px-6 py-5 text-[10px] text-slate-500 font-black">{{ $expense->batch?->code ?? '—' }}</td>
                                <td class="px-6 py-5 text-right text-sm font-black text-slate-900">{{ number_format($expense->amount, 0, ',', ' ') }}</td>
                                <td class="px-6 py-5 text-center">
                                    <span @class([
                                        'text-[8px] font-black uppercase px-3 py-1 rounded-full',
                                        'bg-amber-50 text-amber-600'   => $expense->status === 'en_attente',
                                        'bg-emerald-50 text-emerald-600' => $expense->status === 'valide',
                                        'bg-slate-100 text-slate-400'  => $expense->status === 'annule',
                                    ])>{{ str_replace('_', ' ', $expense->status) }}</span>
                                </td>
                                <td class="px-8 py-5 text-right whitespace-nowrap">
                                    @can('depenses.M')
                                        @if($expense->status === 'en_attente')
                                        <form method="POST" action="{{ route('expenses.approve', $expense) }}" class="inline">
                                            @csrf @method('PUT')
                                            <button type="submit" class="text-emerald-500 hover:text-emerald-700 mr-3 border-none bg-transparent cursor-pointer" title="{{ __("Valider") }}">
                                                <i class="fa-solid fa-check-double"></i>
                                            </button>
                                        </form>
                                        <a href="{{ route('expenses.edit', $expense) }}" class="text-blue-400 hover:text-blue-600 mr-3 no-underline" title="{{ __("Modifier") }}">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>
                                        @endif
                                    @endcan
                                    <a href="{{ route('expenses.show', $expense) }}" class="text-slate-400 hover:text-slate-700 no-underline" title="{{ __("Détails") }}">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="px-8 py-16 text-center">
                                    <i class="fa-solid fa-receipt text-slate-200 text-3xl mb-4 block"></i>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">{{ __("Aucune dépense sur cette période") }}</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6">{{ $expenses->links() }}</div>
        </div>
    </div>
</x-app-layout>
