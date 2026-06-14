<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-5 text-left">
            <a href="{{ route('expenses.index') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Nouvelle Dépense") }}</h2>
                <p class="text-[10px] font-black text-rose-600 uppercase tracking-[0.2em] mt-2 italic">{{ __("Enregistrement d'une charge ponctuelle") }}</p>
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

            {{-- Bandeau d'état hors-ligne (affiché par le script si pas de réseau) --}}
            <div id="offline-banner" class="hidden mb-6 p-4 bg-amber-100 text-amber-800 rounded-2xl text-[10px] font-black uppercase tracking-widest border border-amber-200">
                <i class="fa-solid fa-wifi mr-2"></i> {{ __("Mode hors-ligne : la dépense sera enregistrée localement puis synchronisée au retour du réseau.") }}
            </div>

            <form id="expense-form" method="POST" action="{{ route('expenses.store') }}">
                @csrf

                {{-- DÉPENSE --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-receipt text-rose-500"></i> {{ __("Détail de la dépense") }}
                    </h3>

                    <div class="space-y-6">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Libellé") }} *</label>
                            <input type="text" name="label" value="{{ old('label') }}" required placeholder="{{ __("Ex: Carburant moto livraison") }}"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none focus:ring-4 focus:ring-rose-500/10">
                        </div>

                        <div class="grid grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Catégorie") }} *</label>
                                <select name="category" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none appearance-none cursor-pointer">
                                    @foreach($categories as $key => $label)
                                        <option value="{{ $key }}" {{ old('category') === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Montant") }} ({{ setting('general.currency', 'GNF') }}) *</label>
                                <input type="number" name="amount" value="{{ old('amount') }}" min="1" step="1" required
                                    class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black text-rose-600 shadow-inner outline-none text-right" placeholder="0">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Date") }} *</label>
                                <input type="date" name="expense_date" value="{{ old('expense_date', now()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" required
                                    class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Mode de paiement") }} *</label>
                                <select name="payment_method" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none appearance-none cursor-pointer">
                                    @foreach($paymentMethods as $key => $label)
                                        <option value="{{ $key }}" {{ old('payment_method', 'especes') === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- RATTACHEMENT & BÉNÉFICIAIRE --}}
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
                                    <option value="{{ $batch->id }}" {{ (string) old('batch_id') === (string) $batch->id ? 'selected' : '' }}>{{ $batch->code }}</option>
                                @endforeach
                            </select>
                            <p class="text-[8px] text-slate-400 ml-2 italic">{{ __("Rattacher à un lot impacte directement sa marge nette.") }}</p>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Bénéficiaire / Fournisseur") }}</label>
                            <input type="text" name="supplier_name" value="{{ old('supplier_name') }}" placeholder="{{ __("Nom (facultatif)") }}"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none">
                        </div>
                    </div>

                    <div class="space-y-2 mt-6">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Notes") }}</label>
                        <textarea name="notes" rows="2" placeholder="{{ __("Justificatif, référence reçu, contexte...") }}"
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none">{{ old('notes') }}</textarea>
                    </div>
                </div>

                <button type="submit" class="w-full bg-rose-500 text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] hover:bg-rose-600 transition-all shadow-2xl italic border-none cursor-pointer">
                    <i class="fa-solid fa-floppy-disk mr-2"></i> {{ __("Enregistrer la Dépense") }}
                </button>
            </form>
        </div>
    </div>

    {{-- 📴 SUPPORT HORS-LIGNE (cohérent avec la vente rapide) --}}
    <script>
        (function () {
            const offline = !navigator.onLine || {{ config('app.database_down', false) ? 'true' : 'false' }};
            const banner = document.getElementById('offline-banner');
            if (offline && banner) banner.classList.remove('hidden');

            const form = document.getElementById('expense-form');
            form.addEventListener('submit', async function (e) {
                const isOffline = !navigator.onLine || {{ config('app.database_down', false) ? 'true' : 'false' }};
                if (!isOffline) return; // En ligne : soumission HTTP normale.

                e.preventDefault();

                const amount = parseFloat(form.querySelector('[name=amount]').value) || 0;
                const label = form.querySelector('[name=label]').value.trim();
                if (!label || amount <= 0) {
                    alert(@json(__("Veuillez renseigner un libellé et un montant valide.")));
                    return;
                }

                const batchVal = form.querySelector('[name=batch_id]').value;
                const expense = {
                    uuid: (crypto.randomUUID ? crypto.randomUUID() : 'exp-' + Date.now() + '-' + Math.random().toString(16).slice(2)),
                    category: form.querySelector('[name=category]').value,
                    label: label,
                    amount: amount,
                    expense_date: form.querySelector('[name=expense_date]').value || new Date().toISOString().slice(0, 10),
                    payment_method: form.querySelector('[name=payment_method]').value,
                    batch_id: batchVal ? parseInt(batchVal, 10) : null,
                    supplier_name: form.querySelector('[name=supplier_name]').value || null,
                    notes: form.querySelector('[name=notes]').value || null,
                    is_synced: 0,
                };

                try {
                    await window.db.expenses.add(expense);
                    alert(@json(__("📴 Hors-ligne : dépense enregistrée localement. Elle sera synchronisée (en attente de validation) au retour du réseau.")));
                    window.location.href = "{{ route('expenses.index') }}";
                } catch (err) {
                    console.error(@json(__("Échec de l'enregistrement hors-ligne de la dépense :")), err);
                    alert(@json(__("Erreur lors de l'enregistrement hors-ligne. Réessayez.")));
                }
            });
        })();
    </script>
</x-app-layout>
