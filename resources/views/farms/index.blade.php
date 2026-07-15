<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Multi-Sites" :subtitle="__(':count ferme(s) enregistrée(s)', ['count' => $farms->count()])" icon="fa-city" accent="violet">
            <x-slot name="actions">
                <button onclick="document.getElementById('newFarmModal').classList.remove('hidden')"
                        class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-violet-600 transition-all shadow-2xl italic border-none cursor-pointer flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> {{ __("Nouvelle Ferme") }}
                </button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- ═══ GRILLE DES FERMES ═══ --}}
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 mb-10">
                @foreach($farms as $farm)
                @php
                    $isCurrentFarm = ($currentFarmId ?? 0) == $farm->id;
                    // KPI rapides par ferme (via withoutFarm). On exige
                    // initial_quantity > 0 pour exclure les lots virtuels de
                    // transit (ex. « Zone Fournisseurs Externes »), exactement
                    // comme le fait le dashboard — sinon le décompte des lots
                    // actifs diverge entre les deux vues.
                    $farmBirds = \App\Models\Batch::withoutGlobalScopes()->where('farm_id', $farm->id)->where('status', 'Actif')->where('initial_quantity', '>', 0)->sum('current_quantity');
                    $farmBatches = \App\Models\Batch::withoutGlobalScopes()->where('farm_id', $farm->id)->where('status', 'Actif')->where('initial_quantity', '>', 0)->count();
                    $farmBuildings = \App\Models\Building::withoutGlobalScopes()->physical()->where('farm_id', $farm->id)->count();
                @endphp
                <div @class(['rounded-[2.5rem] border shadow-sm overflow-hidden transition-all',
                    'bg-violet-50 border-violet-300 ring-2 ring-violet-400' => $isCurrentFarm,
                    'bg-white border-slate-100 hover:shadow-lg' => !$isCurrentFarm])>

                    {{-- EN-TÊTE FERME --}}
                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div class="flex items-center gap-3">
                                <div @class(['w-12 h-12 rounded-2xl flex items-center justify-center text-white font-black text-sm shadow-lg',
                                    'bg-violet-500' => $isCurrentFarm, 'bg-slate-400' => !$isCurrentFarm])>
                                    {{ $farm->code }}
                                </div>
                                <div>
                                    <p class="text-sm font-black text-slate-900 uppercase italic leading-none">{{ $farm->name }}</p>
                                    <p class="text-[8px] text-slate-400 font-black uppercase tracking-widest mt-1">
                                        {{ $farm->city ?? '' }} {{ $farm->region ? '— '.$farm->region : '' }}
                                    </p>
                                </div>
                            </div>
                            @if($isCurrentFarm)
                                <span class="text-[8px] font-black text-violet-600 bg-violet-100 px-3 py-1 rounded-full uppercase">{{ __("Active") }}</span>
                            @else
                                <form method="POST" action="{{ route('farms.switch') }}">
                                    @csrf
                                    <input type="hidden" name="farm_id" value="{{ $farm->id }}">
                                    <button type="submit" class="text-[8px] font-black text-slate-400 bg-slate-100 px-3 py-1 rounded-full uppercase hover:bg-violet-100 hover:text-violet-600 transition-all border-none cursor-pointer">
                                        {{ __("Basculer →") }}
                                    </button>
                                </form>
                            @endif
                        </div>

                        {{-- KPI FERME --}}
                        <div class="grid grid-cols-3 gap-3 mb-4">
                            <div class="bg-slate-50 rounded-xl p-3 text-center">
                                <p class="text-lg font-black text-slate-900">{{ number_format($farmBirds) }}</p>
                                <p class="text-[7px] font-black text-slate-400 uppercase">{{ __("Sujets") }}</p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-3 text-center">
                                <p class="text-lg font-black text-slate-900">{{ $farmBatches }}</p>
                                <p class="text-[7px] font-black text-slate-400 uppercase">{{ __("Lots actifs") }}</p>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-3 text-center">
                                <p class="text-lg font-black text-slate-900">{{ $farmBuildings }}</p>
                                <p class="text-[7px] font-black text-slate-400 uppercase">{{ __("Bâtiments") }}</p>
                            </div>
                        </div>

                        {{-- INFOS --}}
                        <div class="space-y-1.5 mb-4">
                            @if($farm->manager_name)
                                <p class="text-[9px] text-slate-500"><i class="fa-solid fa-user-tie w-4 text-center text-slate-300 mr-1"></i> {{ $farm->manager_name }}</p>
                            @endif
                            @if($farm->phone)
                                <p class="text-[9px] text-slate-500"><i class="fa-solid fa-phone w-4 text-center text-slate-300 mr-1"></i> {{ $farm->phone }}</p>
                            @endif
                            @if($farm->address)
                                <p class="text-[9px] text-slate-500"><i class="fa-solid fa-location-dot w-4 text-center text-slate-300 mr-1"></i> {{ $farm->address }}</p>
                            @endif
                            {{-- Couverture météo : géocodage résolu (météo auto active) ? --}}
                            @php $geo = $farm->getSetting('geo'); @endphp
                            @if($geo && isset($geo['lat']))
                                <p class="text-[9px] text-emerald-600"><i class="fa-solid fa-satellite-dish w-4 text-center mr-1"></i> {{ __("Météo auto active") }} · {{ round($geo['lat'], 3) }}, {{ round($geo['lon'], 3) }}</p>
                            @elseif($farm->city || $farm->region)
                                <p class="text-[9px] text-sky-600"><i class="fa-solid fa-cloud w-4 text-center mr-1"></i> {{ __("Météo auto : localisation prête") }}</p>
                            @else
                                <p class="text-[9px] text-amber-600"><i class="fa-solid fa-triangle-exclamation w-4 text-center mr-1"></i> {{ __("Ville absente — météo auto indisponible") }}</p>
                            @endif
                        </div>

                        {{-- UTILISATEURS --}}
                        <div class="flex items-center justify-between pt-4 border-t border-slate-100">
                            <div class="flex -space-x-2">
                                @foreach($farm->users->take(5) as $u)
                                    <div class="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center text-[8px] font-black text-slate-500 border-2 border-white" title="{{ $u->name }}">
                                        {{ strtoupper(substr($u->name, 0, 2)) }}
                                    </div>
                                @endforeach
                                @if($farm->users_count > 5)
                                    <div class="w-8 h-8 rounded-full bg-slate-900 flex items-center justify-center text-[8px] font-black text-white border-2 border-white">
                                        +{{ $farm->users_count - 5 }}
                                    </div>
                                @endif
                            </div>
                            @php $farmJson = $farm->only(['id', 'name', 'city', 'region', 'address', 'phone', 'manager_name']); @endphp
                            <div class="flex items-center gap-3">
                                <button onclick='openEditModal(@json($farmJson))'
                                    class="text-[8px] font-black text-slate-500 uppercase tracking-widest hover:text-violet-700 border-none bg-transparent cursor-pointer">
                                    <i class="fa-solid fa-pen-to-square mr-1"></i> {{ __("Éditer") }}
                                </button>
                                <button onclick="openUserModal({{ $farm->id }}, '{{ addslashes($farm->name) }}')"
                                    class="text-[8px] font-black text-violet-500 uppercase tracking-widest hover:text-violet-700 border-none bg-transparent cursor-pointer">
                                    <i class="fa-solid fa-user-gear mr-1"></i> {{ __("Gérer") }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach

                {{-- CARTE AJOUT --}}
                <button onclick="document.getElementById('newFarmModal').classList.remove('hidden')"
                        class="rounded-[2.5rem] border-2 border-dashed border-slate-200 p-10 flex flex-col items-center justify-center gap-3 hover:border-violet-400 hover:bg-violet-50/30 transition-all cursor-pointer bg-transparent min-h-[280px]">
                    <div class="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center text-slate-300">
                        <i class="fa-solid fa-plus text-2xl"></i>
                    </div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ __("Ajouter un site") }}</p>
                </button>
            </div>

            {{-- ═══ VUE GLOBALE CROSS-FERMES ═══ --}}
            <div class="bg-slate-900 p-8 rounded-[3rem] text-white shadow-2xl">
                <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-6 flex items-center gap-2">
                    <i class="fa-solid fa-globe text-violet-400"></i> {{ __("Vue consolidée — Toutes les fermes") }}
                </h3>
                @php
                    $totalBirds = \App\Models\Batch::withoutGlobalScopes()->where('status', 'Actif')->where('initial_quantity', '>', 0)->sum('current_quantity');
                    $totalBatches = \App\Models\Batch::withoutGlobalScopes()->where('status', 'Actif')->where('initial_quantity', '>', 0)->count();
                    $totalBuildings = \App\Models\Building::withoutGlobalScopes()->physical()->count();
                @endphp
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="p-5 bg-white/5 rounded-2xl text-center">
                        <p class="text-2xl font-black text-white">{{ $farms->count() }}</p>
                        <p class="text-[8px] font-black text-slate-500 uppercase">{{ __("Fermes") }}</p>
                    </div>
                    <div class="p-5 bg-white/5 rounded-2xl text-center">
                        <p class="text-2xl font-black text-emerald-400">{{ number_format($totalBirds) }}</p>
                        <p class="text-[8px] font-black text-slate-500 uppercase">{{ __("Sujets total") }}</p>
                    </div>
                    <div class="p-5 bg-white/5 rounded-2xl text-center">
                        <p class="text-2xl font-black text-blue-400">{{ $totalBatches }}</p>
                        <p class="text-[8px] font-black text-slate-500 uppercase">{{ __("Lots actifs") }}</p>
                    </div>
                    <div class="p-5 bg-white/5 rounded-2xl text-center">
                        <p class="text-2xl font-black text-amber-400">{{ $totalBuildings }}</p>
                        <p class="text-[8px] font-black text-slate-500 uppercase">{{ __("Bâtiments") }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══ MODAL : NOUVELLE FERME ═══ --}}
    <div id="newFarmModal" class="hidden fixed inset-0 bg-slate-900/90 backdrop-blur-xl z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[3rem] w-full max-w-xl shadow-2xl overflow-hidden text-left italic font-bold">
            <div class="px-10 py-8 border-b border-slate-100 flex justify-between items-center">
                <h3 class="text-xl font-black text-slate-800 uppercase tracking-tighter italic leading-none">Nouveau Site</h3>
                <button onclick="document.getElementById('newFarmModal').classList.add('hidden')" class="text-slate-300 hover:text-slate-900 border-none bg-transparent cursor-pointer text-xl"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-10">
                <form action="{{ route('farms.store') }}" method="POST" class="space-y-5">
                    @csrf
                    <div class="grid grid-cols-3 gap-4">
                        <div class="col-span-2 space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Nom du site *</label>
                            <input type="text" name="name" required placeholder="Ferme Avicole de Dubréka"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-sm shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Code *</label>
                            <input type="text" name="code" required placeholder="DUB" maxlength="20"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-lg uppercase shadow-inner outline-none text-center">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Ville</label>
                            <input type="text" name="city" placeholder="Dubréka"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Région</label>
                            <input type="text" name="region" placeholder="Kindia"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Adresse</label>
                        <input type="text" name="address" placeholder="Route nationale, KM 45..."
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Directeur de site</label>
                            <input type="text" name="manager_name" placeholder="Nom complet"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Téléphone</label>
                            <input type="text" name="phone" placeholder="+224 6XX..."
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-violet-600 text-white py-5 rounded-2xl font-black text-xs uppercase tracking-[0.3em] hover:bg-violet-700 transition-all border-none cursor-pointer italic shadow-xl">
                        <i class="fas fa-building mr-2"></i> Créer le Site
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- ═══ MODAL : ÉDITER UNE FERME ═══ --}}
    <div id="editFarmModal" class="hidden fixed inset-0 bg-slate-900/90 backdrop-blur-xl z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[3rem] w-full max-w-xl shadow-2xl overflow-hidden text-left italic font-bold">
            <div class="px-10 py-8 border-b border-slate-100 flex justify-between items-center">
                <h3 class="text-xl font-black text-slate-800 uppercase tracking-tighter italic leading-none">{{ __("Éditer") }} — <span id="editFarmTitle"></span></h3>
                <button onclick="document.getElementById('editFarmModal').classList.add('hidden')" class="text-slate-300 hover:text-slate-900 border-none bg-transparent cursor-pointer text-xl"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-10">
                <form id="editFarmForm" method="POST" class="space-y-5">
                    @csrf
                    @method('PUT')
                    <div class="space-y-2">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Nom du site") }} *</label>
                        <input type="text" name="name" id="edit_name" required
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-sm shadow-inner outline-none">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Ville") }}</label>
                            <input type="text" name="city" id="edit_city" placeholder="Dubréka"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Région") }}</label>
                            <input type="text" name="region" id="edit_region" placeholder="Kindia"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                    </div>
                    <p class="text-[8px] text-sky-600 font-black uppercase tracking-widest ml-2 -mt-2"><i class="fa-solid fa-cloud mr-1"></i> {{ __("La ville (ou région) active la météo automatique de ce site.") }}</p>
                    <div class="space-y-2">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Adresse") }}</label>
                        <input type="text" name="address" id="edit_address" placeholder="Route nationale, KM 45..."
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Directeur de site") }}</label>
                            <input type="text" name="manager_name" id="edit_manager_name" placeholder="Nom complet"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Téléphone") }}</label>
                            <input type="text" name="phone" id="edit_phone" placeholder="+224 6XX..."
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-violet-600 text-white py-5 rounded-2xl font-black text-xs uppercase tracking-[0.3em] hover:bg-violet-700 transition-all border-none cursor-pointer italic shadow-xl">
                        <i class="fas fa-save mr-2"></i> {{ __("Enregistrer") }}
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- ═══ MODAL : GÉRER LES UTILISATEURS D'UNE FERME ═══ --}}
    <div id="userFarmModal" class="hidden fixed inset-0 bg-slate-900/90 backdrop-blur-xl z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[3rem] w-full max-w-lg shadow-2xl overflow-hidden text-left italic font-bold">
            <div class="px-10 py-8 border-b border-slate-100 flex justify-between items-center">
                <h3 class="text-lg font-black text-slate-800 uppercase tracking-tighter italic leading-none">Accès — <span id="modalFarmName"></span></h3>
                <button onclick="document.getElementById('userFarmModal').classList.add('hidden')" class="text-slate-300 hover:text-slate-900 border-none bg-transparent cursor-pointer text-xl"><i class="fas fa-times"></i></button>
            </div>
            <form id="userFarmForm" method="POST" class="p-10">
                @csrf
                <p class="text-[9px] text-slate-400 uppercase tracking-widest font-black mb-6">Cochez les utilisateurs qui ont accès à ce site :</p>
                <div class="space-y-2 max-h-[40vh] overflow-y-auto pr-2">
                    @foreach($users as $u)
                    <label class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-50 cursor-pointer transition-all">
                        <input type="checkbox" name="user_ids[]" value="{{ $u->id }}" class="hidden peer">
                        <div class="w-6 h-6 rounded-lg border-2 border-slate-200 peer-checked:bg-violet-500 peer-checked:border-violet-500 flex items-center justify-center transition-all">
                            <i class="fas fa-check text-[8px] text-white"></i>
                        </div>
                        <div class="w-9 h-9 rounded-xl bg-slate-100 flex items-center justify-center text-[9px] font-black text-slate-400 uppercase">{{ strtoupper(substr($u->name, 0, 2)) }}</div>
                        <div>
                            <p class="text-xs font-black text-slate-800 uppercase">{{ $u->name }}</p>
                            <p class="text-[8px] text-slate-400">{{ $u->email }}</p>
                        </div>
                    </label>
                    @endforeach
                </div>
                <button type="submit" class="w-full mt-6 bg-violet-600 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-violet-700 transition-all border-none cursor-pointer italic">
                    <i class="fas fa-save mr-2"></i> Enregistrer les Accès
                </button>
            </form>
        </div>
    </div>

    <script>
    function openUserModal(farmId, farmName) {
        document.getElementById('modalFarmName').innerText = farmName;
        document.getElementById('userFarmForm').action = '/farms/' + farmId + '/users';
        document.getElementById('userFarmModal').classList.remove('hidden');
    }

    function openEditModal(farm) {
        document.getElementById('editFarmTitle').innerText = farm.name || '';
        document.getElementById('editFarmForm').action = '/farms/' + farm.id;
        document.getElementById('edit_name').value = farm.name || '';
        document.getElementById('edit_city').value = farm.city || '';
        document.getElementById('edit_region').value = farm.region || '';
        document.getElementById('edit_address').value = farm.address || '';
        document.getElementById('edit_manager_name').value = farm.manager_name || '';
        document.getElementById('edit_phone').value = farm.phone || '';
        document.getElementById('editFarmModal').classList.remove('hidden');
    }
    </script>
</x-app-layout>
