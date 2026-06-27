<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('purchases.index') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline shrink-0">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
                <div>
                    <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ $invoice->reference }}</h2>
                    <p class="text-[10px] font-black text-rose-500 uppercase tracking-widest mt-1 italic leading-none">
                        <a href="{{ route('purchases.statement', $invoice->provider) }}" class="no-underline hover:text-rose-700">{{ $invoice->provider->name ?? '—' }} →</a>
                    </p>
                </div>
            </div>
            <div class="flex gap-2">
                @can('depenses.M')
                @if($invoice->status === 'brouillon')
                <form method="POST" action="{{ route('purchases.validate', $invoice) }}">@csrf @method('PUT')
                    <button class="bg-emerald-500 text-white px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-emerald-600 transition-all border-none cursor-pointer italic"><i class="fa-solid fa-check mr-1"></i> {{ __("Valider") }}</button>
                </form>
                @endif
                @if($invoice->status !== 'annule')
                <form method="POST" action="{{ route('purchases.cancel', $invoice) }}" onsubmit="return confirm('Annuler cet achat ? Sa dépense sera retirée du P&L.')">@csrf @method('PUT')
                    <button class="bg-white border border-slate-200 text-slate-500 px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-red-50 hover:text-red-600 transition-all border-none cursor-pointer italic"><i class="fa-solid fa-ban mr-1"></i> {{ __("Annuler") }}</button>
                </form>
                @endif
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['p-5 rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success', 'bg-red-500 text-white' => $msg === 'error'])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : 'triangle-exclamation' }} mr-3 text-lg"></i> {{ session($msg) }}
                    </div>
                @endif
            @endforeach

            {{-- Entête achat --}}
            <div class="bg-white p-7 rounded-[2.5rem] border border-slate-100 shadow-sm">
                <div class="flex justify-between items-start mb-5">
                    <div>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Catégorie") }}</p>
                        <p class="text-[12px] font-black text-slate-700 uppercase">{{ $invoice->category_label }}</p>
                        <p class="text-[11px] font-bold text-slate-500 mt-1">{{ $invoice->label }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Statut") }}</p>
                        <span @class(['text-[9px] font-black uppercase px-3 py-1 rounded-full inline-block mt-1',
                            'bg-slate-100 text-slate-500' => $invoice->status === 'brouillon',
                            'bg-emerald-50 text-emerald-600' => $invoice->status === 'valide',
                            'bg-slate-100 text-slate-400 line-through' => $invoice->status === 'annule'])>{{ $invoice->status }}</span>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 not-italic">
                    <div><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Date") }}</p><p class="text-[11px] font-black text-slate-700">{{ $invoice->invoice_date->format('d/m/Y') }}</p></div>
                    <div><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Échéance") }}</p><p class="text-[11px] font-black {{ $invoice->due_date && $invoice->due_date->isPast() && $invoice->remaining_amount > 0 ? 'text-rose-600' : 'text-slate-700' }}">{{ $invoice->due_date?->format('d/m/Y') ?? '—' }}</p></div>
                    <div><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Montant") }}</p><p class="text-[13px] font-black text-slate-900">{{ number_format($invoice->total_amount, 0, ',', ' ') }}</p></div>
                    <div><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Reste dû") }}</p><p class="text-[13px] font-black {{ $invoice->remaining_amount > 0 && $invoice->status !== 'annule' ? 'text-rose-600' : 'text-emerald-600' }}">{{ $invoice->status === 'annule' ? '—' : number_format($invoice->remaining_amount, 0, ',', ' ') }}</p></div>
                </div>

                @if($invoice->expense)
                <p class="mt-4 text-[9px] font-black text-slate-400 uppercase tracking-widest not-italic"><i class="fa-solid fa-link mr-1"></i> {{ __("Dépense liée") }} : {{ $invoice->expense->reference }} ({{ $invoice->expense->status }})</p>
                @endif
            </div>

            {{-- Règlement --}}
            @can('depenses.C')
            @if($invoice->status === 'valide' && $invoice->remaining_amount > 0)
            <form method="POST" action="{{ route('purchases.pay', $invoice) }}" class="bg-emerald-50 p-6 rounded-[2.5rem] border border-emerald-200">
                @csrf
                <h3 class="text-[10px] font-black uppercase text-emerald-600 tracking-widest mb-4">{{ __("Enregistrer un règlement") }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <input type="number" name="amount" required min="1" max="{{ $invoice->remaining_amount }}" step="1" value="{{ $invoice->remaining_amount }}" placeholder="{{ __('Montant') }}" class="bg-white border-none rounded-xl p-3 text-[11px] font-black outline-none text-right text-emerald-600">
                    <input type="date" name="payment_date" required value="{{ now()->toDateString() }}" class="bg-white border-none rounded-xl p-3 text-[10px] font-black outline-none">
                    <select name="method" required class="bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase outline-none">
                        <option value="especes">{{ __("Espèces") }}</option>
                        <option value="mobile_money">{{ __("Mobile Money") }}</option>
                        <option value="virement">{{ __("Virement") }}</option>
                        <option value="cheque">{{ __("Chèque") }}</option>
                    </select>
                    <button type="submit" class="bg-emerald-600 text-white rounded-xl p-3 text-[10px] font-black uppercase tracking-widest hover:bg-emerald-700 transition-all border-none cursor-pointer italic">{{ __("Régler") }}</button>
                </div>
            </form>
            @endif
            @endcan

            {{-- Historique des règlements --}}
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <p class="px-6 pt-5 text-[9px] font-black uppercase tracking-widest text-slate-400">{{ __("Règlements") }}</p>
                <table class="w-full border-collapse mt-3">
                    <tbody class="divide-y divide-slate-50">
                        @forelse($invoice->payments->sortByDesc('payment_date') as $p)
                        <tr>
                            <td class="px-6 py-3 text-[10px] font-black text-slate-400">{{ $p->payment_date->format('d/m/Y') }}</td>
                            <td class="px-3 py-3 text-[10px] font-black text-slate-500 uppercase">{{ $p->method_label }}</td>
                            <td class="px-3 py-3 text-[10px] font-bold text-slate-400">{{ $p->payer?->name }}</td>
                            <td class="px-6 py-3 text-right text-[11px] font-black {{ $p->amount < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ number_format($p->amount, 0, ',', ' ') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="px-6 py-8 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ __("Aucun règlement.") }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
