<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$account->name" :subtitle="$account->type_label . ' — ' . __('Solde') . ' : ' . number_format($account->current_balance, 0, ',', ' ') . ' ' . currency()" :icon="$account->type_icon" accent="emerald" :back="route('treasury.index')" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">

            <x-flash />

            {{-- ÉDITION / SUPPRESSION DU COMPTE --}}
            @can('tresorerie.M')
            <div x-data="{ open: false }" class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                <button type="button" @click="open = !open" class="w-full flex justify-between items-center border-none bg-transparent cursor-pointer outline-none">
                    <span class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2"><i class="fa-solid fa-gear text-emerald-500"></i> {{ __("Paramètres du compte") }}</span>
                    <i class="fa-solid fa-chevron-down text-slate-300 transition-transform" :class="open && 'rotate-180'"></i>
                </button>
                <div x-show="open" x-transition class="mt-5 space-y-4">
                    <form method="POST" action="{{ route('treasury.account.update', $account) }}" class="space-y-3">
                        @csrf @method('PUT')
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <input type="text" name="name" value="{{ old('name', $account->name) }}" required class="bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none" placeholder="{{ __('Libellé') }}">
                            <select name="type" required class="bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none uppercase">
                                @foreach(\App\Models\TreasuryAccount::TYPES as $k => $label)
                                    <option value="{{ $k }}" @selected($account->type === $k)>{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <input type="text" name="notes" value="{{ old('notes', $account->notes) }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-[10px] font-black shadow-inner outline-none uppercase italic" placeholder="{{ __('Notes (optionnel)') }}">
                        <label class="flex items-center gap-2 text-[10px] font-black uppercase text-slate-500 tracking-widest">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" @checked($account->is_active) class="rounded"> {{ __("Compte actif") }}
                        </label>
                        <button type="submit" class="bg-slate-900 text-white px-6 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-600 transition-all border-none cursor-pointer">{{ __("Enregistrer") }}</button>
                    </form>

                    @can('tresorerie.S')
                    <div class="pt-4 border-t border-slate-100">
                        <form method="POST" action="{{ route('treasury.account.destroy', $account) }}" onsubmit="return confirm('{{ __('Supprimer définitivement ce compte ? (impossible s\'il porte des mouvements)') }}')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-[9px] font-black uppercase tracking-widest text-rose-500 hover:text-rose-700 bg-transparent border-none cursor-pointer p-0"><i class="fa-solid fa-trash-can mr-1"></i> {{ __("Supprimer ce compte") }}</button>
                            <p class="text-[8px] text-slate-400 mt-1 italic">{{ __("Un compte avec mouvements ne peut pas être supprimé — désactivez-le plutôt.") }}</p>
                        </form>
                    </div>
                    @endcan
                </div>
            </div>
            @endcan

            {{-- MOUVEMENT MANUEL --}}
            @can('tresorerie.C')
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
                                @if($tx->source_link)
                                    <a href="{{ $tx->source_link['url'] }}" class="ml-1 text-emerald-600 hover:text-emerald-800 no-underline" title="{{ $tx->source_link['label'] }}"><i class="fa-solid fa-arrow-up-right-from-square text-[8px]"></i></a>
                                @endif
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
