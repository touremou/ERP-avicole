<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-5 text-left">
            <x-back :to="route('clients.show', $client)" />
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Modifier") }} {{ $client->name }}</h2>
                <p class="text-[10px] font-black text-teal-600 uppercase tracking-[0.2em] mt-2 italic">{{ $client->client_id }}</p>
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

            <form method="POST" action="{{ route('clients.update', $client) }}">
                @csrf @method('PUT')

                {{-- IDENTITÉ --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-user text-teal-500"></i> {{ __("Identité") }}
                    </h3>
                    <div class="space-y-6">
                        <div class="space-y-2">
                            <label id="name-label" class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Nom / Raison sociale *") }}</label>
                            <input type="text" name="name" value="{{ old('name', $client->name) }}" required
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none focus:ring-4 focus:ring-teal-500/10">
                        </div>
                        <div class="grid grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Type *") }}</label>
                                <select id="type-select" name="type" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none appearance-none cursor-pointer">
                                    <option value="particulier" {{ old('type', $client->type) === 'particulier' ? 'selected' : '' }}>{{ __("Particulier") }}</option>
                                    <option value="entreprise" {{ old('type', $client->type) === 'entreprise' ? 'selected' : '' }}>{{ __("Entreprise") }}</option>
                                </select>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Catégorie *") }}</label>
                                <select name="category" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none appearance-none cursor-pointer">
                                    <option value="detaillant" {{ old('category', $client->category) === 'detaillant' ? 'selected' : '' }}>{{ __("Détaillant") }}</option>
                                    <option value="grossiste" {{ old('category', $client->category) === 'grossiste' ? 'selected' : '' }}>{{ __("Grossiste") }}</option>
                                    <option value="hotel_restaurant" {{ old('category', $client->category) === 'hotel_restaurant' ? 'selected' : '' }}>{{ __("Hôtel / Restaurant") }}</option>
                                    <option value="revendeur" {{ old('category', $client->category) === 'revendeur' ? 'selected' : '' }}>{{ __("Revendeur") }}</option>
                                    <option value="autre" {{ old('category', $client->category) === 'autre' ? 'selected' : '' }}>{{ __("Autre") }}</option>
                                </select>
                            </div>
                            @if(($priceLists ?? collect())->isNotEmpty())
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Groupe de prix") }}</label>
                                <select name="price_list_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none appearance-none cursor-pointer">
                                    <option value="">{{ __("Tarif par défaut") }}</option>
                                    @foreach($priceLists as $pl)
                                        <option value="{{ $pl->id }}" {{ (string) old('price_list_id', $client->price_list_id) === (string) $pl->id ? 'selected' : '' }}>{{ $pl->name }}</option>
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
                            <input type="text" name="phone" value="{{ old('phone', $client->phone) }}"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Email") }}</label>
                            <input type="email" name="email" value="{{ old('email', $client->email) }}"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Adresse") }}</label>
                        <textarea name="address" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none">{{ old('address', $client->address) }}</textarea>
                    </div>
                </div>

                {{-- FISCAL & CRÉDIT --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-building-columns text-purple-500"></i> {{ __("Fiscal & Crédit") }}
                    </h3>
                    <div id="fiscal-fields" class="grid grid-cols-2 gap-6 mb-6 transition-all duration-300">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">NIF</label>
                            <input type="text" name="nif" value="{{ old('nif', $client->nif) }}"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">RCCM</label>
                            <input type="text" name="rccm" value="{{ old('rccm', $client->rccm) }}"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Plafond crédit") }} ({{ setting('general.currency', 'GNF') }})</label>
                        <input type="number" name="credit_limit" value="{{ old('credit_limit', $client->credit_limit) }}" min="0" step="100000"
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black text-purple-600 shadow-inner outline-none text-right">
                    </div>
                </div>

                {{-- STATUT --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-toggle-on text-emerald-500"></i> {{ __("Statut") }}
                    </h3>
                    <div class="flex gap-4">
                        @foreach(['actif' => __('Actif'), 'suspendu' => __('Suspendu'), 'blackliste' => __('Blacklisté')] as $val => $label)
                        <label class="flex items-center gap-3 cursor-pointer group">
                            <input type="radio" name="status" value="{{ $val }}" {{ old('status', $client->status) === $val ? 'checked' : '' }} class="hidden peer">
                            <div @class([
                                'px-6 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest transition-all border-2',
                                'peer-checked:bg-emerald-500 peer-checked:text-white peer-checked:border-emerald-500' => $val === 'actif',
                                'peer-checked:bg-amber-500 peer-checked:text-white peer-checked:border-amber-500' => $val === 'suspendu',
                                'peer-checked:bg-red-500 peer-checked:text-white peer-checked:border-red-500' => $val === 'blackliste',
                                'border-slate-200 text-slate-400 hover:border-slate-400',
                            ])>{{ $label }}</div>
                        </label>
                        @endforeach
                    </div>
                    @if($client->status === 'blackliste')
                        <p class="text-[8px] text-red-500 mt-3 italic">{{ __("Un client blacklisté ne peut plus passer de commande.") }}</p>
                    @endif
                </div>

                {{-- NOTES --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <div class="space-y-2">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Notes internes") }}</label>
                        <textarea name="notes" rows="3" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none">{{ old('notes', $client->notes) }}</textarea>
                    </div>
                </div>

                <button type="submit" class="w-full bg-teal-500 text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] hover:bg-teal-600 transition-all shadow-2xl italic border-none cursor-pointer">
                    <i class="fa-solid fa-floppy-disk mr-2"></i> {{ __("Enregistrer les Modifications") }}
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
                    // Pas de remise à zéro systématique sur l'édition pour ne pas effacer de données sans faire exprès
                } else {
                    nameLabel.innerHTML = @json(__("Nom / Raison sociale *"));
                    fiscalFields.style.display = 'grid';
                }
            }

            toggleFormMode();
            typeSelect.addEventListener('change', toggleFormMode);
        });
    </script>
</x-app-layout>