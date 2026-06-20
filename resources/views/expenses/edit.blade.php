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

            <form method="POST" action="{{ route('expenses.update', $expense) }}" enctype="multipart/form-data">
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

                    {{-- JUSTIFICATIF (remplacement) --}}
                    <div class="space-y-2 mt-6">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Justificatif (facture, reçu)") }}</label>
                        @if($expense->justificatif_path)
                            <div class="mb-3">
                                <a href="{{ route('expenses.justificatif', $expense) }}" target="_blank"
                                   class="inline-flex items-center gap-2 text-[10px] font-black text-blue-600 uppercase tracking-widest hover:text-blue-800 no-underline">
                                    <i class="fa-solid fa-file-arrow-down"></i> {{ __("Justificatif actuel") }}
                                </a>
                            </div>
                        @endif
                        <div class="p-6 border-2 border-dashed border-slate-200 rounded-2xl bg-slate-50/50 text-center group hover:border-rose-400 transition-all">
                            <i class="fa-solid fa-paperclip text-slate-300 text-xl mb-3 group-hover:text-rose-500 transition-colors"></i>
                            <input type="file" name="justificatif" accept=".pdf,.jpg,.jpeg,.png"
                                class="block w-full text-[10px] text-slate-500 file:bg-rose-500 file:text-white file:rounded-full file:border-0 file:px-5 file:py-2 file:font-black file:uppercase file:mr-4 file:cursor-pointer cursor-pointer">
                            <p class="text-[8px] text-slate-400 mt-3 italic uppercase tracking-widest">{{ $expense->justificatif_path ? __("Choisir un fichier remplace le justificatif actuel") : __("PDF ou image · 5 Mo max") }}</p>
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full bg-rose-500 text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] hover:bg-rose-600 transition-all shadow-2xl italic border-none cursor-pointer">
                    <i class="fa-solid fa-floppy-disk mr-2"></i> {{ __("Enregistrer les modifications") }}
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
