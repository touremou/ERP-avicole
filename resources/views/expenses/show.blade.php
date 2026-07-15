<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$expense->label" :subtitle="$expense->reference . ' · ' . str_replace('_', ' ', $expense->status)" icon="fa-receipt" accent="rose" :back="route('expenses.index')">
            <x-slot name="actions">
                @can('depenses.M')
                @if($expense->status === 'en_attente')
                    <form method="POST" action="{{ route('expenses.approve', $expense) }}">
                        @csrf @method('PUT')
                        <button type="submit" class="bg-emerald-500 text-white px-6 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-600 transition-all shadow-lg italic border-none cursor-pointer">
                            <i class="fa-solid fa-check-double mr-1"></i> {{ __("Valider") }}
                        </button>
                    </form>
                    <a href="{{ route('expenses.edit', $expense) }}" class="bg-slate-900 text-white px-6 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-slate-700 transition-all shadow-lg italic no-underline flex items-center">
                        <i class="fa-solid fa-pen mr-1"></i> {{ __("Modifier") }}
                    </a>
                @endif
                @if($expense->status !== 'annule')
                    <form method="POST" action="{{ route('expenses.cancel', $expense) }}" onsubmit="return confirm('{{ __("Annuler cette dépense ? Elle sera exclue des résultats.") }}');">
                        @csrf @method('PUT')
                        <button type="submit" class="bg-white text-rose-600 border border-rose-200 px-6 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-rose-50 transition-all shadow-sm italic cursor-pointer">
                            <i class="fa-solid fa-ban mr-1"></i> {{ __("Annuler") }}
                        </button>
                    </form>
                @endif
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- MONTANT --}}
            <div class="bg-slate-900 text-white p-8 rounded-[3rem] shadow-2xl mb-6 text-center">
                <p class="text-[9px] font-black text-rose-300 uppercase tracking-[0.3em] mb-2">{{ __("Montant") }}</p>
                <p class="text-4xl font-black leading-none">{{ number_format($expense->amount, 0, ',', ' ') }} <small class="text-sm opacity-50">{{ setting('general.currency', 'GNF') }}</small></p>
            </div>

            {{-- DÉTAILS --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                <dl class="grid grid-cols-2 gap-6">
                    <div>
                        <dt class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Catégorie") }}</dt>
                        <dd class="text-sm font-black text-slate-800">{{ $expense->category_label }}</dd>
                    </div>
                    <div>
                        <dt class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Date") }}</dt>
                        <dd class="text-sm font-black text-slate-800">{{ $expense->expense_date->format('d/m/Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Mode de paiement") }}</dt>
                        <dd class="text-sm font-black text-slate-800">{{ $expense->payment_method_label }}</dd>
                    </div>
                    <div>
                        <dt class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Lot rattaché") }}</dt>
                        <dd class="text-sm font-black text-slate-800">{{ $expense->batch?->code ?? __("Charge générale (ferme)") }}</dd>
                    </div>
                    <div>
                        <dt class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Bénéficiaire") }}</dt>
                        <dd class="text-sm font-black text-slate-800">{{ $expense->supplier_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Saisie par") }}</dt>
                        <dd class="text-sm font-black text-slate-800">{{ $expense->user?->name ?? '—' }}</dd>
                    </div>
                    @if($expense->approved_at)
                    <div>
                        <dt class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Validée par") }}</dt>
                        <dd class="text-sm font-black text-emerald-600">{{ $expense->approver?->name ?? '—' }} · {{ $expense->approved_at->format('d/m/Y H:i') }}</dd>
                    </div>
                    @endif
                </dl>

                @if($expense->notes)
                <div class="mt-6 pt-6 border-t border-slate-100">
                    <dt class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-2">{{ __("Notes") }}</dt>
                    <dd class="text-xs font-bold text-slate-600 whitespace-pre-line">{{ $expense->notes }}</dd>
                </div>
                @endif

                @if($expense->justificatif_path)
                <div class="mt-6 pt-6 border-t border-slate-100">
                    <dt class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-2">{{ __("Justificatif") }}</dt>
                    <dd>
                        <a href="{{ route('expenses.justificatif', $expense) }}" target="_blank"
                           class="inline-flex items-center gap-2 bg-slate-900 text-white px-5 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-rose-600 transition-all no-underline italic">
                            <i class="fa-solid fa-file-arrow-down"></i> {{ __("Télécharger le justificatif") }}
                        </a>
                    </dd>
                </div>
                @endif
            </div>

            @can('depenses.S')
            <form method="POST" action="{{ route('expenses.destroy', $expense) }}" onsubmit="return confirm('{{ __("Supprimer définitivement cette dépense ?") }}');" class="text-right">
                @csrf @method('DELETE')
                <button type="submit" class="text-[9px] font-black text-slate-400 uppercase tracking-widest hover:text-rose-600 border-none bg-transparent cursor-pointer italic">
                    <i class="fa-solid fa-trash mr-1"></i> {{ __("Supprimer cette dépense") }}
                </button>
            </form>
            @endcan
        </div>
    </div>
</x-app-layout>
