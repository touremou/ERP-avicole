{{-- Sous-menu contextuel partagé : rendu à la fois dans le breadcrumb desktop
     (barre horizontale, md+) ET dans le tiroir mobile (liste verticale, < sm).
     Évite la duplication des ~12 branches de navigation et garantit que les
     sous-menus restent accessibles sur petit écran (bug : ils disparaissaient
     car ils n'existaient que dans le breadcrumb hidden md:flex). --}}
@php
    $mobile = $mobile ?? false;
    if ($mobile) {
        $sectionClass  = 'text-[10px] font-black text-slate-700 uppercase tracking-widest px-3 py-2 flex items-center gap-1 border-b border-slate-50 mb-1';
        $linkClass     = 'block text-[10px] font-black uppercase tracking-widest px-3 py-2 rounded-lg transition-all no-underline';
        $activeClass   = 'bg-slate-100 text-slate-800';
        $inactiveClass = 'text-slate-500 hover:text-slate-800 hover:bg-slate-50';
    } else {
        $sectionClass  = 'text-[9px] font-black text-slate-800 uppercase tracking-widest mr-1';
        $linkClass     = 'text-[9px] font-black uppercase tracking-widest px-2.5 py-1.5 rounded-lg transition-all no-underline';
        $activeClass   = 'bg-slate-100 text-slate-800';
        $inactiveClass = 'text-slate-400 hover:text-slate-700 hover:bg-slate-50';
    }
@endphp

@if(request()->routeIs('dashboard'))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-house mr-1 text-blue-500"></i> {{ __("Accueil") }}</span>

@elseif(request()->routeIs(['employees.*', 'payroll.*', 'tasks.*', 'providers.*', 'attendance.*']))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-users text-slate-500 mr-1"></i> {{ __("Annuaire") }}</span>
    @can('annuaire.L')
    <a href="{{ route('employees.index') }}" class="{{ $linkClass }} {{ request()->routeIs('employees.*') ? $activeClass : $inactiveClass }}">{{ __("Employés") }}</a>
    <a href="{{ route('attendance.index') }}" class="{{ $linkClass }} {{ request()->routeIs('attendance.*') ? $activeClass : $inactiveClass }}">{{ __("Présence") }}</a>
    <a href="{{ route('providers.index') }}" class="{{ $linkClass }} {{ request()->routeIs('providers.*') ? $activeClass : $inactiveClass }}">{{ __("Fournisseurs") }}</a>
    <a href="{{ route('tasks.index') }}" class="{{ $linkClass }} {{ request()->routeIs('tasks.index') ? $activeClass : $inactiveClass }}">{{ __("Tâches") }}</a>
    <a href="{{ route('payroll.index') }}" class="{{ $linkClass }} {{ request()->routeIs('payroll.index') ? $activeClass : $inactiveClass }}">{{ __("Paie") }}</a>
    <a href="{{ route('payroll.leaves') }}" class="{{ $linkClass }} {{ request()->routeIs('payroll.leaves') ? $activeClass : $inactiveClass }}">{{ __("Congés") }}</a>
    @endcan

@elseif(request()->routeIs(['buildings.*','batches.*', 'daily-checks.*', 'health.*', 'protocols.*', 'reports.*', 'campaigns.*']))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-dove text-blue-500 mr-1"></i> {{ __("Élevage") }}</span>
    @can('elevage.L')
    <a href="{{ route('buildings.index') }}" class="{{ $linkClass }} {{ request()->routeIs('buildings.*') ? $activeClass : $inactiveClass }}">{{ __("Bâtiments") }}</a>
    <a href="{{ route('batches.index') }}" class="{{ $linkClass }} {{ request()->routeIs('batches.*') ? $activeClass : $inactiveClass }}">{{ __("Lots") }}</a>
    <a href="{{ route('campaigns.index') }}" class="{{ $linkClass }} {{ request()->routeIs('campaigns.*') ? $activeClass : $inactiveClass }}">{{ __("Campagnes") }}</a>
    <a href="{{ route('health.index') }}" class="{{ $linkClass }} {{ request()->routeIs('health.*') ? $activeClass : $inactiveClass }}">{{ __("Santé") }}</a>
    <a href="{{ route('reports.index') }}" class="{{ $linkClass }} {{ request()->routeIs('reports.*') ? $activeClass : $inactiveClass }}">{{ __("Rapports") }}</a>
    @endcan
    @can('elevage.M')
    <a href="{{ route('protocols.index') }}" class="{{ $linkClass }} {{ request()->routeIs('protocols.*') ? $activeClass : $inactiveClass }}">{{ __("Protocoles") }}</a>
    @endcan

@elseif(request()->routeIs(['egg-productions.*', 'egg-movements.*', 'milk-productions.*', 'incubations.*', 'chick-dispatches.*']))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-egg text-amber-500 mr-1"></i> {{ __("Production") }}</span>
    @can('production.L')
    <a href="{{ route('egg-productions.index') }}" class="{{ $linkClass }} {{ request()->routeIs('egg-productions.*') ? $activeClass : $inactiveClass }}">{{ __("Œufs") }}</a>
    <a href="{{ route('milk-productions.index') }}" class="{{ $linkClass }} {{ request()->routeIs('milk-productions.*') ? $activeClass : $inactiveClass }}">{{ __("Lait") }}</a>
    <a href="{{ route('incubations.index') }}" class="{{ $linkClass }} {{ request()->routeIs('incubations.*') ? $activeClass : $inactiveClass }}">{{ __("Couvoir") }}</a>
    @endcan

@elseif(request()->routeIs(['cultures.*', 'plots.*', 'crop-cycles.*', 'crop-transformations.*', 'crop-catalogue.*', 'crop-campaigns.*', 'crop-recipes.*', 'crop-reports.*', 'crop-calendar-events.*', 'crop-protocols.*', 'weather.*']))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-seedling text-green-600 mr-1"></i> {{ __("Production Végétale") }}</span>
    @can('cultures.L')
    {{-- Barre contextuelle = entités opérationnelles. Le pilotage (vue d'ensemble,
         calendrier, météo) et le référentiel (catalogue, protocoles, recettes)
         vivent dans la sous-navigation « hub » du tableau de bord. --}}
    <a href="{{ route('cultures.dashboard') }}" class="{{ $linkClass }} {{ request()->routeIs('cultures.dashboard') || request()->routeIs('crop-catalogue.*') || request()->routeIs('crop-protocols.*') || request()->routeIs('crop-recipes.*') || request()->routeIs('crop-calendar-events.*') ? $activeClass : $inactiveClass }}">{{ __("Tableau de bord") }}</a>
    <a href="{{ route('plots.index') }}" class="{{ $linkClass }} {{ request()->routeIs('plots.*') ? $activeClass : $inactiveClass }}">{{ __("Parcelles") }}</a>
    <a href="{{ route('crop-cycles.index') }}" class="{{ $linkClass }} {{ request()->routeIs('crop-cycles.*') ? $activeClass : $inactiveClass }}">{{ __("Cycles") }}</a>
    <a href="{{ route('crop-campaigns.index') }}" class="{{ $linkClass }} {{ request()->routeIs('crop-campaigns.*') ? $activeClass : $inactiveClass }}">{{ __("Campagnes") }}</a>
    <a href="{{ route('crop-transformations.index') }}" class="{{ $linkClass }} {{ request()->routeIs('crop-transformations.*') ? $activeClass : $inactiveClass }}">{{ __("Transformation") }}</a>
    <a href="{{ route('crop-reports.index') }}" class="{{ $linkClass }} {{ request()->routeIs('crop-reports.*') ? $activeClass : $inactiveClass }}">{{ __("Rapports") }}</a>
    @endcan

@elseif(request()->routeIs(['provenderie.*', 'raw-materials.*', 'formulas.*', 'production.*', 'machines.*']))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-wheat-awn text-lime-600 mr-1"></i> {{ __("Provenderie") }}</span>
    @can('provenderie.L')
    <a href="{{ route('provenderie.dashboard') }}" class="{{ $linkClass }} {{ request()->routeIs('provenderie.dashboard') ? $activeClass : $inactiveClass }}">{{ __("Pilotage") }}</a>
    @endcan
    @can('provenderie.C')
    <a href="{{ route('raw-materials.index') }}" class="{{ $linkClass }} {{ request()->routeIs('raw-materials.*') ? $activeClass : $inactiveClass }}">{{ __("MP") }}</a>
    <a href="{{ route('formulas.index') }}" class="{{ $linkClass }} {{ request()->routeIs('formulas.*') ? $activeClass : $inactiveClass }}">{{ __("Formules") }}</a>
    <a href="{{ route('production.index') }}" class="{{ $linkClass }} {{ request()->routeIs('production.*') ? $activeClass : $inactiveClass }}">{{ __("Production") }}</a>
    @endcan

@elseif(request()->routeIs('planning.*'))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-calendar-days text-indigo-500 mr-1"></i> {{ __("Planning") }}</span>
    @can('planning.L')
    <a href="{{ route('planning.index') }}" class="{{ $linkClass }} {{ request()->routeIs('planning.index') ? $activeClass : $inactiveClass }}">{{ __("Calendrier") }}</a>
    @endcan
    @can('planning.C')
    <a href="{{ route('planning.create') }}" class="{{ $linkClass }} {{ request()->routeIs('planning.create') ? $activeClass : $inactiveClass }}">{{ __("Planifier") }}</a>
    @endcan

@elseif(request()->routeIs('slaughter.*'))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-drumstick-bite text-rose-500 mr-1"></i> {{ __("Abattoir") }}</span>
    @can('abattoir.L')
    <a href="{{ route('slaughter.dashboard') }}" class="{{ $linkClass }} {{ request()->routeIs('slaughter.dashboard') ? $activeClass : $inactiveClass }}">{{ __("Dashboard") }}</a>
    @endcan
    @can('abattoir.C')
    <a href="{{ route('slaughter.orders.create') }}" class="{{ $linkClass }} {{ request()->routeIs('slaughter.orders.*') ? $activeClass : $inactiveClass }}">{{ __("Ordre") }}</a>
    <a href="{{ route('slaughter.transform.form') }}" class="{{ $linkClass }} {{ request()->routeIs('slaughter.transform.*') ? $activeClass : $inactiveClass }}">{{ __("Transfo") }}</a>
    <a href="{{ route('slaughter.finished') }}" class="{{ $linkClass }} {{ request()->routeIs('slaughter.finished*') ? $activeClass : $inactiveClass }}">{{ __("Produits Finis") }}</a>
    @endcan

@elseif(request()->routeIs(['sales.*', 'clients.*', 'payments.*', 'pos.*']))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-cash-register text-teal-500 mr-1"></i> {{ __("Commerce") }}</span>
    @can('commerce.C')
    <a href="{{ route('pos.index') }}" class="{{ $linkClass }} {{ request()->routeIs('pos.*') ? $activeClass : $inactiveClass }}">{{ __("Caisse") }}</a>
    @endcan
    @can('commerce.L')
    <a href="{{ route('sales.index') }}" class="{{ $linkClass }} {{ request()->routeIs('sales.*') ? $activeClass : $inactiveClass }}">{{ __("Ventes") }}</a>
    <a href="{{ route('clients.index') }}" class="{{ $linkClass }} {{ request()->routeIs('clients.*') ? $activeClass : $inactiveClass }}">{{ __("Clients") }}</a>
    <a href="{{ route('payments.index') }}" class="{{ $linkClass }} {{ request()->routeIs('payments.*') ? $activeClass : $inactiveClass }}">{{ __("Paiements") }}</a>
    @endcan

@elseif(request()->routeIs('expenses.*'))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-receipt text-rose-500 mr-1"></i> {{ __("Dépenses") }}</span>

@elseif(request()->routeIs('notifications.*'))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-bell text-emerald-500 mr-1"></i> {{ __("Notifications") }}</span>
    @can('notifications.S')
    <a href="{{ route('notifications.logs') }}" class="{{ $linkClass }} {{ request()->routeIs('notifications.logs') ? $activeClass : $inactiveClass }}">{{ __("Historique") }}</a>
    @endcan

@elseif(request()->routeIs(['stocks.*', 'dispatches.*']))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-boxes-stacked text-orange-500 mr-1"></i> {{ __("Logistique") }}</span>
    @can('logistique.L')
    <a href="{{ route('stocks.index') }}" class="{{ $linkClass }} {{ request()->routeIs('stocks.*') ? $activeClass : $inactiveClass }}">{{ __("Stocks") }}</a>
    <a href="{{ route('dispatches.index') }}" class="{{ $linkClass }} {{ request()->routeIs('dispatches.index') ? $activeClass : $inactiveClass }}">{{ __("Expéditions") }}</a>
    <a href="{{ route('dispatches.discrepancies') }}" class="{{ $linkClass }} {{ request()->routeIs('dispatches.discrepancies') ? 'bg-red-50 text-red-600' : 'text-red-400 hover:text-red-600 hover:bg-red-50' }}">{{ __("Écarts") }}</a>
    @endcan

@elseif(request()->routeIs('utilities.*'))
    {{-- Section Ressources : on ne garde que le Tableau de bord dans la nav.
         Les points d'entrée Eau / Énergie / Carburant vivent sur l'index. --}}
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-bolt text-cyan-500 mr-1"></i> {{ __("Eau & Énergie") }}</span>
    @can('ressources.L')
    <a href="{{ route('utilities.dashboard') }}" class="{{ $linkClass }} {{ request()->routeIs('utilities.dashboard') ? $activeClass : $inactiveClass }}">{{ __("Tableau de bord") }}</a>
    @endcan

@elseif(request()->routeIs(['users.*', 'farms.*', 'trash.*', 'settings.*', 'admin.species.*']))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-shield-halved text-purple-500 mr-1"></i> {{ __("Admin") }}</span>
    @can('admin.S')
    <a href="{{ route('users.index') }}" class="{{ $linkClass }} {{ request()->routeIs('users.*') ? $activeClass : $inactiveClass }}">{{ __("Accès") }}</a>
    <a href="{{ route('admin.species.index') }}" class="{{ $linkClass }} {{ request()->routeIs('admin.species.*') ? $activeClass : $inactiveClass }}">{{ __("Espèces") }}</a>
    <a href="{{ route('settings.index') }}" class="{{ $linkClass }} {{ request()->routeIs('settings.*') ? $activeClass : $inactiveClass }}">{{ __("Paramètres") }}</a>
    <a href="{{ route('farms.index') }}" class="{{ $linkClass }} {{ request()->routeIs('farms.*') ? $activeClass : $inactiveClass }}">{{ __("Sites") }}</a>
    <a href="{{ route('trash.index') }}" class="{{ $linkClass }} {{ request()->routeIs('trash.*') ? $activeClass : $inactiveClass }}">{{ __("Corbeille") }}</a>
    @endcan
@endif
