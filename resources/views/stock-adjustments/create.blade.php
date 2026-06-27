<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <x-back />
            <div>
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Nouvel ajustement") }}</h2>
                <p class="text-[10px] font-black text-orange-500 uppercase tracking-widest mt-1 italic leading-none">{{ __("Démarque / écart d'inventaire") }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @if($errors->any())
                <div class="mb-6 p-5 bg-red-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-widest shadow-xl">
                    @foreach($errors->all() as $e) <p><i class="fa-solid fa-circle-xmark mr-2"></i> {{ $e }}</p> @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('stock-adjustments.store') }}" class="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm space-y-5"
                  x-data="adjustForm({{ Illuminate\Support\Js::from($stocks) }}, {{ (int) ($stock_id ?? 0) }})">
                @csrf

                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">{{ __("Article") }}</label>
                    <select name="stock_id" x-model.number="stockId" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-[11px] font-black uppercase outline-none">
                        <option value="">{{ __("— Choisir —") }}</option>
                        @foreach($stocks as $s)
                            <option value="{{ $s->id }}">{{ $s->item_name }} ({{ $s->unit }})</option>
                        @endforeach
                    </select>
                </div>

                <template x-if="current">
                    <div class="bg-slate-50 rounded-2xl p-4 grid grid-cols-3 gap-3 text-center not-italic">
                        <div><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Stock actuel") }}</p><p class="text-sm font-black text-slate-800" x-text="fmtQty(current.current_quantity) + ' ' + current.unit"></p></div>
                        <div><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Écart") }}</p><p class="text-sm font-black" :class="delta < 0 ? 'text-rose-600' : (delta > 0 ? 'text-emerald-600' : 'text-slate-400')" x-text="(delta > 0 ? '+' : '') + fmtQty(delta)"></p></div>
                        <div><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Valeur") }}</p><p class="text-sm font-black" :class="delta < 0 ? 'text-rose-600' : 'text-emerald-600'" x-text="fmtMoney(valueImpact)"></p></div>
                    </div>
                </template>

                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">{{ __("Quantité comptée (réelle)") }}</label>
                    <input type="number" name="counted_quantity" x-model.number="counted" required min="0" step="0.001" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black text-slate-800 outline-none text-right">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">{{ __("Motif") }}</label>
                        <select name="reason" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-[11px] font-black uppercase outline-none">
                            @foreach($reasons as $k => $v)<option value="{{ $k }}" @selected(old('reason') === $k)>{{ $v }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">{{ __("Date") }}</label>
                        <input type="date" name="adjustment_date" value="{{ old('adjustment_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-[11px] font-black outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">{{ __("Note (optionnel)") }}</label>
                    <textarea name="notes" maxlength="500" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-[11px] font-black outline-none">{{ old('notes') }}</textarea>
                </div>

                <button type="submit" class="w-full bg-orange-500 text-white font-black py-5 rounded-[2rem] hover:bg-orange-600 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl border-none cursor-pointer">
                    <i class="fa-solid fa-sliders mr-2"></i> {{ __("Enregistrer l'ajustement") }}
                </button>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('adjustForm', (stocks, initialId) => ({
                stocks,
                stockId: initialId || '',
                counted: null,
                get current() { return this.stocks.find(s => Number(s.id) === Number(this.stockId)) || null; },
                get delta() {
                    if (!this.current || this.counted === null || this.counted === '') return 0;
                    return Math.round((this.counted - Number(this.current.current_quantity)) * 1000) / 1000;
                },
                get valueImpact() {
                    return Math.round(Math.abs(this.delta) * Number(this.current?.last_unit_price || 0));
                },
                fmtQty(v) { return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 3 }).format(v || 0); },
                fmtMoney(v) { return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(Math.round(v || 0)) + ' {{ currency() }}'; },
            }));
        });
    </script>
</x-app-layout>
