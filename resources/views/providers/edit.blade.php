<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4 text-left">
                {{-- BOUTON RETOUR RAPIDE --}}
                <a href="{{ route('providers.show', $provider->id) }}" class="group flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 text-slate-400 hover:text-slate-800 rounded-xl transition shadow-sm no-underline">
                    <i class="fas fa-chevron-left group-hover:-translate-x-1 transition-transform"></i>
                    <span class="text-[10px] font-black uppercase italic tracking-widest">Retour</span>
                </a>
                <div>
                    <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                        {{ __('Modification du partenaire') }}
                    </h2>
                    <p class="text-[10px] font-black text-blue-500 uppercase tracking-widest mt-1 italic leading-none">
                        {{ $provider->name }}
                    </p>
                </div>
            </div>
            <div class="hidden md:block">
                <span class="px-4 py-2 bg-slate-900 rounded-xl text-[10px] font-black uppercase text-amber-400 italic tracking-widest border border-slate-800 shadow-lg">
                    <i class="fas fa-edit mr-2"></i> Mode Édition Actif
                </span>
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">

            {{-- PROTECTION PERMISSION MODIFICATION (M) --}}
            @can('annuaire.M')
                {{-- AFFICHAGE DES ERREURS --}}
                @if ($errors->any())
                    <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl animate-pulse text-left">
                        <h3 class="font-black uppercase text-xs mb-2 italic">⚠️ Erreur de saisie détectée</h3>
                        <ul class="text-[10px] font-bold list-disc ml-5 uppercase tracking-tighter">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('providers.update', $provider->id) }}" method="POST" class="space-y-8">
                    @csrf
                    @method('PUT')
                    
                    {{-- 01. IDENTITÉ & CONTACT --}}
                    <div class="bg-white p-10 rounded-[2.5rem] shadow-sm border border-slate-100 relative overflow-hidden text-left">
                        <div class="absolute top-0 left-0 w-2 h-full bg-blue-500"></div>
                        <h3 class="text-[10px] font-black text-blue-600 uppercase tracking-widest mb-8 flex items-center italic">
                            <i class="fas fa-id-card mr-3"></i> 01. Identité & Contact
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="md:col-span-2">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest leading-none">Raison Sociale / Nom Complet</label>
                                <input type="text" name="name" value="{{ old('name', $provider->name) }}" required 
                                       class="w-full p-4 bg-slate-50 rounded-2xl border-2 border-transparent focus:border-blue-500 outline-none font-black text-slate-700 transition-all shadow-inner italic">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest leading-none">Type de partenaire</label>
                                <select name="type" class="w-full p-4 bg-slate-50 rounded-2xl font-black text-xs uppercase outline-none border-2 border-transparent focus:border-blue-500 shadow-inner appearance-none italic cursor-pointer">
                                    <option value="Fournisseur" {{ old('type', $provider->type) == 'Fournisseur' ? 'selected' : '' }}>🚚 Fournisseur</option>
                                    <option value="Prestataire" {{ old('type', $provider->type) == 'Prestataire' ? 'selected' : '' }}>🛠 Prestataire</option>
                                    <option value="Partenaire" {{ old('type', $provider->type) == 'Partenaire' ? 'selected' : '' }}>🤝 Partenaire</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest leading-none">Domaine (Aliment, Poussins...)</label>
                                <input type="text" name="domain" value="{{ old('domain', $provider->domain) }}" 
                                       class="w-full p-4 bg-slate-50 rounded-2xl font-black outline-none border-2 border-transparent focus:border-blue-500 shadow-inner italic">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest leading-none">Téléphone</label>
                                <input type="text" name="phone" value="{{ old('phone', $provider->phone) }}" required 
                                       class="w-full p-4 bg-slate-50 rounded-2xl font-black outline-none border-2 border-transparent focus:border-blue-500 shadow-inner italic">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest leading-none">Email</label>
                                <input type="email" name="email" value="{{ old('email', $provider->email) }}" 
                                       class="w-full p-4 bg-slate-50 rounded-2xl font-black outline-none border-2 border-transparent focus:border-blue-500 shadow-inner italic">
                            </div>

                            <div class="md:col-span-2">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest leading-none">Adresse Géographique</label>
                                <input type="text" name="address" value="{{ old('address', $provider->address) }}" placeholder="Ville, Quartier, Rue..." 
                                       class="w-full p-4 bg-slate-50 rounded-2xl font-black outline-none border-2 border-transparent focus:border-blue-500 shadow-inner italic">
                            </div>
                        </div>
                    </div>

                    {{-- 02. ADMINISTRATIF & PAIEMENT --}}
                    <div class="bg-white p-10 rounded-[2.5rem] shadow-sm border border-slate-100 relative overflow-hidden text-left">
                        <div class="absolute top-0 left-0 w-2 h-full bg-emerald-500"></div>
                        <h3 class="text-[10px] font-black text-emerald-600 uppercase tracking-widest mb-8 flex items-center italic">
                            <i class="fas fa-file-invoice mr-3"></i> 02. Administratif & Paiement
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest leading-none">Numéro RCCM</label>
                                <input type="text" name="rccm" value="{{ old('rccm', $provider->rccm) }}" 
                                       class="w-full p-4 bg-slate-50 rounded-2xl font-mono font-black text-sm outline-none border-2 border-transparent focus:border-emerald-500 shadow-inner italic">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest leading-none">Numéro NIF / IFU</label>
                                <input type="text" name="nif" value="{{ old('nif', $provider->nif) }}" 
                                       class="w-full p-4 bg-slate-50 rounded-2xl font-mono font-black text-sm outline-none border-2 border-transparent focus:border-emerald-500 shadow-inner italic">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest leading-none">Conditions de Paiement</label>
                                <input type="text" name="payment_terms" value="{{ old('payment_terms', $provider->payment_terms) }}" placeholder="Ex: Cash, 30 jours..." 
                                       class="w-full p-4 bg-slate-50 rounded-2xl font-black outline-none border-2 border-transparent focus:border-emerald-500 shadow-inner italic">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest leading-none">Niveau de Fiabilité</label>
                                <select name="reliability" class="w-full p-4 bg-slate-50 rounded-2xl font-black text-xs uppercase outline-none border-2 border-transparent focus:border-emerald-500 shadow-inner appearance-none italic cursor-pointer">
                                    <option value="Bon" {{ old('reliability', $provider->reliability) == 'Bon' ? 'selected' : '' }}>🌟 Excellent (A+)</option>
                                    <option value="Moyen" {{ old('reliability', $provider->reliability) == 'Moyen' ? 'selected' : '' }}>🔄 Correct (B)</option>
                                    <option value="Mauvais" {{ old('reliability', $provider->reliability) == 'Mauvais' ? 'selected' : '' }}>⚠️ Risqué (C)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    {{-- ACTIONS --}}
                    <div class="flex flex-col md:flex-row gap-4 pt-4">
                        <a href="{{ route('providers.show', $provider->id) }}" 
                           class="flex-1 bg-white border-2 border-slate-100 text-slate-400 font-black py-7 rounded-[2.5rem] shadow-sm hover:bg-slate-50 hover:text-slate-600 transition-all text-center uppercase tracking-[0.2em] text-xs italic flex items-center justify-center gap-2 no-underline">
                            <i class="fas fa-times"></i> Abandonner
                        </a>

                        <button type="submit" class="flex-[2] bg-slate-900 text-white font-black py-7 rounded-[2.5rem] shadow-2xl hover:bg-blue-600 transition-all transform hover:-translate-y-1 uppercase tracking-widest text-xs italic group">
                            <i class="fas fa-save mr-2 group-hover:scale-110 transition-transform"></i>
                            Mettre à jour la fiche partenaire
                        </button>
                    </div>
                    
                    <p class="text-center text-[9px] font-black text-slate-400 uppercase mt-4 tracking-widest opacity-50 italic">
                        Dernière révision : {{ $provider->updated_at->format('d/m/Y à H:i') }}
                    </p>
                </form>
            @else
                {{-- ACCÈS REFUSÉ --}}
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                    <i class="fas fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter leading-none">Accès Restreint</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic leading-none">Vous n'avez pas la permission (M) pour modifier les informations de ce partenaire.</p>
                    <a href="{{ route('providers.show', $provider->id) }}" class="inline-block mt-8 px-10 py-4 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase italic no-underline hover:bg-blue-500 transition-all">Retour à la Fiche</a>
                </div>
            @endcan

        </div>
    </div>
</x-app-layout>