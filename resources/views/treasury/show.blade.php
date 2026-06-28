<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <x-back />
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 bg-slate-900 rounded-2xl flex items-center justify-center text-white shadow-lg">
                    <i class="fa-solid {{ $account->type_icon }}"></i>
                </div>
                <div>
                    <h2 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ $account->name }}</h2>
                    <p class="text-[9px] font-black text-emerald-500 uppercase tracking-widest mt-1 italic">
                        {{ $account->type_label }} — {{ __("Solde") }} : {{ number_format($account->current_balance, 0, ',', ' ') }} {{ currency() }}
                    </p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">

            <x-flash />

            {{-- MOUVEMENT MANUEL --}}
            @can('depenses.C')
            <form method="POST" action="{{ route('treasury.movement', $account) }}" class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                @csrf
                <h3 class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-4">{{ __("Mouvement manuel") }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <select name="direction" required class="bg-slate-50 border-none rounded-2xl p-3 text-[10px] font-black uppercase shadow-inner outline-none">
                        <option value="in">↑ {{ __("Entrée") }}</option>
                        <option value="out">↓ {{ __("Sortie") }}</option>
                    </select>
                    <input type="number" name="amount" required min="1" step="1" placeholder="{{ __('Montant') }}" class="bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none text-right">
                    <input type="date" name="date" value="{{ now()->toDateString() }}" max="{{ date('Y-m-d') }}" required class="bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none">
                    <button type="submit" class="bg-slate-900 text-white py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-600 transition-all border-none cursor-pointer">{{ __("Enregistrer") }}</button>
                </div>
                <input type="text" name="description" placeholder="{{ __('Description (ex. apport associé, retrait, frais bancaires…)') }}" class="w-full mt-3 bg-slate-50 border-none rounded-2xl p-3 text-[10px] font-black shadow-inner outline-none uppercase italic">
            </form>
            @endcan

            {{-- GRAND-LIVRE --}}
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-6 py-4 text-left">{{ __("Date") }}</th>
                            <th class="px-4 py-4 text-left">{{ __("Libellé") }}</th>
                            <th class="px-4 py-4 text-right">{{ __("Mouvement") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($transactions as $tx)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-6 py-4 text-[10px] font-black text-slate-500">{{ $tx->transaction_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-4 text-[10px] font-black text-slate-700">
                                {{ $tx->description ?? ucfirst($tx->category) }}
                                @if($tx->counterpart)<span class="text-slate-400">· {{ $tx->counterpart->name }}</span>@endif
                                <span class="block text-[7px] text-slate-300 uppercase tracking-widest">{{ $tx->category }}</span>
                            </td>
                            <td class="px-4 py-4 text-right text-sm font-black {{ $tx->direction === 'in' ? 'text-emerald-600' : 'text-red-500' }}">
                                {{ $tx->direction === 'in' ? '+' : '-' }}{{ number_format($tx->amount, 0, ',', ' ') }}
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="3" class="p-8 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ __("Aucun mouvement.") }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $transactions->links() }}</div>
        </div>
    </div>
</x-app-layout>
