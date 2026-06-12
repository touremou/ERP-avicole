<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-5 text-left">
            <a href="{{ route('expenses.show', $expense) }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Modifier Dépense") }}</h2>
                <p class="text-[10px] font-black text-rose-600 uppercase tracking-[0.2em] mt-2 italic">{{ $expense->reference }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @if($errors->any())
                <div class="mb-8 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200">
                    <i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('expenses.update', $expense) }}">
                @csrf
                @method('PUT')

                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-receipt text-rose-500"></i> {{ __("Détail de la dépense") }}
                    </h3>

                    <div class="space-y-6">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Libellé") }} *</label>
                            <input type="text" name="label" value="{{ old('label', $expense->label) }}" required
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none focus:ring-4 focus:ring-rose-500/10">
                        </div>

                        <div class="grid grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Catégorie") }} *</label>
                                <select name="category" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none appearance-none cursor-pointer">
                                    @foreach($categories as $key => $label)
                                        <option value="{{ $key }}" {{ old('category', $expense->category) === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Montant") }} ({{ setting('general.currency', 'GNF') }}) *</label>
                                <input type="number" name="amount" value="{{ old('amount', (int) $expense->amount) }}" min="1" step="1" required
                                    class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black text-rose-600 shadow-inner outline-none text-right">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Date") }} *</label>
                                <input type="date" name="expense_date" value="{{ old('expense_date', $expense->expense_date->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" required
                                    class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Mode de paiement") }} *</label>
                                <select name="payment_method" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none appearance-none cursor-pointer">
                                    @foreach($paymentMethods as $key => $label)
                                        <option value="{{ $key }}" {{ old('payment_method', $expense->payment_method) === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-link text-blue-500"></i> {{ __("Rattachement (optionnel)") }}
                    </h3>

                    <div class="grid grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Lot concerné") }}</label>
                            <select name="batch_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none appearance-none cursor-pointer">
                                <option value="">— {{ __("Charge générale (ferme)") }} —</option>
                                @foreach($batches as $batch)
                                    <option value="{{ $batch->id }}" {{ (string) old('batch_id', $expense->batch_id) === (string) $batch->id ? 'selected' : '' }}>{{ $batch->code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Bénéficiaire / Fournisseur") }}</label>
                            <input type="text" name="supplier_name" value="{{ old('supplier_name', $expense->supplier_name) }}"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none">
                        </div>
                    </div>

                    <div class="space-y-2 mt-6">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Notes") }}</label>
                        <textarea name="notes" rows="2"
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none">{{ old('notes', $expense->notes) }}</textarea>
                    </div>
                </div>

                <button type="submit" class="w-full bg-rose-500 text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] hover:bg-rose-600 transition-all shadow-2xl italic border-none cursor-pointer">
                    <i class="fa-solid fa-floppy-disk mr-2"></i> {{ __("Enregistrer les modifications") }}
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
