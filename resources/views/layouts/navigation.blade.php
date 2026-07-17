{{-- Navigation AviSmart v8 — App Drawer + Breadcrumb Contextuel + Farm Switcher --}}
{{-- L'état du menu mobile est persisté en sessionStorage : il survit à la
     navigation au lieu de se réinitialiser à chaque page (ce qui donnait
     l'impression, sur petit écran, que « la session ne persiste pas »). --}}
<nav x-data="{ mobileOpen: false }"
     x-init="mobileOpen = sessionStorage.getItem('navMobileOpen') === '1';
             $watch('mobileOpen', v => sessionStorage.setItem('navMobileOpen', v ? '1' : '0'))"
     class="sticky top-0 z-50 bg-white/95 backdrop-blur-md border-b border-slate-100 italic">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-14">
            <div class="flex items-center">

                {{-- LOGO --}}
                <a href="{{ route('dashboard') }}" class="shrink-0 flex items-center mr-4 hover:scale-105 transition-transform">
                    <x-application-logo class="block h-8 w-auto" />
                </a>

                {{-- APP DRAWER (grille modules) --}}
                <x-menu align="left" width="w-[22rem]" panel="bg-white rounded-2xl shadow-2xl border border-slate-100 p-4" class="hidden sm:block">
                    <x-slot name="trigger">
                        <span class="flex items-center px-3 py-2 bg-slate-900 text-white rounded-xl text-[9px] font-black uppercase tracking-widest transition-all hover:bg-biocrest shadow-md">
                            <i class="fa-solid fa-grip text-xs mr-1.5"></i> {{ __("Modules") }} <i class="fa-solid fa-chevron-down ms-1.5 text-[6px] opacity-40"></i>
                        </span>
                    </x-slot>
                        <p class="text-[8px] font-black uppercase tracking-widest text-slate-400 mb-3 px-1 border-b border-slate-100 pb-2">{{ __("Accès rapide") }}</p>
                        <div class="grid grid-cols-3 gap-1.5">
                            {{-- Lanceur de modules PILOTÉ PAR LA MATRICE : tout module sur
                                 lequel l'utilisateur a la lecture (can_read) apparaît ici,
                                 sans liste codée en dur. Source : getAccessibleModules()
                                 (matrice module_permissions) + Module::landingRoute(). --}}
                            @foreach(auth()->user()->getAccessibleModules()->whereNotIn('slug', \App\Models\Module::nonLauncherSlugs()) as $module)
                                @php
                                    $landing = \App\Models\Module::landingRoute($module->slug);
                                    $color   = $module->color ?: 'slate';
                                    $icon    = $module->icon ?: 'fa-cube';
                                @endphp
                                @if($landing && \Illuminate\Support\Facades\Route::has($landing))
                                    <a href="{{ route($landing) }}" class="flex flex-col items-center p-2.5 rounded-xl hover:bg-{{ $color }}-50 transition-all group no-underline">
                                        <div class="w-9 h-9 rounded-lg bg-{{ $color }}-50 text-{{ $color }}-500 flex items-center justify-center mb-1 group-hover:scale-110 transition-transform">
                                            <i class="fa-solid {{ $icon }} text-sm"></i>
                                        </div>
                                        <span class="text-[7px] font-black uppercase tracking-widest text-slate-500 text-center">{{ __($module->name) }}</span>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                </x-menu>

                {{-- BREADCRUMB CONTEXTUEL (visible dès tablette : md). Sur mobile,
                     le même contenu est rendu dans le tiroir (cf. plus bas). --}}
                <div class="hidden md:flex items-center ml-4 pl-4 border-l border-slate-200 h-8 gap-1">
                    @include('layouts.partials.contextual-nav')
                </div>
            </div>

            {{-- DROITE : FARM SWITCHER + USER --}}
            <div class="hidden sm:flex items-center gap-2">

                {{-- FARM SWITCHER --}}
                @if($isMultiFarm ?? false)
                <x-menu align="right" width="w-64" panel="bg-white rounded-2xl shadow-2xl border border-slate-100 p-3">
                    <x-slot name="trigger">
                        <span class="flex items-center gap-1.5 text-[9px] font-black uppercase tracking-widest px-3 py-1.5 rounded-xl bg-violet-50 text-violet-600 hover:bg-violet-100 transition-all">
                            <span class="text-xs leading-none" aria-hidden="true">🏡</span>
                            {{ ($currentFarm->code ?? 'SITE') }}
                            <i class="fa-solid fa-chevron-down text-[5px] opacity-30"></i>
                        </span>
                    </x-slot>
                        <p class="text-[7px] font-black text-slate-400 uppercase tracking-widest px-2 mb-2">{{ __("Changer de site") }}</p>
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
                </x-menu>
                @elseif($currentFarm ?? null)
                <span class="flex items-center gap-1.5 text-[8px] font-black text-slate-400 uppercase tracking-widest px-2">
                    <span class="text-xs leading-none" aria-hidden="true">🏡</span>{{ $currentFarm->name }}
                </span>
                @endif

                {{-- STATUT RÉSEAU (pendant du badge de sync mobile) --}}
                <div x-data="{ online: navigator.onLine }"
                     x-init="window.addEventListener('online', () => online = true); window.addEventListener('offline', () => online = false)"
                     class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-[8px] font-black uppercase tracking-widest transition-colors"
                     :class="online ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'"
                     :title="online ? @js(__('En ligne')) : @js(__('Hors-ligne'))">
                    <span class="text-sm leading-none" x-text="online ? '📡' : '📴'"></span>
                    <span class="hidden xl:inline" x-text="online ? @js(__('En ligne')) : @js(__('Hors-ligne'))"></span>
                </div>

                {{-- RÔLE --}}
                <div class="px-3 py-1.5 bg-slate-50 rounded-xl text-right hidden xl:block">
                    <span class="text-[7px] font-black uppercase text-slate-400 tracking-widest block leading-none">{{ __("Rôle") }}</span>
                    <span class="text-[8px] font-black uppercase italic text-blue-500">{{ Auth::user()->userRole?->display_name ?? Auth::user()->userRole?->label ?? __("Opérateur") }}</span>
                </div>

                {{-- USER DROPDOWN --}}
                <x-menu align="right" width="w-52" panel="bg-white rounded-2xl shadow-2xl border border-slate-100 p-2.5">
                    <x-slot name="trigger">
                        <span class="flex items-center gap-1.5 p-1 bg-slate-100 rounded-xl hover:bg-slate-200 transition-all">
                            @if(Auth::user()->avatar_url)
                                <img src="{{ Auth::user()->avatar_url }}" alt="{{ Auth::user()->name }}" class="w-8 h-8 rounded-lg object-cover shadow">
                            @else
                                <span class="w-8 h-8 rounded-lg bg-slate-900 flex items-center justify-center text-white text-xs font-black shadow">
                                    {{ strtoupper(substr(Auth::user()->name ?? 'U', 0, 1)) }}
                                </span>
                            @endif
                            <i class="fa-solid fa-chevron-down text-[6px] text-slate-400 mr-1.5"></i>
                        </span>
                    </x-slot>
                        @can('rh.L')
                        <a href="{{ route('tasks.index', ['mine' => 1]) }}" class="block rounded-lg p-2 text-[9px] font-black uppercase italic tracking-widest hover:bg-blue-50 text-slate-500 no-underline"><i class="fa-solid fa-list-check text-blue-500 w-4 text-center mr-1"></i> {{ __("Mes Tâches") }}</a>
                        @endcan
                        <a href="{{ route('profile.edit') }}" class="block rounded-lg p-2 text-[9px] font-black uppercase italic tracking-widest hover:bg-blue-50 text-slate-500 no-underline"><i class="fa-solid fa-user-gear text-blue-500 w-4 text-center mr-1"></i> {{ __("Profil") }}</a>
                        @if(\Illuminate\Support\Facades\Route::has('notifications.preferences'))
                        <a href="{{ route('notifications.preferences') }}" class="block rounded-lg p-2 text-[9px] font-black uppercase italic tracking-widest hover:bg-blue-50 text-slate-500 no-underline"><i class="fa-solid fa-bell text-blue-500 w-4 text-center mr-1"></i> {{ __("Notifications") }}</a>
                        @endif
                        <div class="border-t border-slate-100 my-1.5"></div>
                        <form method="POST" action="{{ route('logout') }}">@csrf
                            <button type="submit" class="w-full text-left rounded-lg p-2 text-[9px] font-black uppercase italic tracking-widest hover:bg-red-50 text-red-500 border-none bg-transparent cursor-pointer"><i class="fa-solid fa-right-from-bracket w-4 text-center mr-1"></i> {{ __("Déconnexion") }}</button>
                        </form>
                </x-menu>
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
            {{-- Sous-menu contextuel de la section courante : rendu identique au
                 breadcrumb desktop, pour que les sous-menus restent accessibles
                 sur petit écran (ils disparaissaient auparavant). --}}
            @unless(request()->routeIs('dashboard'))
                <div class="mb-2 pb-2 border-b border-slate-100">
                    @include('layouts.partials.contextual-nav', ['mobile' => true])
                </div>
            @endunless

            {{-- Lanceur de modules mobile, PILOTÉ PAR LA MATRICE : même source
                 que le drawer desktop (getAccessibleModules() + landingRoute()),
                 pour une couverture identique sur mobile et desktop. --}}
            @foreach(auth()->user()->getAccessibleModules()->whereNotIn('slug', \App\Models\Module::nonLauncherSlugs()) as $module)
                @php
                    $landing = \App\Models\Module::landingRoute($module->slug);
                    $color   = $module->color ?: 'slate';
                    $icon    = $module->icon ?: 'fa-cube';
                @endphp
                @if($landing && \Illuminate\Support\Facades\Route::has($landing))
                    <a href="{{ route($landing) }}" class="flex items-center px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-slate-600 hover:bg-{{ $color }}-50 no-underline">
                        <i class="fa-solid {{ $icon }} mr-2 text-{{ $color }}-500 w-4 text-center"></i> {{ __($module->name) }}
                    </a>
                @endif
            @endforeach
            <div class="border-t border-slate-100 my-2"></div>
            <a href="{{ route('profile.edit') }}" class="block px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-slate-600 hover:bg-slate-50 no-underline"><i class="fa-solid fa-user-gear mr-1 text-slate-400"></i> {{ __("Profil") }}</a>
            <form method="POST" action="{{ route('logout') }}">@csrf
                <button type="submit" class="w-full text-left px-3 py-2 rounded-lg text-[10px] font-black uppercase italic text-red-500 hover:bg-red-50 border-none bg-transparent cursor-pointer"><i class="fa-solid fa-right-from-bracket mr-1"></i> {{ __("Déconnexion") }}</button>
            </form>
        </div>
    </div>
</nav>
