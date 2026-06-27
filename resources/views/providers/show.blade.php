<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div class="flex items-center gap-4 text-left">
                {{-- RETOUR DYNAMIQUE (L) --}}
                <a href="{{ route('providers.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 text-slate-500 hover:text-slate-800 rounded-xl transition-all shadow-sm group no-underline">
                    <i class="fas fa-chevron-left group-hover:-translate-x-1 transition-transform"></i>
                    <span class="text-[10px] font-black uppercase italic tracking-widest">{{ __("Retour") }}</span>
                </a>

                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    {{ __('Fiche Partenaire :') }} {{ $provider->name }}
                </h2>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                {{-- ACTION : RELEVÉ / DETTES (module Finance) --}}
                @can('depenses.L')
                <a href="{{ route('purchases.statement', $provider->id) }}"
                   class="bg-white border border-slate-200 px-5 py-2.5 rounded-xl text-[10px] font-black uppercase text-slate-600 hover:bg-rose-600 hover:text-white hover:border-rose-600 transition shadow-sm tracking-widest flex items-center italic no-underline">
                    <i class="fas fa-file-invoice-dollar mr-2 text-rose-500 group-hover:text-white"></i> {{ __("Relevé / dettes") }}
                </a>
                @endcan

                {{-- ACTION : MODIFIER (M) --}}
                @can('annuaire.M')
                <a href="{{ route('providers.edit', $provider->id) }}"
                   class="bg-white border border-slate-200 px-5 py-2.5 rounded-xl text-[10px] font-black uppercase text-slate-600 hover:bg-blue-600 hover:text-white hover:border-blue-600 transition shadow-sm tracking-widest flex items-center italic no-underline">
                    <i class="fas fa-edit mr-2 text-blue-500 group-hover:text-white"></i> {{ __("Modifier") }}
                </a>

                {{-- ACTION : BLACKLIST / RÉACTIVER (M) --}}
                <form action="{{ route('providers.blacklist', $provider->id) }}" method="POST" class="inline">
                    @csrf @method('PUT')
                    @if($provider->status == 'Blacklisté')
                        <input type="hidden" name="status" value="Actif">
                        <button type="submit" class="bg-emerald-50 text-emerald-600 border border-emerald-100 px-5 py-2.5 rounded-xl text-[10px] font-black uppercase shadow-sm hover:bg-emerald-600 hover:text-white transition flex items-center tracking-widest italic">
                            <i class="fas fa-check-circle mr-2"></i> {{ __("Réactiver") }}
                        </button>
                    @else
                        <button type="submit" onclick="return confirm(@json(__('Placer ce partenaire sur liste noire ?')))"
                                class="bg-orange-50 text-orange-600 border border-orange-100 px-5 py-2.5 rounded-xl text-[10px] font-black uppercase shadow-sm hover:bg-orange-600 hover:text-white transition flex items-center tracking-widest italic">
                            <i class="fas fa-ban mr-2"></i> {{ __("Blacklister") }}
                        </button>
                    @endif
                </form>
                @endcan

                <div class="h-8 w-[1px] bg-slate-200 mx-1"></div>

                {{-- ACTION : ARCHIVAGE (S) --}}
                @can('annuaire.S')
                    @php $isLocked = $provider->batches()->live()->where('status', 'Actif')->exists(); @endphp

                    @if($isLocked)
                        <div class="group relative inline-block">
                            <button type="button" class="cursor-not-allowed opacity-50 bg-slate-100 text-slate-400 border border-slate-200 px-5 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest italic leading-none">
                                <i class="fas fa-lock mr-2"></i> {{ __("Désactiver") }}
                            </button>
                            <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block w-48 p-3 bg-slate-900 text-white text-[8px] font-bold uppercase rounded-xl text-center shadow-xl z-50 italic tracking-tighter leading-tight">
                                <i class="fas fa-info-circle text-orange-400 mr-1"></i> 
                                {{ __("Action impossible : ce partenaire est lié à un lot actif.") }}
                            </div>
                        </div>
                    @else
                        <form action="{{ route('providers.destroy', $provider->id) }}" method="POST" 
                              onsubmit="return confirm(@json(__('Confirmer l\'archivage ? L\'historique sera conservé dans la corbeille.')));" class="inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="bg-red-50 text-red-600 border border-red-100 px-5 py-2.5 rounded-xl text-[10px] font-black uppercase shadow-sm hover:bg-red-600 hover:text-white transition flex items-center tracking-widest italic">
                                <i class="fas fa-archive mr-2"></i> {{ __("Désactiver") }}
                            </button>
                        </form>
                    @endif
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                {{-- COLONNE GAUCHE : IDENTITÉ --}}
                <div class="space-y-6">
                    <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100 text-center relative overflow-hidden">
                        @if($provider->status == 'Blacklisté')
                            <div class="absolute top-0 right-0 bg-red-600 text-white px-10 py-1 rotate-45 translate-x-8 translate-y-4 text-[10px] font-black uppercase tracking-widest shadow-lg">{{ __("Banni") }}</div>
                        @endif

                        <div class="w-24 h-24 mx-auto mb-6 rounded-3xl bg-slate-900 flex items-center justify-center text-yellow-500 text-3xl shadow-xl shadow-slate-900/20 overflow-hidden transition-transform hover:scale-105 duration-500">
                            @if($provider->logo_path)
                                <img src="{{ media_url($provider->logo_path) }}" class="w-full h-full object-cover" alt="{{ $provider->name }}">
                            @else
                                <i class="fas fa-industry"></i>
                            @endif
                        </div>
                        
                        <h2 class="text-2xl font-black text-slate-800 leading-tight tracking-tighter uppercase">{{ $provider->name }}</h2>
                        <div class="inline-block mt-3 px-4 py-1.5 rounded-xl bg-blue-50 text-blue-600 font-black uppercase text-[9px] tracking-[0.2em] border border-blue-100">
                            {{ $provider->type }}
                        </div>
                        
                        <div class="mt-8 pt-8 border-t border-slate-50 space-y-4 text-left">
                            <div class="flex items-center group p-3 bg-slate-50/50 rounded-2xl shadow-inner border border-slate-100">
                                <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center text-slate-400 group-hover:text-blue-500 transition shadow-sm border border-slate-100">
                                    <i class="fas fa-hashtag text-xs"></i>
                                </div>
                                <div class="ml-4">
                                    <p class="text-[9px] font-black uppercase text-slate-400 tracking-widest italic leading-none mb-1">{{ __("ID Interne") }}</p>
                                    <p class="font-mono font-bold text-slate-700 uppercase tracking-tighter leading-none">{{ $provider->provider_id ?? 'N/A' }}</p>
                                </div>
                            </div>
                            
                            <div class="flex items-center group p-3 bg-slate-50/50 rounded-2xl shadow-inner border border-slate-100">
                                <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center text-slate-400 group-hover:text-emerald-500 transition shadow-sm border border-slate-100">
                                    <i class="fas fa-phone text-xs"></i>
                                </div>
                                <div class="ml-4 text-left">
                                    <p class="text-[9px] font-black uppercase text-slate-400 tracking-widest italic leading-none mb-1">{{ __("Ligne Directe") }}</p>
                                    <p class="font-bold text-slate-700 tracking-tighter leading-none">{{ $provider->phone }}</p>
                                </div>
                            </div>

                            @if($provider->email)
                            <div class="flex items-center group p-3 bg-slate-50/50 rounded-2xl shadow-inner border border-slate-100">
                                <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center text-slate-400 group-hover:text-blue-500 transition shadow-sm border border-slate-100">
                                    <i class="fas fa-envelope text-xs"></i>
                                </div>
                                <div class="ml-4 text-left">
                                    <p class="text-[9px] font-black uppercase text-slate-400 tracking-widest italic leading-none mb-1">{{ __("Email") }}</p>
                                    <p class="font-bold text-slate-700 tracking-tighter leading-none lowercase">{{ $provider->email }}</p>
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>

                    {{-- FIABILITÉ --}}
                    <div @class([
                        'p-8 rounded-[2.5rem] shadow-xl text-white flex items-center justify-between border-4 border-white/20',
                        'bg-gradient-to-br from-emerald-500 to-emerald-600 shadow-emerald-500/20' => $provider->reliability == 'Bon',
                        'bg-gradient-to-br from-orange-500 to-orange-600 shadow-orange-500/20' => $provider->reliability == 'Moyen',
                        'bg-gradient-to-br from-red-500 to-red-600 shadow-red-500/20' => $provider->reliability == 'Mauvais',
                    ])>
                        <div class="text-left">
                            <p class="text-[10px] font-black uppercase opacity-70 mb-1 tracking-widest italic">{{ __("Indice de Fiabilité") }}</p>
                            <p class="text-3xl font-black italic tracking-tighter uppercase leading-none">{{ $provider->reliability }}</p>
                        </div>
                        <i class="fas fa-shield-check text-4xl opacity-30"></i>
                    </div>
                </div>

                {{-- COLONNE DROITE : ADMINISTRATIF --}}
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-white p-10 rounded-[2.5rem] shadow-sm border border-slate-100 text-left">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 mb-8 tracking-[0.2em] flex items-center italic">
                            <span class="w-8 h-[2px] bg-slate-100 mr-3"></span> {{ __("Informations Administratives") }}
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 text-left">
                            <div class="relative pl-6 border-l-4 border-blue-500">
                                <p class="text-[10px] font-black text-slate-400 uppercase mb-1 tracking-widest italic leading-none">{{ __("Secteur") }}</p>
                                <p class="font-black text-slate-800 text-xl tracking-tighter uppercase leading-none">{{ $provider->domain ?? __('Généraliste') }}</p>
                            </div>
                            <div class="relative pl-6 border-l-4 border-emerald-500">
                                <p class="text-[10px] font-black text-slate-400 uppercase mb-1 tracking-widest italic leading-none">{{ __("Règlement habituel") }}</p>
                                <p class="font-black text-slate-800 text-xl tracking-tighter uppercase leading-none">{{ $provider->payment_terms ?? __('Non spécifié') }}</p>
                            </div>
                            <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100 shadow-inner">
                                <p class="text-[9px] font-black text-slate-400 uppercase mb-2 italic tracking-widest leading-none">{{ __("Registre (RCCM)") }}</p>
                                <p class="font-mono font-black text-slate-900 text-sm tracking-tighter leading-none">{{ $provider->rccm ?? __('NON DÉFINI') }}</p>
                            </div>
                            <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100 shadow-inner">
                                <p class="text-[9px] font-black text-slate-400 uppercase mb-2 italic tracking-widest leading-none">{{ __("NIF / IFU") }}</p>
                                <p class="font-mono font-black text-slate-900 text-sm tracking-tighter leading-none">{{ $provider->nif ?? __('NON DÉFINI') }}</p>
                            </div>
                        </div>

                        <div class="mt-12 p-8 bg-slate-50 rounded-[2.5rem] border border-dashed border-slate-200">
                            <p class="text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest italic">{{ __("Localisation du Siège") }}</p>
                            <p class="text-slate-700 font-bold flex items-start italic tracking-tight">
                                <i class="fas fa-map-marker-alt text-red-500 mt-1 mr-4"></i>
                                {{ $provider->address ?? __('Aucune adresse enregistrée pour ce partenaire.') }}
                            </p>
                        </div>
                    </div>

                    {{-- HISTORIQUE DES FLUX --}}
                    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden text-left">
                        <div class="p-8 border-b border-slate-50 bg-slate-50/30 flex justify-between items-center">
                            <div>
                                <h3 class="font-black text-slate-800 uppercase text-[10px] tracking-widest italic leading-none">{{ __("📦 Journal des Acquisitions") }}</h3>
                                <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 italic leading-none">{{ __("Performance des lots fournis") }}</p>
                            </div>
                            <span class="px-4 py-1.5 bg-slate-900 text-white rounded-full text-[9px] font-black uppercase italic shadow-lg">
                                {{ $provider->batches->count() }} {{ __("Lot(s)") }}
                            </span>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="text-[9px] font-black uppercase text-slate-400 bg-slate-50/50 tracking-widest italic">
                                        <th class="px-8 py-5">{{ __("Lot") }}</th>
                                        <th class="px-4 py-5">{{ __("Espèce") }}</th>
                                        <th class="px-4 py-5 text-center">{{ __("Initial") }}</th>
                                        <th class="px-4 py-5 text-center text-red-500">{{ __("Mortalité") }}</th>
                                        <th class="px-8 py-5 text-right">{{ __("État") }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    @forelse($provider->batches->sortByDesc('arrival_date') as $batch)
                                        <tr class="group hover:bg-slate-50 transition-all cursor-pointer" onclick="window.location='{{ route('batches.show', $batch->id) }}'">
                                            <td class="px-8 py-5">
                                                <p class="font-black text-blue-600 uppercase tracking-tighter group-hover:underline leading-none mb-1">{{ $batch->code }}</p>
                                                <p class="text-[9px] font-bold text-slate-400 italic leading-none">{{ \Carbon\Carbon::parse($batch->arrival_date)->translatedFormat('d M Y') }}</p>
                                            </td>
                                            <td class="px-4 py-5">
                                                <span class="text-[8px] font-black uppercase px-2 py-1 bg-slate-100 rounded-lg text-slate-600 italic">
                                                    {{ $batch->type }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-5 text-center font-black text-slate-700 uppercase tracking-tighter">
                                                {{ number_format($batch->initial_quantity) }}
                                            </td>
                                            <td class="px-4 py-5 text-center font-black text-red-500 italic">
                                                @php
                                                    $deaths = $batch->dailyChecks->sum('mortality');
                                                    $rate = ($batch->initial_quantity > 0) ? ($deaths / $batch->initial_quantity) * 100 : 0;
                                                @endphp
                                                {{ number_format($rate, 1) }}%
                                            </td>
                                            <td class="px-8 py-5 text-right">
                                                <span @class([
                                                    'px-3 py-1 rounded-xl text-[8px] font-black uppercase tracking-widest',
                                                    'bg-emerald-100 text-emerald-600' => $batch->status == 'Actif',
                                                    'bg-slate-100 text-slate-400' => $batch->status != 'Actif'
                                                ])>
                                                    {{ $batch->status }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="px-8 py-20 text-center">
                                                <i class="fas fa-history text-3xl text-slate-100 mb-3 block"></i>
                                                <p class="text-[10px] font-black text-slate-300 uppercase italic tracking-widest">{{ __("Aucune donnée historique") }}</p>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- ACCÈS CORBEILLE (S) --}}
                    @can('annuaire.S')
                    <div class="mt-10 py-6 border-t border-slate-100 flex justify-end">
                        <a href="{{ route('trash.index') }}" class="group flex items-center gap-4 bg-slate-50 px-5 py-2.5 rounded-2xl hover:bg-slate-900 transition-all duration-500 border border-dashed border-slate-200 hover:border-slate-800 no-underline">
                            <div class="flex flex-col items-end leading-none">
                                <span class="text-[7px] font-black text-slate-400 uppercase tracking-widest mb-1 group-hover:text-slate-500 italic">{{ __("Maintenance Partenaires") }}</span>
                                <span class="text-[9px] font-black text-slate-400 uppercase italic group-hover:text-white transition-colors">{{ __("Consulter les archives") }}</span>
                            </div>
                            <div class="w-8 h-8 bg-white rounded-xl flex items-center justify-center text-slate-400 group-hover:bg-blue-600 group-hover:text-white transition-all shadow-sm">
                                <i class="fas fa-archive text-[10px]"></i>
                            </div>
                        </a>
                    </div>
                    @endcan

                </div>
            </div>
        </div>
    </div>
</x-app-layout>