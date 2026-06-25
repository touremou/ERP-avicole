<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('sales.show', $sale) }}" class="group text-slate-400 hover:text-slate-800 transition no-underline">
                <i class="fas fa-arrow-left group-hover:-translate-x-1 transition-transform text-xl"></i>
            </a>
            <div>
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    ↩️ {{ __("Retour client") }}
                </h2>
                <p class="text-[10px] font-black text-orange-500 uppercase tracking-widest mt-1 italic leading-none">
                    {{ $sale->reference }} — {{ $sale->client->name }}
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <form method="POST" action="{{ route('sales.return.store', $sale) }}" class="space-y-6">
                @csrf

                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden divide-y divide-slate-50">
                    @foreach($sale->items as $item)
                    <div class="flex items-center justify-between gap-4 p-5">
                        <div class="min-w-0">
                            <p class="text-xs font-black text-slate-800 uppercase truncate">{{ $item->product_name }}</p>
                            <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">
                                {{ __("Vendu") }} : {{ rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.') }} {{ $item->unit }}
                                · {{ money($item->unit_price) }} / {{ $item->unit }}
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <label class="block text-[8px] font-black text-orange-400 uppercase tracking-widest mb-1">{{ __("À retourner") }}</label>
                            <input type="number" name="returns[{{ $item->id }}]" value="0" min="0" max="{{ $item->quantity }}" step="0.01"
                                   class="w-24 bg-orange-50 border-none rounded-xl p-3 text-center text-sm font-black text-orange-600 shadow-inner outline-none">
                        </div>
                    </div>
                    @endforeach
                </div>

                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm p-6 space-y-4">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">{{ __("Mode de remboursement") }}</label>
                        <select name="refund_method" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-[10px] font-black uppercase shadow-inner outline-none appearance-none cursor-pointer">
                            <option value="especes">{{ __("Espèces") }}</option>
                            <option value="orange_money">{{ __("Orange Money / MoMo") }}</option>
                            <option value="virement">{{ __("Virement") }}</option>
                            <option value="cheque">{{ __("Chèque") }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">{{ __("Motif (optionnel)") }}</label>
                        <textarea name="reason" rows="2" placeholder="{{ __('Produit défectueux, erreur de commande…') }}"
                                  class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none uppercase italic"></textarea>
                    </div>
                </div>

                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 text-[9px] font-bold text-amber-700 not-italic">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    {{ __("Les articles rendus sont remis en stock ; la vente est réduite et le trop-perçu (déjà payé − nouveau total) est remboursé automatiquement.") }}
                </div>

                <div class="flex gap-4">
                    <a href="{{ route('sales.show', $sale) }}" class="flex-1 bg-white border-2 border-slate-100 text-slate-400 font-black py-5 rounded-[2rem] shadow-sm hover:bg-slate-50 text-center uppercase tracking-widest text-[10px] italic no-underline flex items-center justify-center">
                        <i class="fas fa-times mr-2"></i> {{ __("Annuler") }}
                    </a>
                    <button type="submit" class="flex-[2] bg-orange-500 text-white font-black py-5 rounded-[2rem] hover:bg-orange-600 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl border-none cursor-pointer">
                        <i class="fa-solid fa-rotate-left mr-2"></i> {{ __("Valider le retour") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
