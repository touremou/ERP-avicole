{{-- Navigation AviSmart v5 — 12 Modules complets --}}
<nav x-data="{ open: false }" class="sticky top-0 z-50 bg-white/90 backdrop-blur-md border-b border-slate-100 italic">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-20">
            <div class="flex">
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" class="hover:scale-105 transition-transform duration-300">
                        <x-application-logo class="block h-10 w-auto fill-current text-blue-600" />
                    </a>
                </div>
                <div class="hidden space-x-5 sm:-my-px sm:ms-10 sm:flex items-center">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" class="font-black uppercase text-[10px] tracking-widest italic flex items-center gap-2">
                        <i class="fa-solid fa-grid-2 text-blue-500/50"></i> {{ __('Dashboard') }}
                    </x-nav-link>

                    @if(config('app.database_down') || (Auth::check() && Gate::allows('L')))

                        {{-- PARC --}}
                        <x-dropdown align="left" width="56">
                            <x-slot name="trigger">
                                <button @class(['group flex items-center text-[10px] font-black uppercase tracking-widest transition-all duration-300 outline-none', 'text-blue-600' => request()->routeIs('buildings.*'), 'text-slate-500 hover:text-blue-600' => !request()->routeIs('buildings.*')])>
                                    <div @class(['w-6 h-6 rounded-lg flex items-center justify-center mr-2 transition-all shadow-sm', 'bg-blue-600 text-white' => request()->routeIs('buildings.*'), 'bg-slate-50 text-slate-400 group-hover:bg-blue-600 group-hover:text-white' => !request()->routeIs('buildings.*')])><i class="fa-solid fa-warehouse text-[10px]"></i></div>
                                    Parc <i class="fa-solid fa-chevron-down ms-2 text-[7px] opacity-30 group-hover:rotate-180 transition-transform duration-300"></i>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="p-3 space-y-1.5 bg-white rounded-2xl shadow-xl border border-slate-100">
                                    <x-dropdown-link :href="route('buildings.index')" class="rounded-xl p-3 {{ request()->routeIs('buildings.*') ? 'bg-blue-50 text-blue-600' : 'hover:bg-blue-50 text-slate-500 hover:text-blue-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-industry text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Gestion Bâtiments</span>
                                    </x-dropdown-link>
                                </div>
                            </x-slot>
                        </x-dropdown>

                        {{-- TECHNIQUE --}}
                        <x-dropdown align="left" width="56">
                            <x-slot name="trigger">
                                <button @class(['group flex items-center text-[10px] font-black uppercase tracking-widest transition-all duration-300 outline-none', 'text-blue-600' => request()->routeIs(['batches.*', 'health.*', 'reports.*']), 'text-slate-500 hover:text-blue-600' => !request()->routeIs(['batches.*', 'health.*', 'reports.*'])])>
                                    <div @class(['w-6 h-6 rounded-lg flex items-center justify-center mr-2 transition-all shadow-sm', 'bg-blue-600 text-white' => request()->routeIs(['batches.*', 'health.*', 'reports.*']), 'bg-slate-50 text-slate-400 group-hover:bg-blue-600 group-hover:text-white' => !request()->routeIs(['batches.*', 'health.*', 'reports.*'])])><i class="fa-solid fa-microscope text-[10px]"></i></div>
                                    Technique <i class="fa-solid fa-chevron-down ms-2 text-[7px] opacity-30 group-hover:rotate-180 transition-transform duration-300"></i>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="p-3 space-y-1.5 bg-white rounded-2xl shadow-xl border border-slate-100">
                                    <x-dropdown-link :href="route('batches.index')" class="rounded-xl p-3 {{ request()->routeIs('batches.*') ? 'bg-blue-50 text-blue-600' : 'hover:bg-blue-50 text-slate-500 hover:text-blue-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-layer-group text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Suivi des Bandes</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('health.index')" class="rounded-xl p-3 {{ request()->routeIs('health.*') ? 'bg-rose-50 text-rose-600' : 'hover:bg-rose-50 text-slate-500 hover:text-rose-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-kit-medical text-xs shrink-0 w-4 text-center text-rose-500"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Suivi Santé</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('reports.index')" class="rounded-xl p-3 {{ request()->routeIs('reports.*') ? 'bg-emerald-50 text-emerald-600' : 'hover:bg-emerald-50 text-slate-500 hover:text-emerald-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-chart-pie text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Rapports</span>
                                    </x-dropdown-link>
                                </div>
                            </x-slot>
                        </x-dropdown>

                        {{-- PRODUCTION --}}
                        <x-dropdown align="left" width="56">
                            <x-slot name="trigger">
                                <button @class(['group flex items-center text-[10px] font-black uppercase tracking-widest transition-all duration-300 outline-none', 'text-emerald-600' => request()->routeIs(['egg-productions.*', 'incubations.*']), 'text-slate-500 hover:text-emerald-600' => !request()->routeIs(['egg-productions.*', 'incubations.*'])])>
                                    <div @class(['w-6 h-6 rounded-lg flex items-center justify-center mr-2 transition-all shadow-sm', 'bg-emerald-500 text-white' => request()->routeIs(['egg-productions.*', 'incubations.*']), 'bg-slate-50 text-slate-400 group-hover:bg-emerald-500 group-hover:text-white' => !request()->routeIs(['egg-productions.*', 'incubations.*'])])><i class="fa-solid fa-industry text-[10px]"></i></div>
                                    Production <i class="fa-solid fa-chevron-down ms-2 text-[7px] opacity-30 group-hover:rotate-180 transition-transform duration-300"></i>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="p-3 space-y-1.5 bg-white rounded-2xl shadow-xl border border-slate-100">
                                    <x-dropdown-link :href="route('egg-productions.index')" class="rounded-xl p-3 {{ request()->routeIs('egg-productions.*') ? 'bg-emerald-50 text-emerald-600' : 'hover:bg-emerald-50 text-slate-500 hover:text-emerald-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-egg text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Ramassage Œufs</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('incubations.index')" class="rounded-xl p-3 {{ request()->routeIs('incubations.*') ? 'bg-indigo-50 text-indigo-600' : 'hover:bg-indigo-50 text-slate-500 hover:text-indigo-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-dna text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Repro & OAC</span>
                                    </x-dropdown-link>
                                </div>
                            </x-slot>
                        </x-dropdown>

                        {{-- PROVENDERIE --}}
                        <x-dropdown align="left" width="56">
                            <x-slot name="trigger">
                                <button @class(['group flex items-center text-[10px] font-black uppercase tracking-widest transition-all duration-300 outline-none', 'text-amber-600' => request()->routeIs(['provenderie.*', 'raw-materials.*', 'formulas.*', 'production.*', 'machines.*']), 'text-slate-500 hover:text-amber-600' => !request()->routeIs(['provenderie.*', 'raw-materials.*', 'formulas.*', 'production.*', 'machines.*'])])>
                                    <div @class(['w-6 h-6 rounded-lg flex items-center justify-center mr-2 transition-all shadow-sm', 'bg-amber-500 text-white' => request()->routeIs(['provenderie.*', 'raw-materials.*', 'formulas.*', 'production.*', 'machines.*']), 'bg-slate-50 text-slate-400 group-hover:bg-amber-500 group-hover:text-white' => !request()->routeIs(['provenderie.*', 'raw-materials.*', 'formulas.*', 'production.*', 'machines.*'])])><i class="fa-solid fa-wheat-awn text-[10px]"></i></div>
                                    Provenderie <i class="fa-solid fa-chevron-down ms-2 text-[7px] opacity-30 group-hover:rotate-180 transition-transform duration-300"></i>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="p-3 space-y-1.5 bg-white rounded-2xl shadow-xl border border-slate-100">
                                    <x-dropdown-link :href="route('provenderie.dashboard')" class="rounded-xl p-3 {{ request()->routeIs('provenderie.dashboard') ? 'bg-slate-900 text-white' : 'bg-slate-50 text-slate-700 hover:bg-slate-900 hover:text-white' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-sliders text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Pilotage Flux</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('raw-materials.index')" class="rounded-xl p-3 {{ request()->routeIs('raw-materials.*') ? 'bg-amber-50 text-amber-600' : 'hover:bg-amber-50 text-slate-500 hover:text-amber-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-seedling text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Matières Premières</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('formulas.index')" class="rounded-xl p-3 {{ request()->routeIs('formulas.*') ? 'bg-blue-50 text-blue-600' : 'hover:bg-blue-50 text-slate-500 hover:text-blue-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-flask text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Formulations</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('production.index')" class="rounded-xl p-3 {{ request()->routeIs('production.*') ? 'bg-emerald-50 text-emerald-600' : 'hover:bg-emerald-50 text-slate-500 hover:text-emerald-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-industry text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Lancer Production</span>
                                    </x-dropdown-link>
                                </div>
                            </x-slot>
                        </x-dropdown>

                        {{-- VENTES --}}
                        <x-dropdown align="left" width="56">
                            <x-slot name="trigger">
                                <button @class(['group flex items-center text-[10px] font-black uppercase tracking-widest transition-all duration-300 outline-none', 'text-teal-600' => request()->routeIs(['sales.*', 'clients.*', 'payments.*']), 'text-slate-500 hover:text-teal-600' => !request()->routeIs(['sales.*', 'clients.*', 'payments.*'])])>
                                    <div @class(['w-6 h-6 rounded-lg flex items-center justify-center mr-2 transition-all shadow-sm', 'bg-teal-500 text-white' => request()->routeIs(['sales.*', 'clients.*', 'payments.*']), 'bg-slate-50 text-slate-400 group-hover:bg-teal-500 group-hover:text-white' => !request()->routeIs(['sales.*', 'clients.*', 'payments.*'])])><i class="fa-solid fa-cash-register text-[10px]"></i></div>
                                    Ventes <i class="fa-solid fa-chevron-down ms-2 text-[7px] opacity-30 group-hover:rotate-180 transition-transform duration-300"></i>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="p-3 space-y-1.5 bg-white rounded-2xl shadow-xl border border-slate-100">
                                    <x-dropdown-link :href="route('sales.index')" class="rounded-xl p-3 {{ request()->routeIs('sales.*') ? 'bg-teal-50 text-teal-600' : 'hover:bg-teal-50 text-slate-500 hover:text-teal-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-file-invoice text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Ventes & BL</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('clients.index')" class="rounded-xl p-3 {{ request()->routeIs('clients.*') ? 'bg-blue-50 text-blue-600' : 'hover:bg-blue-50 text-slate-500 hover:text-blue-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-users text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Clients</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('payments.index')" class="rounded-xl p-3 {{ request()->routeIs('payments.*') ? 'bg-emerald-50 text-emerald-600' : 'hover:bg-emerald-50 text-slate-500 hover:text-emerald-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-money-bill-wave text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Encaissements</span>
                                    </x-dropdown-link>
                                </div>
                            </x-slot>
                        </x-dropdown>

                        {{-- EAU & ÉNERGIE --}}
                        <x-dropdown align="left" width="56">
                            <x-slot name="trigger">
                                <button @class(['group flex items-center text-[10px] font-black uppercase tracking-widest transition-all duration-300 outline-none', 'text-cyan-600' => request()->routeIs('utilities.*'), 'text-slate-500 hover:text-cyan-600' => !request()->routeIs('utilities.*')])>
                                    <div @class(['w-6 h-6 rounded-lg flex items-center justify-center mr-2 transition-all shadow-sm', 'bg-cyan-500 text-white' => request()->routeIs('utilities.*'), 'bg-slate-50 text-slate-400 group-hover:bg-cyan-500 group-hover:text-white' => !request()->routeIs('utilities.*')])><i class="fa-solid fa-droplet text-[10px]"></i></div>
                                    Eau/Énergie <i class="fa-solid fa-chevron-down ms-2 text-[7px] opacity-30 group-hover:rotate-180 transition-transform duration-300"></i>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="p-3 space-y-1.5 bg-white rounded-2xl shadow-xl border border-slate-100">
                                    <x-dropdown-link :href="route('utilities.dashboard')" class="rounded-xl p-3 {{ request()->routeIs('utilities.dashboard') ? 'bg-cyan-50 text-cyan-600' : 'hover:bg-cyan-50 text-slate-500 hover:text-cyan-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-gauge-high text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Tableau de Bord</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('utilities.water.sources')" class="rounded-xl p-3 {{ request()->routeIs('utilities.water.*') ? 'bg-blue-50 text-blue-600' : 'hover:bg-blue-50 text-slate-500 hover:text-blue-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-faucet-drip text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Sources d'Eau</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('utilities.energy.sources')" class="rounded-xl p-3 {{ request()->routeIs('utilities.energy.*') ? 'bg-amber-50 text-amber-600' : 'hover:bg-amber-50 text-slate-500 hover:text-amber-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-bolt text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Sources d'Énergie</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('utilities.fuel.index')" class="rounded-xl p-3 {{ request()->routeIs('utilities.fuel.*') ? 'bg-orange-50 text-orange-600' : 'hover:bg-orange-50 text-slate-500 hover:text-orange-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-gas-pump text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Achats Gasoil</span>
                                    </x-dropdown-link>
                                </div>
                            </x-slot>
                        </x-dropdown>

                        {{-- PLANIFICATION --}}
                        <x-dropdown align="left" width="56">
                            <x-slot name="trigger">
                                <button @class(['group flex items-center text-[10px] font-black uppercase tracking-widest transition-all duration-300 outline-none', 'text-indigo-600' => request()->routeIs('planning.*'), 'text-slate-500 hover:text-indigo-600' => !request()->routeIs('planning.*')])>
                                    <div @class(['w-6 h-6 rounded-lg flex items-center justify-center mr-2 transition-all shadow-sm', 'bg-indigo-500 text-white' => request()->routeIs('planning.*'), 'bg-slate-50 text-slate-400 group-hover:bg-indigo-500 group-hover:text-white' => !request()->routeIs('planning.*')])><i class="fa-solid fa-calendar-days text-[10px]"></i></div>
                                    Planning <i class="fa-solid fa-chevron-down ms-2 text-[7px] opacity-30 group-hover:rotate-180 transition-transform duration-300"></i>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="p-3 space-y-1.5 bg-white rounded-2xl shadow-xl border border-slate-100">
                                    <x-dropdown-link :href="route('planning.index')" class="rounded-xl p-3 {{ request()->routeIs('planning.index') ? 'bg-indigo-50 text-indigo-600' : 'hover:bg-indigo-50 text-slate-500 hover:text-indigo-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-calendar-days text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Calendrier Bandes</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('planning.create')" class="rounded-xl p-3 {{ request()->routeIs('planning.create') ? 'bg-indigo-50 text-indigo-600' : 'hover:bg-indigo-50 text-slate-500 hover:text-indigo-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-plus text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Planifier une Bande</span>
                                    </x-dropdown-link>
                                </div>
                            </x-slot>
                        </x-dropdown>

                        {{-- ABATTOIR --}}
                        <x-dropdown align="left" width="56">
                            <x-slot name="trigger">
                                <button @class(['group flex items-center text-[10px] font-black uppercase tracking-widest transition-all duration-300 outline-none', 'text-rose-600' => request()->routeIs('slaughter.*'), 'text-slate-500 hover:text-rose-600' => !request()->routeIs('slaughter.*')])>
                                    <div @class(['w-6 h-6 rounded-lg flex items-center justify-center mr-2 transition-all shadow-sm', 'bg-rose-500 text-white' => request()->routeIs('slaughter.*'), 'bg-slate-50 text-slate-400 group-hover:bg-rose-500 group-hover:text-white' => !request()->routeIs('slaughter.*')])><i class="fa-solid fa-industry text-[10px]"></i></div>
                                    Abattoir <i class="fa-solid fa-chevron-down ms-2 text-[7px] opacity-30 group-hover:rotate-180 transition-transform duration-300"></i>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="p-3 space-y-1.5 bg-white rounded-2xl shadow-xl border border-slate-100">
                                    <x-dropdown-link :href="route('slaughter.dashboard')" class="rounded-xl p-3 {{ request()->routeIs('slaughter.dashboard') ? 'bg-rose-50 text-rose-600' : 'hover:bg-rose-50 text-slate-500 hover:text-rose-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-gauge-high text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Dashboard</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('slaughter.orders.create')" class="rounded-xl p-3 {{ request()->routeIs('slaughter.orders.*') ? 'bg-rose-50 text-rose-600' : 'hover:bg-rose-50 text-slate-500 hover:text-rose-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-clipboard-list text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Nouvel Ordre</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('slaughter.transform.form')" class="rounded-xl p-3 {{ request()->routeIs('slaughter.transform.*') ? 'bg-amber-50 text-amber-600' : 'hover:bg-amber-50 text-slate-500 hover:text-amber-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-fire text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Transformation</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('slaughter.finished')" class="rounded-xl p-3 {{ request()->routeIs('slaughter.finished') ? 'bg-emerald-50 text-emerald-600' : 'hover:bg-emerald-50 text-slate-500 hover:text-emerald-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-boxes-stacked text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Produits Finis</span>
                                    </x-dropdown-link>
                                </div>
                            </x-slot>
                        </x-dropdown>

                        {{-- LOGISTIQUE & ANTI-FRAUDE --}}
                        <x-dropdown align="left" width="56">
                            <x-slot name="trigger">
                                <button @class(['group flex items-center text-[10px] font-black uppercase tracking-widest transition-all duration-300 outline-none', 'text-orange-600' => request()->routeIs(['stocks.*', 'dispatches.*']), 'text-slate-500 hover:text-orange-600' => !request()->routeIs(['stocks.*', 'dispatches.*'])])>
                                    <div @class(['w-6 h-6 rounded-lg flex items-center justify-center mr-2 transition-all shadow-sm', 'bg-orange-500 text-white' => request()->routeIs(['stocks.*', 'dispatches.*']), 'bg-slate-50 text-slate-400 group-hover:bg-orange-500 group-hover:text-white' => !request()->routeIs(['stocks.*', 'dispatches.*'])])><i class="fa-solid fa-truck-ramp-box text-[10px]"></i></div>
                                    Logistique <i class="fa-solid fa-chevron-down ms-2 text-[7px] opacity-30 group-hover:rotate-180 transition-transform duration-300"></i>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="p-3 space-y-1.5 bg-white rounded-2xl shadow-xl border border-slate-100">
                                    <x-dropdown-link :href="route('stocks.index')" class="rounded-xl p-3 {{ request()->routeIs('stocks.*') ? 'bg-orange-50 text-orange-600' : 'hover:bg-orange-50 text-slate-500 hover:text-orange-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-boxes-stacked text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Stock Global</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('dispatches.index')" class="rounded-xl p-3 {{ request()->routeIs('dispatches.index') || request()->routeIs('dispatches.show') || request()->routeIs('dispatches.create') ? 'bg-blue-50 text-blue-600' : 'hover:bg-blue-50 text-slate-500 hover:text-blue-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-truck-fast text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Expéditions</span>
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('dispatches.discrepancies')" class="rounded-xl p-3 {{ request()->routeIs('dispatches.discrepancies') ? 'bg-red-50 text-red-600' : 'hover:bg-red-50 text-slate-500 hover:text-red-600' }} transition-all flex items-center gap-3">
                                        <i class="fa-solid fa-triangle-exclamation text-xs shrink-0 w-4 text-center opacity-70"></i>
                                        <span class="text-[9px] font-black uppercase italic tracking-widest">Écarts & Litiges</span>
                                        @php $openDiscrepancies = \App\Models\DiscrepancyReport::where('resolution', 'en_cours')->count(); @endphp
                                        @if($openDiscrepancies > 0)
                                            <span class="ml-auto text-[8px] font-black bg-red-500 text-white px-2 py-0.5 rounded-full animate-pulse">{{ $openDiscrepancies }}</span>
                                        @endif
                                    </x-dropdown-link>
                                </div>
                            </x-slot>
                        </x-dropdown>

                        {{-- ANNUAIRE --}}
                        @if(config('app.database_down') || Gate::allows('S'))
                            <x-dropdown align="left" width="56">
                                <x-slot name="trigger">
                                    <button @class(['group flex items-center text-[10px] font-black uppercase tracking-widest transition-all duration-300 outline-none', 'text-purple-600' => request()->routeIs(['providers.*', 'employees.*', 'users.*']), 'text-slate-500 hover:text-purple-600' => !request()->routeIs(['providers.*', 'employees.*', 'users.*'])])>
                                        <div @class(['w-6 h-6 rounded-lg flex items-center justify-center mr-2 transition-all shadow-sm', 'bg-purple-600 text-white' => request()->routeIs(['providers.*', 'employees.*', 'users.*']), 'bg-slate-50 text-slate-400 group-hover:bg-purple-600 group-hover:text-white' => !request()->routeIs(['providers.*', 'employees.*', 'users.*'])])><i class="fa-solid fa-users-gear text-[10px]"></i></div>
                                        Annuaire <i class="fa-solid fa-chevron-down ms-2 text-[7px] opacity-30 group-hover:rotate-180 transition-transform duration-300"></i>
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <div class="p-3 space-y-1.5 bg-white rounded-2xl shadow-xl border border-slate-100">
                                        <x-dropdown-link :href="route('providers.index')" class="rounded-xl p-3 {{ request()->routeIs('providers.*') ? 'bg-purple-50 text-purple-600' : 'hover:bg-purple-50 text-slate-500 hover:text-purple-600' }} transition-all flex items-center gap-3">
                                            <i class="fa-solid fa-handshake text-xs shrink-0 w-4 text-center opacity-70"></i>
                                            <span class="text-[9px] font-black uppercase italic tracking-widest">Fournisseurs</span>
                                        </x-dropdown-link>
                                        <x-dropdown-link :href="route('employees.index')" class="rounded-xl p-3 {{ request()->routeIs('employees.*') ? 'bg-orange-50 text-orange-600' : 'hover:bg-orange-50 text-slate-500 hover:text-orange-600' }} transition-all flex items-center gap-3">
                                            <i class="fa-solid fa-id-card text-xs shrink-0 w-4 text-center opacity-70"></i>
                                            <span class="text-[9px] font-black uppercase italic tracking-widest">Liste Employés</span>
                                        </x-dropdown-link>
                                        <x-dropdown-link :href="route('users.index')" class="rounded-xl p-3 {{ request()->routeIs('users.*') ? 'bg-purple-50 text-purple-600' : 'hover:bg-purple-50 text-slate-500 hover:text-purple-600' }} transition-all flex items-center gap-3">
                                            <i class="fa-solid fa-user-lock text-xs shrink-0 w-4 text-center opacity-70"></i>
                                            <span class="text-[9px] font-black uppercase italic tracking-widest">Accès Utilisateurs</span>
                                        </x-dropdown-link>
                                    </div>
                                </x-slot>
                            </x-dropdown>
                        @endif
                    @endif
                </div>
            </div>

            {{-- SECTION UTILISATEUR --}}
            <div class="hidden sm:flex sm:items-center sm:ms-6 gap-4">
                <div class="px-4 py-2 bg-slate-50 rounded-2xl border border-slate-100 text-right">
                    <span class="block text-[8px] font-black uppercase text-slate-400 leading-none tracking-widest italic">Position</span>
                    <span @class(['text-[9px] font-black uppercase italic', 'text-purple-600' => (Auth::user()->userRole?->name ?? '') === 'admin', 'text-blue-500' => (Auth::user()->userRole?->name ?? '') !== 'admin'])>
                        {{ Auth::user()->userRole?->display_name ?? 'Opérateur' }}
                    </span>
                </div>
                <x-dropdown align="right" width="64">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center p-1 bg-slate-100 rounded-2xl hover:bg-slate-200 transition-colors duration-300 outline-none">
                            <div class="w-8 h-8 rounded-xl bg-white shadow-sm flex items-center justify-center text-blue-600 font-black text-xs uppercase italic">{{ substr(Auth::user()->name ?? 'U', 0, 1) }}</div>
                            <i class="fa-solid fa-chevron-down ms-2 text-[8px] text-slate-400 mr-2"></i>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <div class="p-3">
                            <x-dropdown-link :href="route('notifications.preferences')" class="rounded-xl font-black text-xs italic uppercase tracking-widest hover:bg-emerald-50 flex items-center p-3 gap-3">
                                <i class="fa-brands fa-whatsapp text-emerald-500 text-xs shrink-0 w-4 text-center"></i>
                                <span>{{ __('Notifications') }}</span>
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('profile.edit')" class="rounded-xl font-black text-xs italic uppercase tracking-widest hover:bg-blue-50 flex items-center p-3 gap-3">
                                <i class="fa-solid fa-user-gear text-blue-500 text-xs shrink-0 w-4 text-center"></i>
                                <span>{{ __('Profil') }}</span>
                            </x-dropdown-link>
                            @if(!config('app.database_down') && Gate::allows('S'))
                            <form action="{{ route('batches.sync_stocks') }}" method="POST" class="px-1 py-1">
                                @csrf
                                <button type="submit" class="w-full flex items-center gap-3 px-3 py-3 bg-slate-900 text-white rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-emerald-600 transition-all shadow-lg italic group border-none cursor-pointer outline-none">
                                    <i class="fa-solid fa-arrows-rotate group-hover:rotate-180 transition-transform duration-700 text-xs shrink-0 w-4 text-center"></i> <span>Synchroniser</span>
                                </button>
                            </form>
                            @endif
                            <div class="border-t border-slate-100 my-2"></div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();" class="rounded-xl font-black text-xs italic text-red-600 uppercase tracking-widest hover:bg-red-50 flex items-center p-3 gap-3">
                                    <i class="fa-solid fa-power-off text-red-500 text-xs shrink-0 w-4 text-center"></i> <span>{{ __('Quitter') }}</span>
                                </x-dropdown-link>
                            </form>
                        </div>
                    </x-slot>
                </x-dropdown>
            </div>

            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="p-2 rounded-xl text-slate-400 hover:bg-slate-100 transition-colors outline-none">
                    <i class="fa-solid fa-bars-staggered text-xl" :class="{'hidden': open, 'inline-flex': ! open }"></i>
                    <i class="fa-solid fa-xmark text-xl" :class="{'hidden': ! open, 'inline-flex': open }"></i>
                </button>
            </div>
        </div>
    </div>
</nav>
