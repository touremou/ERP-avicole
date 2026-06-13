<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
            <div class="text-left">
                <h2 class="text-3xl font-black text-slate-800 tracking-tighter uppercase italic leading-none">
                    {{ __('Annuaire Partenaires') }}
                </h2>
                <div class="flex items-center gap-2 mt-2">
                    <span class="w-8 h-1 bg-blue-600 rounded-full"></span>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] italic">{{ __("Gestion des Accouveurs & Fournisseurs") }}</p>
                </div>
            </div>
            
            {{-- Permission C : Ajout de partenaire --}}
            @can('annuaire.C')
            <a href="{{ route('providers.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-xl shadow-slate-900/20 group italic no-underline">
                <i class="fas fa-plus-circle mr-2 group-hover:rotate-90 transition-transform text-blue-400"></i> {{ __("Nouveau Partenaire") }}
            </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- BARRE DE RECHERCHE DYNAMIQUE --}}
            <div class="mb-6 max-w-2xl mx-auto md:mx-0">
                <div class="relative group">
                    <div class="absolute inset-y-0 left-0 pl-6 flex items-center pointer-events-none text-left">
                        <i class="fas fa-search text-slate-300 group-focus-within:text-blue-500 transition-colors"></i>
                    </div>
                    <input type="text" id="providerSearch" onkeyup="searchProvider()"
                           placeholder="{{ __('Rechercher un partenaire (Nom, Type, Domaine)...') }}"
                           class="w-full pl-14 pr-20 py-5 bg-white border border-slate-100 rounded-[2.5rem] shadow-sm outline-none focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 transition-all font-black text-[10px] uppercase tracking-widest italic shadow-inner">

                    <div class="absolute inset-y-0 right-0 pr-8 flex items-center">
                        <span id="searchCount" class="text-[9px] font-black text-blue-500 uppercase italic bg-blue-50 px-3 py-1 rounded-full hidden"></span>
                    </div>
                </div>
            </div>

            {{-- FILTRE DE STATUT --}}
            <div class="mb-10 flex items-center gap-2 max-w-2xl mx-auto md:mx-0">
                @foreach([
                    'Actif'      => __('Actifs'),
                    'Blacklisté' => __('Blacklistés'),
                    'all'        => __('Tous'),
                ] as $value => $label)
                    <a href="{{ route('providers.index', ['status' => $value]) }}"
                       class="px-5 py-2 rounded-full text-[9px] font-black uppercase tracking-widest italic transition-all no-underline
                              {{ $status === $value ? 'bg-slate-900 text-white shadow-md' : 'bg-white border border-slate-100 text-slate-400 hover:text-slate-700' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            {{-- GRILLE DES PARTENAIRES --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8" id="providers-grid">
                @forelse($providers as $provider)
                    <div class="provider-card bg-white rounded-[3rem] border border-slate-100 shadow-sm hover:shadow-2xl hover:-translate-y-2 transition-all duration-500 overflow-hidden group text-left" data-type="{{ $provider->type }}">
                        
                        <div class="p-8 pb-4 relative">
                            {{-- Badge de fiabilité visuel --}}
                            <div class="absolute top-6 right-8">
                                @if($provider->reliability == 'Bon')
                                    <i class="fas fa-certificate text-emerald-500 text-lg shadow-sm" title="{{ __('Partenaire de confiance') }}"</i>
                                @elseif($provider->reliability == 'Mauvais')
                                    <i class="fas fa-exclamation-triangle text-red-500 animate-pulse" title="{{ __('Partenaire à surveiller') }}"</i>
                                @endif
                            </div>

                            <div class="flex items-center gap-4 mb-8">
                                <div class="w-16 h-16 rounded-[1.5rem] bg-slate-900 flex items-center justify-center text-yellow-500 text-2xl shadow-lg overflow-hidden group-hover:bg-blue-600 group-hover:text-white transition-all duration-500">
                                    @if($provider->logo_path)
                                        <img src="{{ media_url($provider->logo_path) }}" class="w-full h-full object-cover" alt="{{ $provider->name }}">
                                    @else
                                        <i class="fas fa-handshake"></i>
                                    @endif
                                </div>
                                <div class="overflow-hidden">
                                    <span class="text-[8px] font-black text-blue-500 uppercase tracking-widest italic">{{ $provider->type }}</span>
                                    @if($provider->status !== 'Actif')
                                        <span class="ml-2 text-[8px] font-black text-red-500 uppercase tracking-widest italic">{{ $provider->status }}</span>
                                    @endif
                                    <h3 class="text-xl font-black text-slate-800 uppercase tracking-tighter leading-none mt-1 truncate">{{ $provider->name }}</h3>
                                </div>
                            </div>

                            <div class="space-y-4 mb-6">
                                <div class="flex items-center group/item">
                                    <div class="w-8 h-8 rounded-lg bg-slate-50 flex items-center justify-center mr-3 text-slate-300 group-hover/item:text-blue-500 transition-colors">
                                        <i class="fas fa-phone-alt text-[10px]"></i>
                                    </div>
                                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-tight">{{ $provider->phone }}</span>
                                </div>

                                <div class="flex items-center group/item">
                                    <div class="w-8 h-8 rounded-lg bg-slate-50 flex items-center justify-center mr-3 text-slate-300 group-hover/item:text-blue-500 transition-colors">
                                        <i class="fas fa-layer-group text-[10px]"></i>
                                    </div>
                                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-tight truncate">{{ $provider->domain ?? __("Généraliste") }}</span>
                                </div>

                                <div class="flex items-center group/item">
                                    <div @class([
                                        'w-8 h-8 rounded-lg flex items-center justify-center mr-3 transition-colors',
                                        'bg-emerald-50 text-emerald-500' => $provider->reliability == 'Bon',
                                        'bg-slate-50 text-slate-300' => $provider->reliability != 'Bon',
                                    ])>
                                        <i class="fas fa-shield-check text-[10px]"></i>
                                    </div>
                                    <span @class([
                                        'text-[10px] font-black uppercase tracking-tight',
                                        'text-emerald-500' => $provider->reliability == 'Bon',
                                        'text-orange-500' => $provider->reliability == 'Moyen',
                                        'text-red-500' => $provider->reliability == 'Mauvais',
                                    ])>
                                        {{ __("Fiabilité :") }} {{ $provider->reliability }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="bg-slate-50/50 p-6 flex justify-between items-center border-t border-slate-50">
                            <div class="flex gap-4 items-center">
                                {{-- Permission M : Édition --}}
                                @can('annuaire.M')
                                <a href="{{ route('providers.edit', $provider->id) }}" class="text-slate-300 hover:text-blue-600 transition-colors">
                                    <i class="fas fa-pen-nib text-xs"></i>
                                </a>

                                {{-- Réactivation rapide d'un partenaire blacklisté --}}
                                @if($provider->status === 'Blacklisté')
                                    <form action="{{ route('providers.blacklist', $provider->id) }}" method="POST" class="inline">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="status" value="Actif">
                                        <button type="submit" class="text-[9px] font-black text-emerald-600 uppercase tracking-widest italic hover:text-emerald-700 transition-colors">
                                            <i class="fas fa-check-circle mr-1"></i> {{ __("Réactiver") }}
                                        </button>
                                    </form>
                                @endif
                                @endcan
                            </div>

                            {{-- Permission L : Consultation --}}
                            @can('annuaire.L')
                            <a href="{{ route('providers.show', $provider->id) }}"
                               class="px-8 py-3 bg-white border border-slate-200 rounded-xl text-[9px] font-black uppercase tracking-widest text-slate-900 hover:bg-slate-900 hover:text-white transition-all shadow-sm italic no-underline">
                                {{ __("Voir Fiche") }}
                            </a>
                            @endcan
                        </div>
                    </div>
                @empty
                    <div class="col-span-full py-32 text-center bg-white rounded-[3rem] border border-dashed border-slate-200">
                        <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
                            <i class="fas fa-address-book text-3xl text-slate-200"></i>
                        </div>
                        <p class="text-slate-400 font-black uppercase text-[10px] italic tracking-widest">{{ __("Aucun partenaire dans cette catégorie") }}</p>
                    </div>
                @endforelse
            </div>

            {{-- ZONE DE MAINTENANCE (ACCÈS CORBEILLE - Permission S) --}}
            @can('annuaire.S')
            <div class="mt-24 py-10 border-t border-slate-100 flex justify-center">
                <a href="{{ route('trash.index') }}" class="group flex items-center gap-4 bg-slate-50 px-6 py-3 rounded-2xl hover:bg-slate-900 transition-all duration-500 border border-dashed border-slate-200 hover:border-slate-800 no-underline">
                    <div class="flex flex-col items-start leading-none text-left">
                        <span class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1 group-hover:text-slate-500 italic">{{ __("Maintenance Annuaire") }}</span>
                        <span class="text-[10px] font-black text-slate-400 uppercase italic group-hover:text-white transition-colors">{{ __("Relations archivées & Partenaires supprimés") }}</span>
                    </div>
                    <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center text-slate-400 group-hover:bg-red-600 group-hover:text-white transition-all shadow-sm">
                        <i class="fas fa-trash-restore text-xs"></i>
                    </div>
                </a>
            </div>
            @endcan

        </div>
    </div>

    <script>
        function searchProvider() {
            const input = document.getElementById('providerSearch');
            const filter = input.value.toUpperCase();
            const cards = document.getElementsByClassName('provider-card');
            const countSpan = document.getElementById('searchCount');
            let visibleCount = 0;

            for (let i = 0; i < cards.length; i++) {
                const textContent = cards[i].innerText || cards[i].textContent;
                
                if (textContent.toUpperCase().indexOf(filter) > -1) {
                    cards[i].style.display = "";
                    cards[i].style.animation = "fadeIn 0.3s ease forwards";
                    visibleCount++;
                } else {
                    cards[i].style.display = "none";
                }
            }

            if (filter === "") {
                countSpan.classList.add('hidden');
            } else {
                countSpan.classList.remove('hidden');
                countSpan.innerText = visibleCount + " " + @json(__("Trouvé(s)"));
            }
        }
    </script>

    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        /* Custom scrollbar for better UX if list is long */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
    </style>
</x-app-layout>