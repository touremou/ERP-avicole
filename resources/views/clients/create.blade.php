<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Nouveau Client')" :subtitle="__('Enregistrement d\'un partenaire commercial')" icon="fa-users" accent="teal" :back="route('clients.index')" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @if($errors->any())
                <div class="mb-8 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200">
                    <i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('clients.store') }}">
                @csrf

                {{-- IDENTITÉ --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-user text-teal-500"></i> {{ __("Identité") }}
                    </h3>

                    <div class="space-y-6">
                        <div class="space-y-2">
                            {{-- 💡 ID ajouté sur le label pour le modifier dynamiquement --}}
                            <label id="name-label" class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Nom / Raison sociale *") }}</label>
                            <input type="text" name="name" value="{{ old('name') }}" required
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none focus:ring-4 focus:ring-teal-500/10">
                        </div>

                        <div class="grid grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Type *") }}</label>
                                {{-- 💡 ID ajouté sur le select --}}
                                <select id="type-select" name="type" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none appearance-none cursor-pointer">
                                    <option value="particulier" {{ old('type') === 'particulier' ? 'selected' : '' }}>{{ __("Particulier") }}</option>
                                    <option value="entreprise" {{ old('type') === 'entreprise' ? 'selected' : '' }}>{{ __("Entreprise") }}</option>
                                </select>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Catégorie *") }}</label>
                                <select name="category" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none appearance-none cursor-pointer">
                                    <option value="detaillant" {{ old('category', 'detaillant') === 'detaillant' ? 'selected' : '' }}>{{ __("Détaillant") }}</option>
                                    <option value="grossiste" {{ old('category') === 'grossiste' ? 'selected' : '' }}>{{ __("Grossiste") }}</option>
                                    <option value="hotel_restaurant" {{ old('category') === 'hotel_restaurant' ? 'selected' : '' }}>{{ __("Hôtel / Restaurant") }}</option>
                                    <option value="revendeur" {{ old('category') === 'revendeur' ? 'selected' : '' }}>{{ __("Revendeur") }}</option>
                                    <option value="autre" {{ old('category') === 'autre' ? 'selected' : '' }}>{{ __("Autre") }}</option>
                                </select>
                            </div>
                            @if(($priceLists ?? collect())->isNotEmpty())
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Groupe de prix") }}</label>
                                <select name="price_list_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none appearance-none cursor-pointer">
                                    <option value="">{{ __("Tarif par défaut") }}</option>
                                    @foreach($priceLists as $pl)
                                        <option value="{{ $pl->id }}" {{ (string) old('price_list_id') === (string) $pl->id ? 'selected' : '' }}>{{ $pl->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- CONTACT --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-phone text-blue-500"></i> {{ __("Contact") }}
                    </h3>

                    <div class="grid grid-cols-2 gap-6 mb-6">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Téléphone") }}</label>
                            <input type="text" name="phone" value="{{ old('phone') }}" placeholder="+224 6XX XX XX XX"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Email") }}</label>
                            <input type="email" name="email" value="{{ old('email') }}" placeholder="contact@exemple.com"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none">
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Adresse") }}</label>
                        <textarea name="address" rows="2" placeholder="{{ __('Quartier, commune, ville...') }}"
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none">{{ old('address') }}</textarea>
                    </div>
                </div>

                {{-- FISCAL & CRÉDIT --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-building-columns text-purple-500"></i> {{ __("Fiscal & Crédit") }}
                    </h3>

                    {{-- 💡 ID ajouté sur le bloc fiscal pour le masquer facilement --}}
                    <div id="fiscal-fields" class="grid grid-cols-2 gap-6 mb-6 transition-all duration-300">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">NIF</label>
                            <input type="text" name="nif" value="{{ old('nif') }}" placeholder='{{ __("Numéro d'identification fiscale") }}'
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">RCCM</label>
                            <input type="text" name="rccm" value="{{ old('rccm') }}" placeholder="{{ __('Registre du commerce') }}"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Plafond crédit") }} ({{ setting('general.currency', 'GNF') }})</label>
                        <input type="number" name="credit_limit" value="{{ old('credit_limit', setting('ventes.credit_limit_default', 0)) }}" min="0" step="100000"
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black text-purple-600 shadow-inner outline-none text-right" placeholder="0">
                        <p class="text-[8px] text-slate-400 ml-2 italic">{{ __("0 = pas de plafond (crédit illimité). Sinon, le système bloquera les ventes au-delà.") }}</p>
                    </div>
                </div>

                {{-- NOTES --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <div class="space-y-2">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Notes internes") }}</label>
                        <textarea name="notes" rows="3" placeholder="{{ __('Informations complémentaires...') }}"
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none">{{ old('notes') }}</textarea>
                    </div>
                </div>

                <button type="submit" class="w-full bg-teal-500 text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] hover:bg-teal-600 transition-all shadow-2xl italic border-none cursor-pointer">
                    <i class="fa-solid fa-user-plus mr-2"></i> {{ __("Enregistrer le Client") }}
                </button>
            </form>
        </div>
    </div>

    {{-- 🚀 SCRIPT DYNAMIQUE --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const typeSelect = document.getElementById('type-select');
            const nameLabel = document.getElementById('name-label');
            const fiscalFields = document.getElementById('fiscal-fields');

            function toggleFormMode() {
                if (typeSelect.value === 'particulier') {
                    nameLabel.innerHTML = @json(__("Nom & Prénom *"));
                    fiscalFields.style.display = 'none';
                    // Optionnel : vider les champs cachés pour ne pas envoyer de fausses données
                    document.querySelector('input[name="nif"]').value = '';
                    document.querySelector('input[name="rccm"]').value = '';
                } else {
                    nameLabel.innerHTML = @json(__("Nom / Raison sociale *"));
                    fiscalFields.style.display = 'grid'; // Utilise 'grid' pour conserver la mise en page
                }
            }

            // Exécuter au chargement pour appliquer l'état initial (utile si on revient avec une erreur old())
            toggleFormMode();

            // Écouter les changements
            typeSelect.addEventListener('change', toggleFormMode);
        });
    </script>
</x-app-layout>