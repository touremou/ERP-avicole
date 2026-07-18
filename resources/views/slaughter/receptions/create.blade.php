<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Nouvelle réception du vif')" :subtitle="__('CCP 1 — Contrôle ante-mortem (enregistrement immuable)')" icon="fa-truck-ramp-box" accent="rose" :back="route('slaughter.receptions.index')" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left" x-data="receptionForm()" x-cloak>

            @can('abattoir.C')
                @if($errors->any())
                    <div class="mb-8 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200"><i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('slaughter.receptions.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                        <div class="space-y-6">

                            <div class="grid grid-cols-2 gap-6">
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Éleveur livreur *") }}</label>
                                    <select name="provider_id" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                        <option value="">{{ __("— Sélectionner —") }}</option>
                                        @foreach($providers as $p)
                                            <option value="{{ $p->id }}" @selected(old('provider_id') == $p->id)>{{ $p->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Date de réception *") }}</label>
                                    <input type="date" name="reception_date" value="{{ old('reception_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                                </div>
                            </div>

                            {{-- ORIGINE : achat (dette éleveur + charge P&L) ou façon (prestation, sans coût matière) --}}
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Origine des sujets *") }}</label>
                                <div class="grid grid-cols-2 gap-3">
                                    <label class="cursor-pointer">
                                        <input type="radio" name="origin" value="achat" x-model="origin" class="peer sr-only">
                                        <div class="p-3 rounded-2xl bg-slate-50 text-center text-[10px] font-black uppercase peer-checked:bg-emerald-500 peer-checked:text-white transition-all">🛒 {{ __("Achat") }}</div>
                                    </label>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="origin" value="facon" x-model="origin" class="peer sr-only">
                                        <div class="p-3 rounded-2xl bg-slate-50 text-center text-[10px] font-black uppercase peer-checked:bg-indigo-500 peer-checked:text-white transition-all">🤝 {{ __("À façon") }}</div>
                                    </label>
                                </div>
                                <p class="text-[9px] font-bold text-slate-400 ml-2" x-show="origin === 'facon'" x-transition>{{ __("À façon : sujets propriété du client, facturés en prestation — aucun coût d'achat.") }}</p>
                            </div>

                            {{-- ACHAT : prix connu → facture fournisseur brouillon générée (dette + charge). Optionnel : sinon complété au bureau. --}}
                            <div class="grid grid-cols-2 gap-6 p-4 bg-emerald-50/50 rounded-2xl" x-show="origin === 'achat'" x-transition>
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Base de prix") }}</label>
                                    <select name="purchase_basis" x-model="basis" class="w-full bg-white border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                        <option value="par_sujet">{{ __("Par sujet") }}</option>
                                        <option value="par_kg_vif">{{ __("Au kg vif") }}</option>
                                        <option value="forfait">{{ __("Forfait") }}</option>
                                    </select>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2" x-text="basis === 'forfait' ? @js(__('Montant total (GNF)')) : (basis === 'par_kg_vif' ? @js(__('Prix / kg vif (GNF)')) : @js(__('Prix / sujet (GNF)')))"></label>
                                    <input type="number" name="purchase_unit_price" value="{{ old('purchase_unit_price') }}" step="0.01" min="0" class="w-full bg-white border-none rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-center" placeholder="{{ __('optionnel') }}">
                                </div>
                            </div>

                            <div class="grid grid-cols-3 gap-4">
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Qté annoncée") }}</label>
                                    <input type="number" name="announced_quantity" value="{{ old('announced_quantity') }}" min="0" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-center">
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Qté reçue *") }}</label>
                                    <input type="number" name="received_quantity" value="{{ old('received_quantity') }}" min="1" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-center">
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Qté écartée") }}</label>
                                    <input type="number" name="rejected_quantity" value="{{ old('rejected_quantity', 0) }}" min="0" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-center">
                                </div>
                            </div>

                            <div class="grid grid-cols-3 gap-4">
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Poids vif total (kg) *") }}</label>
                                    <input type="number" name="total_live_weight_kg" value="{{ old('total_live_weight_kg') }}" step="0.01" min="0.1" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-center">
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("État sanitaire *") }}</label>
                                    <select name="sanitary_state" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                        <option value="conforme" @selected(old('sanitary_state') === 'conforme')>{{ __("Conforme") }}</option>
                                        <option value="reserves" @selected(old('sanitary_state') === 'reserves')>{{ __("Avec réserves") }}</option>
                                        <option value="non_conforme" @selected(old('sanitary_state') === 'non_conforme')>{{ __("Non conforme") }}</option>
                                    </select>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Diète respectée *") }}</label>
                                    <select name="fasting_respected" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                        <option value="oui" @selected(old('fasting_respected') === 'oui')>{{ __("Oui") }}</option>
                                        <option value="partielle" @selected(old('fasting_respected') === 'partielle')>{{ __("Partielle") }}</option>
                                        <option value="non" @selected(old('fasting_respected') === 'non')>{{ __("Non") }}</option>
                                    </select>
                                </div>
                            </div>

                            {{-- DÉCISION — 3 gros boutons radio --}}
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Décision *") }}</label>
                                <div class="grid grid-cols-3 gap-3">
                                    <label class="cursor-pointer">
                                        <input type="radio" name="decision" value="accepte" x-model="decision" class="peer sr-only" required @checked(old('decision') === 'accepte')>
                                        <div class="p-5 rounded-2xl border-2 text-center transition-all peer-checked:bg-emerald-500 peer-checked:text-white peer-checked:border-emerald-500 bg-emerald-50 border-emerald-100 text-emerald-600">
                                            <i class="fa-solid fa-check text-xl block mb-2"></i>
                                            <span class="text-[9px] font-black uppercase tracking-widest">{{ __("Accepté") }}</span>
                                        </div>
                                    </label>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="decision" value="accepte_avec_decote" x-model="decision" class="peer sr-only" @checked(old('decision') === 'accepte_avec_decote')>
                                        <div class="p-5 rounded-2xl border-2 text-center transition-all peer-checked:bg-amber-500 peer-checked:text-white peer-checked:border-amber-500 bg-amber-50 border-amber-100 text-amber-600">
                                            <i class="fa-solid fa-scale-unbalanced text-xl block mb-2"></i>
                                            <span class="text-[9px] font-black uppercase tracking-widest">{{ __("Accepté avec décote") }}</span>
                                        </div>
                                    </label>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="decision" value="refuse" x-model="decision" class="peer sr-only" @checked(old('decision') === 'refuse')>
                                        <div class="p-5 rounded-2xl border-2 text-center transition-all peer-checked:bg-red-600 peer-checked:text-white peer-checked:border-red-600 bg-red-50 border-red-100 text-red-600">
                                            <i class="fa-solid fa-ban text-xl block mb-2"></i>
                                            <span class="text-[9px] font-black uppercase tracking-widest">{{ __("Refusé") }}</span>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            {{-- MOTIF — obligatoire si décision ≠ accepté --}}
                            <div class="space-y-2" x-show="decision && decision !== 'accepte'" x-transition>
                                <label class="text-[9px] font-black uppercase text-red-500 tracking-widest ml-2">{{ __("Motif de la décision *") }}</label>
                                <textarea name="decision_reason" rows="2" :required="decision !== 'accepte'" placeholder="{{ __('Mortalité en caisse, état sanitaire, diète non respectée...') }}" class="w-full bg-red-50 border-2 border-red-100 rounded-2xl p-4 text-xs font-bold shadow-inner outline-none">{{ old('decision_reason') }}</textarea>
                            </div>

                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Photo du certificat sanitaire") }}</label>
                                <input type="file" name="photo" accept="image/jpeg,image/png" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none">
                                <p class="text-[8px] text-slate-400 ml-2">{{ __("JPG ou PNG, 5 Mo max.") }}</p>
                            </div>

                            <div class="p-4 bg-slate-50 rounded-2xl">
                                <p class="text-[8px] font-black text-slate-500 uppercase"><i class="fa-solid fa-lock mr-1"></i> {{ __("Registre HACCP immuable : cet enregistrement ne pourra plus être modifié ni supprimé après validation.") }}</p>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-rose-500 hover:bg-rose-600 text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] transition-all shadow-2xl italic border-none cursor-pointer">
                        <i class="fa-solid fa-clipboard-check mr-2"></i> {{ __("Valider la réception") }}
                    </button>
                </form>
            @else
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                    <i class="fa-solid fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">{{ __("Accès Restreint") }}</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic">{{ __("Vous n'avez pas la permission d'enregistrer une réception.") }}</p>
                </div>
            @endcan
        </div>
    </div>

    <script>
    function receptionForm() {
        return {
            decision: @json(old('decision', '')),
            origin: @json(old('origin', 'achat')),
            basis: @json(old('purchase_basis', 'par_sujet')),
        }
    }
    </script>
</x-app-layout>
