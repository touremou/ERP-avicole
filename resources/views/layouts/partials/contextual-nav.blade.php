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

@elseif(request()->routeIs(['annuaire.*', 'employees.*', 'payroll.*', 'tasks.*', 'providers.*', 'attendance.*']))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-users text-slate-500 mr-1"></i> {{ __("Annuaire") }}</span>
    @can('annuaire.L')
    <a href="{{ route('annuaire.index') }}" class="{{ $linkClass }} {{ request()->routeIs('annuaire.*') ? $activeClass : $inactiveClass }}">{{ __("Tableau de bord") }}</a>
    @endcan

@elseif(request()->routeIs(['elevage.*', 'buildings.*','batches.*', 'daily-checks.*', 'health.*', 'protocols.*', 'reports.*', 'campaigns.*', 'planning.*']))
    {{-- Planning est intégré à Élevage (carte du hub) : ses pages affichent le
         breadcrumb Élevage, pas de section autonome. --}}
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-dove text-blue-500 mr-1"></i> {{ __("Élevage") }}</span>
    @can('elevage.L')
    <a href="{{ route('elevage.index') }}" class="{{ $linkClass }} {{ request()->routeIs('elevage.*') ? $activeClass : $inactiveClass }}">{{ __("Tableau de bord") }}</a>
    @endcan

@elseif(request()->routeIs(['productions.*', 'egg-productions.*', 'egg-movements.*', 'milk-productions.*', 'incubations.*', 'chick-dispatches.*']))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-egg text-amber-500 mr-1"></i> {{ __("Production") }}</span>
    @can('production.L')
    <a href="{{ route('productions.index') }}" class="{{ $linkClass }} {{ request()->routeIs('productions.*') ? $activeClass : $inactiveClass }}">{{ __("Tableau de bord") }}</a>
    @endcan

@elseif(request()->routeIs(['cultures.*', 'plots.*', 'crop-cycles.*', 'crop-transformations.*', 'crop-catalogue.*', 'crop-campaigns.*', 'crop-recipes.*', 'crop-reports.*', 'crop-calendar-events.*', 'crop-protocols.*', 'weather.*']))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-seedling text-green-600 mr-1"></i> {{ __("Production Végétale") }}</span>
    @can('cultures.L')
    {{-- Barre contextuelle = entités opérationnelles. Le pilotage (vue d'ensemble,
         calendrier, météo) et le référentiel (catalogue, protocoles, recettes)
         vivent dans la sous-navigation « hub » du tableau de bord. --}}
    <a href="{{ route('cultures.dashboard') }}" class="{{ $linkClass }} {{ request()->routeIs('cultures.dashboard') || request()->routeIs('crop-catalogue.*') || request()->routeIs('crop-protocols.*') || request()->routeIs('crop-recipes.*') || request()->routeIs('crop-calendar-events.*') ? $activeClass : $inactiveClass }}">{{ __("Tableau de bord") }}</a>
    @endcan

@elseif(request()->routeIs(['provenderie.*', 'raw-materials.*', 'formulas.*', 'production.*', 'machines.*']))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-wheat-awn text-lime-600 mr-1"></i> {{ __("Provenderie") }}</span>
    @can('provenderie.L')
    <a href="{{ route('provenderie.dashboard') }}" class="{{ $linkClass }} {{ request()->routeIs('provenderie.dashboard') ? $activeClass : $inactiveClass }}">{{ __("Tableau de bord") }}</a>
    @endcan

@elseif(request()->routeIs('slaughter.*'))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-drumstick-bite text-rose-500 mr-1"></i> {{ __("Abattoir") }}</span>
    @can('abattoir.L')
    <a href="{{ route('slaughter.dashboard') }}" class="{{ $linkClass }} {{ request()->routeIs('slaughter.dashboard') ? $activeClass : $inactiveClass }}">{{ __("Tableau de bord") }}</a>
    @endcan

@elseif(request()->routeIs(['commerce.*', 'sales.*', 'clients.*', 'payments.*', 'pos.*', 'returns.*', 'cash-register.*']))
    {{-- Module Commerce : entrée UNIFIÉE (hub-only). Tous les accès (Caisse, Session,
         Ventes, Clients, Paiements, Avoirs) vivent dans les cartes du hub ; chaque
         sous-page porte une ancre de retour <x-hub-back/> vers le tableau de bord. --}}
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-cash-register text-teal-500 mr-1"></i> {{ __("Commerce") }}</span>
    @can('commerce.L')
    <a href="{{ route('commerce.index') }}" class="{{ $linkClass }} {{ request()->routeIs('commerce.*') ? $activeClass : $inactiveClass }}">{{ __("Tableau de bord") }}</a>
    @endcan

@elseif(request()->routeIs(['finance.*', 'expenses.*', 'budgets.*', 'treasury.*', 'purchases.*']))
    {{-- Module Finance : hub d'abord (Tableau de bord), puis les grands livres. --}}
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-coins text-rose-500 mr-1"></i> {{ __("Finance") }}</span>
    @can('depenses.L')
    <a href="{{ route('finance.index') }}" class="{{ $linkClass }} {{ request()->routeIs('finance.*') ? $activeClass : $inactiveClass }}">{{ __("Tableau de bord") }}</a>
    @endcan

@elseif(request()->routeIs('notifications.*'))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-bell text-emerald-500 mr-1"></i> {{ __("Notifications") }}</span>
    @can('notifications.S')
    <a href="{{ route('notifications.logs') }}" class="{{ $linkClass }} {{ request()->routeIs('notifications.logs') ? $activeClass : $inactiveClass }}">{{ __("Historique") }}</a>
    @endcan

@elseif(request()->routeIs(['logistique.*', 'stocks.*', 'dispatches.*', 'stock-adjustments.*']))
    <span class="{{ $sectionClass }}"><i class="fa-solid fa-boxes-stacked text-orange-500 mr-1"></i> {{ __("Logistique") }}</span>
    @can('logistique.L')
    <a href="{{ route('logistique.index') }}" class="{{ $linkClass }} {{ request()->routeIs('logistique.*') ? $activeClass : $inactiveClass }}">{{ __("Tableau de bord") }}</a>
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
