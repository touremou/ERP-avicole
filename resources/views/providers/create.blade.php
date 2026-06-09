<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="text-left">
                <h2 class="text-2xl font-black text-slate-800 tracking-tighter uppercase italic">
                    {{ __('🤝 Nouveau Partenaire') }}
                </h2>
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mt-1 italic leading-none">Enregistrer un nouveau fournisseur ou prestataire</p>
            </div>
            <a href="{{ route('providers.index') }}" class="px-6 py-3 bg-white border border-slate-200 text-slate-500 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-red-50 hover:text-red-600 transition shadow-sm no-underline">
                <i class="fas fa-times mr-2"></i> Annuler
            </a>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">

            {{-- VÉRIFICATION PERMISSION CRÉATION (C) --}}
            @can('annuaire.C')
                {{-- GESTION DES ERREURS --}}
                @if ($errors->any())
                    <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl text-left">
                        <h3 class="font-black uppercase text-xs mb-2 italic flex items-center">
                            <i class="fas fa-exclamation-triangle mr-2"></i> Erreur lors de la saisie
                        </h3>
                        <ul class="text-[10px] font-bold list-disc ml-5 uppercase tracking-wide">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('providers.store') }}" method="POST" class="space-y-8">
                    @csrf
                    
                    <div class="bg-white p-10 rounded-[2.5rem] shadow-sm border border-slate-100 relative overflow-hidden text-left">
                        <div class="absolute top-0 left-0 w-full h-2 bg-slate-900"></div>

                        {{-- SECTION 01 : PROFIL --}}
                        <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-8 flex items-center italic">
                            <i class="fas fa-info-circle mr-3 text-blue-500"></i> 01. Profil de l'entreprise
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div class="md:col-span-2">
                                <label class="block text-[10px] font-black uppercase text-slate-400 mb-2 ml-1 italic tracking-widest">Raison Sociale / Nom</label>
                                <input type="text" name="name" value="{{ old('name') }}" required placeholder="Ex: GNA-PRO Sarl" 
                                       class="w-full p-4 bg-slate-50 rounded-2xl border-2 border-transparent focus:border-blue-500 outline-none font-black text-slate-700 transition-all shadow-inner italic">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black uppercase text-slate-400 mb-2 ml-1 italic tracking-widest">Type de Partenaire</label>
                                <select name="type" class="w-full p-4 bg-slate-50 rounded-2xl font-black outline-none border-2 border-transparent focus:border-blue-500 shadow-inner appearance-none italic cursor-pointer">
                                    <option value="Fournisseur" @selected(old('type') == 'Fournisseur')>🚚 Fournisseur d'aliments/poussins</option>
                                    <option value="Prestataire" @selected(old('type') == 'Prestataire')>🛠 Prestataire de services</option>
                                    <option value="Partenaire" @selected(old('type') == 'Partenaire')>🤝 Partenaire stratégique</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black uppercase text-slate-400 mb-2 ml-1 italic tracking-widest">Domaine de compétence</label>
                                <input type="text" name="domain" value="{{ old('domain') }}" placeholder="Ex: Aviculture, Santé Animale" 
                                       class="w-full p-4 bg-slate-50 rounded-2xl font-black outline-none border-2 border-transparent focus:border-blue-500 shadow-inner italic">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black uppercase text-slate-400 mb-2 ml-1 italic tracking-widest">Téléphone principal</label>
                                <input type="text" name="phone" value="{{ old('phone') }}" required placeholder="+225 00 00 00 00" 
                                       class="w-full p-4 bg-slate-50 rounded-2xl font-black outline-none border-2 border-transparent focus:border-blue-500 shadow-inner italic">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black uppercase text-slate-400 mb-2 ml-1 italic tracking-widest">Adresse Email</label>
                                <input type="email" name="email" value="{{ old('email') }}" placeholder="contact@entreprise.com" 
                                       class="w-full p-4 bg-slate-50 rounded-2xl font-black outline-none border-2 border-transparent focus:border-blue-500 shadow-inner italic">
                            </div>
                        </div>

                        {{-- SECTION 02 : LÉGAL --}}
                        <div class="md:col-span-2 mt-12 mb-8 border-t border-slate-50 pt-10">
                            <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] flex items-center italic">
                                <i class="fas fa-file-invoice-dollar mr-3 text-green-500"></i> 02. Cadre Légal & Paiement
                            </h3>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div>
                                <label class="block text-[10px] font-black uppercase text-slate-400 mb-2 ml-1 italic tracking-widest">N° RCCM</label>
                                <input type="text" name="rccm" value="{{ old('rccm') }}" placeholder="CI-ABJ-0000-B-000" 
                                       class="w-full p-4 bg-slate-50 rounded-2xl outline-none font-mono font-black text-slate-600 border-2 border-transparent focus:border-green-500 shadow-inner">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black uppercase text-slate-400 mb-2 ml-1 italic tracking-widest">N° NIF (IFU)</label>
                                <input type="text" name="nif" value="{{ old('nif') }}" placeholder="0 000000 X" 
                                       class="w-full p-4 bg-slate-50 rounded-2xl outline-none font-mono font-black text-slate-600 border-2 border-transparent focus:border-green-500 shadow-inner">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black uppercase text-slate-400 mb-2 ml-1 italic tracking-widest">Délais de Paiement</label>
                                <input type="text" name="payment_terms" value="{{ old('payment_terms') }}" placeholder="Ex: 50% commande, 50% livraison" 
                                       class="w-full p-4 bg-slate-50 rounded-2xl outline-none font-black text-slate-700 border-2 border-transparent focus:border-green-500 shadow-inner italic">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black uppercase text-slate-400 mb-2 ml-1 italic tracking-widest">Indice de Fiabilité</label>
                                <select name="reliability" class="w-full p-4 bg-slate-50 rounded-2xl font-black outline-none border-2 border-transparent focus:border-green-500 shadow-inner appearance-none italic cursor-pointer">
                                    <option value="Bon" @selected(old('reliability') == 'Bon')>🌟 Établissement de confiance (A+)</option>
                                    <option value="Moyen" @selected(old('reliability', 'Moyen') == 'Moyen')>🔄 Partenaire régulier (B)</option>
                                    <option value="Mauvais" @selected(old('reliability') == 'Mauvais')>⚠️ À surveiller / Risqué (C)</option>
                                </select>
                            </div>
                        </div>

                        {{-- ACTIONS --}}
                        <div class="flex flex-col md:flex-row gap-4 pt-10">
                            <a href="{{ route('providers.index') }}" class="flex-1 bg-white border-2 border-slate-100 text-slate-400 font-black py-6 rounded-[2rem] shadow-sm hover:bg-slate-50 transition-all text-center uppercase tracking-widest text-[10px] italic flex items-center justify-center gap-2 no-underline">
                                <i class="fas fa-times"></i> Annuler
                            </a>              
                            <button type="submit" class="flex-[2] bg-slate-900 text-white font-black py-6 rounded-[2rem] hover:bg-emerald-600 transition-all uppercase tracking-[0.2em] text-[10px] italic shadow-xl group">
                                <i class="fas fa-check-circle mr-2 group-hover:scale-110 transition-transform"></i>
                                Confirmer l'adhésion du partenaire
                            </button>
                        </div>
                    </div>
                </form>
            @else
                {{-- ACCÈS REFUSÉ --}}
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                    <i class="fas fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">Accès Restreint</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic leading-none">La permission <span class="text-blue-500">annuaire.C</span> (Créer) est requise pour enregistrer un nouveau partenaire.</p>
                    <a href="{{ route('providers.index') }}" class="inline-block mt-8 px-10 py-4 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase italic no-underline hover:bg-emerald-500 transition-all">Retour au Journal</a>
                </div>
            @endcan

        </div>
    </div>
</x-app-layout>