{{-- Navigation AviSmart v8 — App Drawer + Breadcrumb Contextuel + Farm Switcher --}}
<nav x-data="{ mobileOpen: false }" class="sticky top-0 z-50 bg-white/95 backdrop-blur-md border-b border-slate-100 italic">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-14">
            <div class="flex items-center">

                {{-- LOGO --}}
                <a href="{{ route('dashboard') }}" class="shrink-0 flex items-center mr-4 hover:scale-105 transition-transform">
                    <x-application-logo class="block h-8 w-auto fill-current text-blue-600" />
                </a>

                {{-- APP DRAWER (grille modules, hover) --}}
                <div x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false" class="hidden sm:block relative">
                    <button class="flex items-center px-3 py-2 bg-slate-900 text-white rounded-xl text-[9px] font-black uppercase tracking-widest transition-all hover:bg-blue-600 shadow-md border-none cursor-pointer outline-none">
                        <i class="fa-solid fa-grip text-xs mr-1.5"></i> Modules <i class="fa-solid fa-chevron-down ms-1.5 text-[6px] opacity-40"></i>
                    </button>
                    <div x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100"
                         x-transition:leave="transition ease-in duration-100" x-transition:leave-end="opacity-0"
                         class="absolute left-0 top-full mt-1 w-[22rem] bg-white rounded-2xl shadow-2xl border border-slate-100 p-4 z-50" x-cloak>
                        <p class="text-[8px] font-black uppercase tracking-widest text-slate-400 mb-3 px-1 border-b border-slate-100 pb-2">Accès rapide</p>
                        <div class="grid grid-cols-3 gap-1.5">
                            @php
                                $modules = [
                                    ['route' => 'dashboard',             'icon' => 'fa-gauge-high',       'color' => 'blue',    'label' => 'Dashboard'],
                                    ['route' => 'employees.index',       'icon' => 'fa-users',            'color' => 'slate',   'label' => 'RH'],
                                    ['route' => 'buildings.index',       'icon' => 'fa-dove',             'color' => 'blue',    'label' => 'Élevage'],
                                    ['route' => 'egg-productions.index', 'icon' => 'fa-egg',              'color' => 'amber',   'label' => 'Production'],
                                    ['route' => 'incubations.index',     'icon' => 'fa-temperature-half', 'color' => 'pink',    'label' => 'Couvoir'],
                                    ['route' => 'provenderie.dashboard', 'icon' => 'fa-wheat-awn',        'color' => 'lime',    'label' => 'Provenderie'],
                                    ['route' => 'planning.index',        'icon' => 'fa-calendar-days',    'color' => 'indigo',  'label' => 'Planning'],
                                    ['route' => 'slaughter.dashboard',   'icon' => 'fa-drumstick-bite',   'color' => 'rose',    'label' => 'Abattoir'],
                                    ['route' => 'sales.index',           'icon' => 'fa-cash-register',    'color' => 'teal',    'label' => 'Commerce'],
                                    ['route' => 'stocks.index',          'icon' => 'fa-boxes-stacked',    'color' => 'orange',  'label' => 'Stocks'],
                                    ['route' => 'utilities.dashboard',   'icon' => 'fa-bolt',             'color' => 'cyan',    'label' => 'Ressources'],
                                    ['route' => 'users.index',           'icon' => 'fa-shield-halved',    'color' => 'purple',  'label' => 'Admin'],
                                ];
                            @endphp
                            {{-- Dans le bloc App Drawer, remplacez la boucle @foreach par ceci --}}
                            @foreach($modules as $m)
                                @php
                                    // On extrait le nom du module de la route ou du label pour déduire la permission (ex: stocks.index -> stock.L)
                                    // Vous pouvez ajuster cette logique selon votre nomenclature exacte
                                    $permMapping = [
                                        'RH' => 'rh.L', 'Élevage' => 'elevage.L', 'Production' => 'production.L', 
                                        'Couvoir' => 'couvoir.L', 'Provenderie' => 'provenderie.L', 'Planning' => 'planning.L',
                                        'Abattoir' => 'abattoir.L', 'Commerce' => 'commerce.L', 'Stocks' => 'stocks.L',
                                        'Ressources' => 'ressources.L', 'Admin' => 'admin.S'
                                    ];
                                    $requiredPerm = $permMapping[$m['label']] ?? null;
                                @endphp

                                @if(\Illuminate\Support\Facades\Route::has($m['route']))
                                    @if(!$requiredPerm || auth()->user()->can($requiredPerm))
                                        <a href="{{ route($m['route']) }}" class="flex flex-col items-center p-2.5 rounded-xl hover:bg-{{ $m['color'] }}-50 transition-all group no-underline">
                                            <div class="w-9 h-9 rounded-lg bg-{{ $m['color'] }}-50 text-{{ $m['color'] }}-500 flex items-center justify-center mb-1 group-hover:scale-110 transition-transform">
                                        <i class="fa-solid {{ $m['icon'] }} text-sm"></i>
                                    </div>
                                    <span class="text-[7px] font-black uppercase tracking-widest text-slate-500 text-center">{{ $m['label'] }}</span>
                                        </a>
                                    @endif
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- BREADCRUMB CONTEXTUEL --}}
                <div class="hidden lg:flex items-center ml-4 pl-4 border-l border-slate-200 h-8 gap-1">
                    @php
                        $linkClass = 'text-[9px] font-black uppercase tracking-widest px-2.5 py-1.5 rounded-lg transition-all no-underline';
                        $activeClass = 'bg-slate-100 text-slate-800';
                        $inactiveClass = 'text-slate-400 hover:text-slate-700 hover:bg-slate-50';
                    @endphp

                    @if(request()->routeIs('dashboard'))
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest"><i class="fa-solid fa-house mr-1 text-blue-500"></i> Accueil</span>
                    
                    @elseif(request()->routeIs(['employees.*', 'payroll.*', 'tasks.*']))
                       
                        <span class="text-[9px] font-black text-slate-800 uppercase tracking-widest mr-1"><i class="fa-solid fa-credit-card text-slate-500 mr-1"></i> RH</span>
                        
                        @canany(['rh.L', 'annuaire.L'])
                        <a href="{{ route('employees.index') }}" class="{{ $linkClass }} {{ request()->routeIs('employees.*') ? $activeClass : $inactiveClass }}">Employés</a>
                        @endcan
                        @can('rh.L')
                        <a href="{{ route('tasks.index') }}" class="{{ $linkClass }} {{ request()->routeIs('tasks.index') ? $activeClass : $inactiveClass }}">Tâches</a>
                        <a href="{{ route('payroll.index') }}" class="{{ $linkClass }} {{ request()->routeIs('payroll.index') ? $activeClass : $inactiveClass }}">Paie</a>
                        <a href="{{ route('payroll.leaves') }}" class="{{ $linkClass }} {{ request()->routeIs('payroll.leaves') ? $activeClass : $inactiveClass }}">Congés</a>
                        @endcan
                    @elseif(request()->routeIs(['buildings.*','batches.*', 'daily-checks.*', 'health.*', 'protocols.*', 'reports.*']))
                        
                        <span class="text-[9px] font-black text-slate-800 uppercase tracking-widest mr-1"><i class="fa-solid fa-dove text-blue-500 mr-1"></i> Élevage</span>
                        @can('elevage.L')
                        <a href="{{ route('buildings.index') }}" class="{{ $linkClass }} {{ request()->routeIs('buildings.*') ? $activeClass : $inactiveClass }}">Bâtiments</a>
                        <a href="{{ route('batches.index') }}" class="{{ $linkClass }} {{ request()->routeIs('batches.*') ? $activeClass : $inactiveClass }}">Lots</a>
                        <a href="{{ route('health.index') }}" class="{{ $linkClass }} {{ request()->routeIs('health.*') ? $activeClass : $inactiveClass }}">Santé</a>
                        @endcan
                        @can('elevage.M')
                        <a href="{{ route('protocols.index') }}" class="{{ $linkClass }} {{ request()->routeIs('protocols.*') ? $activeClass : $inactiveClass }}">Protocoles</a>
                        @endcan
                        @can('elevage.S')
                        <a href="{{ route('reports.index') }}" class="{{ $linkClass }} {{ request()->routeIs('reports.*') ? $activeClass : $inactiveClass }}">Rapports</a>
                        @endcan

                    @elseif(request()->routeIs(['egg-productions.*', 'egg-movements.*', 'incubations.*', 'chick-dispatches.*']))
                        <span class="text-[9px] font-black text-slate-800 uppercase tracking-widest mr-1"><i class="fa-solid fa-egg text-amber-500 mr-1"></i> Production</span>
                        @can('production.L')
                        <a href="{{ route('egg-productions.index') }}" class="{{ $linkClass }} {{ request()->routeIs('egg-productions.*') ? $activeClass : $inactiveClass }}">Collecte</a>
                        @endcan
                        @can('couvoir.L')
                        <a href="{{ route('incubations.index') }}" class="{{ $linkClass }} {{ request()->routeIs('incubations.*') ? $activeClass : $inactiveClass }}">Couvoir</a>
                        @endcan

                    @elseif(request()->routeIs(['provenderie.*', 'raw-materials.*', 'formulas.*', 'production.*', 'machines.*']))
                        
                        <span class="text-[9px] font-black text-slate-800 uppercase tracking-widest mr-1"><i class="fa-solid fa-wheat-awn text-lime-600 mr-1"></i> Provenderie</span>
                        @can('provenderie.L')                       
                        <a href="{{ route('provenderie.dashboard') }}" class="{{ $linkClass }} {{ request()->routeIs('provenderie.dashboard') ? $activeClass : $inactiveClass }}">Pilotage</a>
                        @endcan
                        @can('provenderie.C')
                        <a href="{{ route('raw-materials.index') }}" class="{{ $linkClass }} {{ request()->routeIs('raw-materials.*') ? $activeClass : $inactiveClass }}">MP</a>
                        <a href="{{ route('formulas.index') }}" class="{{ $linkClass }} {{ request()->routeIs('formulas.*') ? $activeClass : $inactiveClass }}">Formules</a>
                        <a href="{{ route('production.index') }}" class="{{ $linkClass }} {{ request()->routeIs('production.*') ? $activeClass : $inactiveClass }}">Production</a>
                        @endcan

                    @elseif(request()->routeIs('planning.*'))
                        <span class="text-[9px] font-black text-slate-800 uppercase tracking-widest mr-1"><i class="fa-solid fa-calendar-days text-indigo-500 mr-1"></i> Planning</span>
                         @can('planning.L')
                        <a href="{{ route('planning.index') }}" class="{{ $linkClass }} {{ request()->routeIs('planning.index') ? $activeClass : $inactiveClass }}">Calendrier</a>
                        @endcan
                        @can('planning.C')
                        <a href="{{ route('planning.create') }}" class="{{ $linkClass }} {{ request()->routeIs('planning.create') ? $activeClass : $inactiveClass }}">Planifier</a>
                        @endcan

                    @elseif(request()->routeIs('slaughter.*'))
                        <span class="text-[9px] font-black text-slate-800 uppercase tracking-widest mr-1"><i class="fa-solid fa-drumstick-bite text-rose-500 mr-1"></i> Abattoir</span>
                        @can('abattoir.L')
                        <a href="{{ route('slaughter.dashboard') }}" class="{{ $linkClass }} {{ request()->routeIs('slaughter.dashboard') ? $activeClass : $inactiveClass }}">Dashboard</a>
                        @endcan
                        @can('abattoir.C')
                        <a href="{{ route('slaughter.orders.create') }}" class="{{ $linkClass }} {{ request()->routeIs('slaughter.orders.*') ? $activeClass : $inactiveClass }}">Ordre</a>
                        <a href="{{ route('slaughter.transform.form') }}" class="{{ $linkClass }} {{ request()->routeIs('slaughter.transform.*') ? $activeClass : $inactiveClass }}">Transfo</a>
                        <a href="{{ route('slaughter.finished') }}" class="{{ $linkClass }} {{ request()->routeIs('slaughter.finished*') ? $activeClass : $inactiveClass }}">Produits Finis</a>
                        @endcan

                    @elseif(request()->routeIs(['sales.*', 'clients.*','providers.*', 'payments.*']))
                        <span class="text-[9px] font-black text-slate-800 uppercase tracking-widest mr-1"><i class="fa-solid fa-cash-register text-teal-500 mr-1"></i> Commerce</span>
                        @can('commerce.L')
                        <a href="{{ route('sales.index') }}" class="{{ $linkClass }} {{ request()->routeIs('sales.*') ? $activeClass : $inactiveClass }}">Ventes</a>
                        <a href="{{ route('payments.index') }}" class="{{ $linkClass }} {{ request()->routeIs('payments.*') ? $activeClass : $inactiveClass }}">Paiements</a>
                        @endcan
                        @canany(['commerce.L', 'annuaire.M'])
                        <a href="{{ route('clients.index') }}" class="{{ $linkClass }} {{ request()->routeIs('clients.*') ? $activeClass : $inactiveClass }}">Clients</a>
                        <a href="{{ route('providers.index') }}" class="{{ $linkClass }} {{ request()->routeIs('providers.*') ? $activeClass : $inactiveClass }}">Fournisseurs</a>
                        @endcan

                    @elseif(request()->routeIs(['stocks.*', 'dispatches.*']))
                        <span class="text-[9px] font-black text-slate-800 uppercase tracking-widest mr-1"><i class="fa-solid fa-boxes-stacked text-orange-500 mr-1"></i> Logistique</span>
                        @can('stocks.L')
                        <a href="{{ route('stocks.index') }}" class="{{ $linkClass }} {{ request()->routeIs('stocks.*') ? $activeClass : $inactiveClass }}">Stocks</a>
                        @endcan
                        @can('logistique.L')
                        <a href="{{ route('dispatches.index') }}" class="{{ $linkClass }} {{ request()->routeIs('dispatches.index') ? $activeClass : $inactiveClass }}">Expéditions</a>
                        <a href="{{ route('dispatches.discrepancies') }}" class="{{ $linkClass }} {{ request()->routeIs('dispatches.discrepancies') ? 'bg-red-50 text-red-600' : 'text-red-400 hover:text-red-600 hover:bg-red-50' }}">Écarts</a>
                        @endcan

                    @elseif(request()->routeIs('utilities.*'))
                        <span class="text-[9px] font-black text-slate-800 uppercase tracking-widest mr-1"><i class="fa-solid fa-bolt text-cyan-500 mr-1"></i> Ressources</span>
                        @can('ressources.L')
                        <a href="{{ route('utilities.dashboard') }}" class="{{ $linkClass }} {{ request()->routeIs('utilities.dashboard') ? $activeClass : $inactiveClass }}">Dashboard</a>
                        @endcan
                        @can('ressources.C')
                        <a href="{{ route('utilities.water.sources') }}" class="{{ $linkClass }} {{ request()->routeIs('utilities.water.*') ? $activeClass : $inactiveClass }}">Eau</a>
                        <a href="{{ route('utilities.energy.sources') }}" class="{{ $linkClass }} {{ request()->routeIs('utilities.energy.*') ? $activeClass : $inactiveClass }}">Énergie</a>
                        <a href="{{ route('utilities.fuel.index') }}" class="{{ $linkClass }} {{ request()->routeIs('utilities.fuel.*') ? $activeClass : $inactiveClass }}">Gasoil</a>
                        @endcan

                    @elseif(request()->routeIs(['users.*', 'farms.*', 'trash.*', 'settings.*', 'admin.species.*']))
                        <span class="text-[9px] font-black text-slate-800 uppercase tracking-widest mr-1"><i class="fa-solid fa-shield-halved text-purple-500 mr-1"></i> Admin</span>
                        @can('admin.S')
                        <a href="{{ route('users.index') }}" class="{{ $linkClass }} {{ request()->routeIs('users.*') ? $activeClass : $inactiveClass }}">Accès</a>
                        <a href="{{ route('admin.species.index') }}" class="{{ $linkClass }} {{ request()->routeIs('admin.species.*') ? $activeClass : $inactiveClass }}">Espèces</a>
                        <a href="{{ route('settings.index') }}" class="{{ $linkClass }} {{ request()->routeIs('settings.*') ? $activeClass : $inactiveClass }}">Paramètres</a>
                        <a href="{{ route('farms.index') }}" class="{{ $linkClass }} {{ request()->routeIs('farms.*') ? $activeClass : $inactiveClass }}">Sites</a>
                        @endcan
                    @endif
                </div>
            </div>

            {{-- DROITE : FARM SWITCHER + USER --}}
            <div class="hidden sm:flex items-center gap-2">

                {{-- FARM SWITCHER --}}
                @if($isMultiFarm ?? false)
                <div x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false" class="relative">
                    <button class="flex items-center gap-1.5 text-[9px] font-black uppercase tracking-widest px-3 py-1.5 rounded-xl bg-violet-50 text-violet-600 hover:bg-violet-100 transition-all outline-none">
                        <i class="fa-solid fa-building text-[8px]"></i>
                        {{ ($currentFarm->code ?? 'SITE') }}
                        <i class="fa-solid fa-chevron-down text-[5px] opacity-30"></i>
                    </button>
                    <div x-show="open" x-transition class="absolute right-0 top-full mt-1 w-64 bg-white rounded-2xl shadow-2xl border border-slate-100 p-3 z-50" x-cloak>
                        <p class="text-[7px] font-black text-slate-400 uppercase tracking-widest px-2 mb-2">Changer de site</p>
                        @foreach($userFarms ?? [] as $farm)
                        <form method="POST" action="{{ route('farms.switch') }}">
                            @csrf
                            <input type="hidden" name="farm_id" value="{{ $farm->id }}">
                            <button type="submit" @class(['w-full text-left rounded-lg p-2.5 text-[8px] font-black uppercase italic tracking-widest transition-all border-none cursor-pointer flex items-center gap-2',
                                'bg-violet-50 text-violet-600' => ($currentFarmId ?? 0) == $farm->id,
                                'hover:bg-slate-50 text-slate-500' => ($currentFarmId ?? 0) != $farm->id])>
                                <span @class(['w-6 h-6 rounded-md flex items-center justify-center text-[7px] font-black text-white',
                                    'bg-violet-500' => ($currentFarmId ?? 0) == $farm->id, 'bg-slate-300' => ($currentFarmId ?? 0) != $farm->id])>{{ $farm->code ?? '?' }}</span>
                                <span class="truncate">{{ $farm->name }}</span>
                                @if(($currentFarmId ?? 0) == $farm->id)<i class="fa-solid fa-check text-emerald-500 ml-auto"></i>@endif
                            </button>
                        </form>
                        @endforeach
                    </div>
                </div>
                @elseif($currentFarm ?? null)
                <span class="text-[8px] font-black text-slate-400 uppercase tracking-widest px-2">{{ $currentFarm->name }}</span>
                @endif

                {{-- RÔLE --}}
                <div class="px-3 py-1.5 bg-slate-50 rounded-xl text-right hidden xl:block">
                    <span class="text-[7px] font-black uppercase text-slate-400 tracking-widest block leading-none">Rôle</span>
                    <span class="text-[8px] font-black uppercase italic text-blue-500">{{ Auth::user()->userRole?->display_name ?? 'Opérateur' }}</span>
                </div>

                {{-- USER DROPDOWN --}}
                <div x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false" class="relative">
                    <button class="flex items-center gap-1.5 p-1 bg-slate-100 rounded-xl hover:bg-slate-200 transition-all outline-none">
                        <div class="w-8 h-8 rounded-lg bg-slate-900 flex items-center justify-center text-white text-xs font-black shadow">
                            {{ strtoupper(substr(Auth::user()->name ?? 'U', 0, 1)) }}
                        </div>
                        <i class="fa-solid fa-chevron-down text-[6px] text-slate-400 mr-1.5"></i>
                    </button>
                    <div x-show="open" x-transition class="absolute right-0 top-full mt-1 w-52 bg-white rounded-2xl shadow-2xl border border-slate-100 p-2.5 z-50" x-cloak>
                        <a href="{{ route('notifications.preferences') }}" class="block rounded-lg p-2 text-[9px] font-black uppercase italic tracking-widest hover:bg-emerald-50 text-slate-500 no-underline"><i class="fa-brands fa-whatsapp text-emerald-500 w-4 text-center mr-1"></i> Notifications</a>
                        <a href="{{ route('tasks.index') }}" class="block rounded-lg p-2 text-[9px] font-black uppercase italic tracking-widest hover:bg-blue-50 text-slate-500 no-underline"><i class="fa-solid fa-list-check text-blue-500 w-4 text-center mr-1"></i> Planning Tâches</a>
                        <a href="{{ route('profile.edit') }}" class="block rounded-lg p-2 text-[9px] font-black uppercase italic tracking-widest hover:bg-blue-50 text-slate-500 no-underline"><i class="fa-solid fa-user-gear text-blue-500 w-4 text-center mr-1"></i> Profil</a>
                        <a href="{{ route('employees.index') }}" class="block rounded-lg p-2 text-[9px] font-black uppercase italic tracking-widest hover:bg-slate-50 text-slate-500 no-underline"><i class="fa-solid fa-users text-slate-400 w-4 text-center mr-1"></i> Employés</a>
                        <a href="{{ route('providers.index') }}" class="block rounded-lg p-2 text-[9px] font-black uppercase italic tracking-widest hover:bg-slate-50 text-slate-500 no-underline"><i class="fa-solid fa-truck-field text-slate-400 w-4 text-center mr-1"></i> Fournisseurs</a>
                        @can('admin.S')
                        <div class="border-t border-slate-100 my-1.5"></div>
                        <a href="{{ route('farms.index') }}" class="block rounded-lg p-2 text-[9px] font-black uppercase italic tracking-widest hover:bg-violet-50 text-slate-500 no-underline"><i class="fa-solid fa-city text-violet-500 w-4 text-center mr-1"></i> Multi-Sites</a>
                        <a href="{{ route('admin.species.index') }}" class="block rounded-lg p-2 text-[9px] font-black uppercase italic tracking-widest hover:bg-teal-50 text-slate-500 no-underline"><i class="fa-solid fa-paw text-teal-500 w-4 text-center mr-1"></i> Espèces</a>
                        <a href="{{ route('settings.index') }}" class="block rounded-lg p-2 text-[9px] font-black uppercase italic tracking-widest hover:bg-amber-50 text-slate-500 no-underline"><i class="fa-solid fa-sliders text-amber-500 w-4 text-center mr-1"></i> Paramètres</a>
                        <a href="{{ route('users.index') }}" class="block rounded-lg p-2 text-[9px] font-black uppercase italic tracking-widest hover:bg-purple-50 text-slate-500 no-underline"><i class="fa-solid fa-shield-halved text-purple-500 w-4 text-center mr-1"></i> Administration</a>
                        <a href="{{ route('trash.index') }}" class="block rounded-lg p-2 text-[9px] font-black uppercase italic tracking-widest hover:bg-red-50 text-slate-500 no-underline"><i class="fa-solid fa-trash text-red-400 w-4 text-center mr-1"></i> Corbeille</a>
                        @endcan
                        <div class="border-t border-slate-100 my-1.5"></div>
                        <form method="POST" action="{{ route('logout') }}">@csrf
                            <button type="submit" class="w-full text-left rounded-lg p-2 text-[9px] font-black uppercase italic tracking-widest hover:bg-red-50 text-red-500 border-none bg-transparent cursor-pointer"><i class="fa-solid fa-right-from-bracket w-4 text-center mr-1"></i> Déconnexion</button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- HAMBURGER --}}
            <button @click="mobileOpen = !mobileOpen" class="sm:hidden p-2 rounded-lg text-slate-400 hover:bg-slate-100 outline-none border-none bg-transparent cursor-pointer">
                <i class="fa-solid text-lg" :class="mobileOpen ? 'fa-xmark' : 'fa-bars'"></i>
            </button>
        </div>
    </div>

    {{-- MOBILE --}}
    <div x-show="mobileOpen" x-transition class="sm:hidden bg-white border-t border-slate-100 shadow-2xl max-h-[80vh] overflow-y-auto" x-cloak>
        <div class="px-4 py-3 space-y-0.5">
            <p class="text-[7px] font-black text-blue-500 uppercase tracking-widest px-3 pt-2">Élevage</p>
            <a href="{{ route('buildings.index') }}" class="block px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-slate-600 hover:bg-blue-50 no-underline">Bâtiments</a>
            <a href="{{ route('batches.index') }}" class="block px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-slate-600 hover:bg-blue-50 no-underline">Lots</a>
            <a href="{{ route('egg-productions.index') }}" class="block px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-slate-600 hover:bg-blue-50 no-underline">Œufs</a>
            <a href="{{ route('incubations.index') }}" class="block px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-slate-600 hover:bg-blue-50 no-underline">Couvoir</a>
            <a href="{{ route('planning.index') }}" class="block px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-slate-600 hover:bg-indigo-50 no-underline">Planning</a>
            <p class="text-[7px] font-black text-lime-600 uppercase tracking-widest px-3 pt-2">Provenderie</p>
            <a href="{{ route('provenderie.dashboard') }}" class="block px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-slate-600 hover:bg-lime-50 no-underline">Dashboard</a>
            <a href="{{ route('formulas.index') }}" class="block px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-slate-600 hover:bg-lime-50 no-underline">Formules</a>
            <p class="text-[7px] font-black text-rose-500 uppercase tracking-widest px-3 pt-2">Abattoir</p>
            <a href="{{ route('slaughter.dashboard') }}" class="block px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-slate-600 hover:bg-rose-50 no-underline">Dashboard</a>
            <a href="{{ route('slaughter.finished') }}" class="block px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-slate-600 hover:bg-rose-50 no-underline">Produits Finis</a>
            <p class="text-[7px] font-black text-teal-500 uppercase tracking-widest px-3 pt-2">Commerce</p>
            <a href="{{ route('sales.index') }}" class="block px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-slate-600 hover:bg-teal-50 no-underline">Ventes</a>
            <a href="{{ route('stocks.index') }}" class="block px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-slate-600 hover:bg-teal-50 no-underline">Stocks</a>
            <p class="text-[7px] font-black text-cyan-500 uppercase tracking-widest px-3 pt-2">Ressources</p>
            <a href="{{ route('utilities.dashboard') }}" class="block px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-slate-600 hover:bg-cyan-50 no-underline">Eau & Énergie</a>
            <div class="border-t border-slate-100 my-2"></div>
            <a href="{{ route('profile.edit') }}" class="block px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-slate-600 hover:bg-slate-50 no-underline"><i class="fa-solid fa-user-gear mr-1 text-slate-400"></i> Profil</a>
            <form method="POST" action="{{ route('logout') }}">@csrf
                <button type="submit" class="w-full text-left px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-red-500 hover:bg-red-50 border-none bg-transparent cursor-pointer"><i class="fa-solid fa-right-from-bracket mr-1"></i> Déconnexion</button>
            </form>
        </div>
    </div>
</nav>
