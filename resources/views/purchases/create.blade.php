<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('purchases.index') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline shrink-0">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Nouvel achat fournisseur") }}</h2>
                <p class="text-[10px] font-black text-rose-500 uppercase tracking-widest mt-1 italic leading-none">{{ __("Crée une dette (brouillon)") }}</p>
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

            <form method="POST" action="{{ route('purchases.store') }}" class="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm space-y-5">
                @csrf

                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">{{ __("Fournisseur") }}</label>
                    <select name="provider_id" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-[11px] font-black uppercase outline-none">
                        <option value="">{{ __("— Choisir —") }}</option>
                        @foreach($providers as $p)
                            <option value="{{ $p->id }}" @selected(old('provider_id') == $p->id)>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">{{ __("Date d'achat") }}</label>
                        <input type="date" name="invoice_date" value="{{ old('invoice_date', now()->toDateString()) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-[11px] font-black outline-none">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">{{ __("Échéance (optionnel)") }}</label>
                        <input type="date" name="due_date" value="{{ old('due_date') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-[11px] font-black outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">{{ __("Catégorie") }}</label>
                    <select name="category" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-[11px] font-black uppercase outline-none">
                        @foreach($categories as $k => $v)
                            <option value="{{ $k }}" @selected(old('category', 'fournitures') === $k)>{{ $v }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">{{ __("Libellé") }}</label>
                    <input type="text" name="label" value="{{ old('label') }}" required maxlength="255" placeholder="{{ __('Ex. 20 sacs de provende') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-[11px] font-black outline-none">
                </div>

                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">{{ __("Montant total") }} ({{ currency() }})</label>
                    <input type="number" name="total_amount" value="{{ old('total_amount') }}" required min="0" step="1" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black text-slate-800 outline-none text-right">
                </div>

                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">{{ __("Notes (optionnel)") }}</label>
                    <textarea name="notes" maxlength="500" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-[11px] font-black outline-none">{{ old('notes') }}</textarea>
                </div>

                <button type="submit" class="w-full bg-rose-500 text-white font-black py-5 rounded-[2rem] hover:bg-rose-600 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl border-none cursor-pointer">
                    <i class="fa-solid fa-floppy-disk mr-2"></i> {{ __("Enregistrer (brouillon)") }}
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
