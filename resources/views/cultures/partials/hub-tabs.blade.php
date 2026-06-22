{{-- Sous-navigation « hub » du module Production Végétale.
     Bandeau d'onglets unique partagé par le tableau de bord et les pages de
     référentiel (Catalogue / Protocoles / Recettes), afin d'éviter toute
     redondance avec la barre contextuelle : les entités opérationnelles
     (Parcelles, Cycles, Campagnes, Transformation, Rapports) restent dans la
     barre contextuelle ; le pilotage et le référentiel vivent ici.

     Groupe 1 — PILOTAGE   : Vue d'ensemble · Calendrier · Météo (rendus dans le dashboard)
     Groupe 2 — RÉFÉRENTIEL: Catalogue · Protocoles · Recettes (pages dédiées) --}}
@php
    $tab        = request()->routeIs('cultures.dashboard') ? request()->query('tab', 'overview') : null;
    $tabBase    = 'px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest italic no-underline flex items-center gap-2 transition-all';
    $tabActive  = 'bg-slate-900 text-white';
    $tabIdle    = 'bg-white text-slate-400 hover:text-slate-700 border border-slate-100';

    $isOverview  = request()->routeIs('cultures.dashboard') && $tab === 'overview';
    $isCalendar  = (request()->routeIs('cultures.dashboard') && $tab === 'calendar') || request()->routeIs('crop-calendar-events.*');
    $isMeteo     = request()->routeIs('cultures.dashboard') && $tab === 'meteo';
    $isCatalogue = request()->routeIs('crop-catalogue.*') || (request()->routeIs('cultures.dashboard') && $tab === 'catalogue');
    $isProtocols = request()->routeIs('crop-protocols.*');
    $isRecipes   = request()->routeIs('crop-recipes.*');
@endphp

<div class="flex flex-wrap items-center gap-2 mt-5">
    {{-- Pilotage --}}
    <a href="{{ route('cultures.dashboard', ['tab' => 'overview']) }}"
       class="{{ $tabBase }} {{ $isOverview ? $tabActive : $tabIdle }}">
        <i class="fa-solid fa-gauge-high"></i> {{ __("Vue d'ensemble") }}
    </a>
    <a href="{{ route('cultures.dashboard', ['tab' => 'calendar']) }}"
       class="{{ $tabBase }} {{ $isCalendar ? $tabActive : $tabIdle }}">
        <i class="fa-solid fa-calendar-days"></i> {{ __("Calendrier") }}
    </a>
    <a href="{{ route('cultures.dashboard', ['tab' => 'meteo']) }}"
       class="{{ $tabBase }} {{ $isMeteo ? $tabActive : $tabIdle }}">
        <i class="fa-solid fa-cloud-sun-rain"></i> {{ __("Météo") }}
    </a>

    {{-- Séparateur pilotage / référentiel --}}
    <span class="hidden sm:inline-block h-6 w-px bg-slate-200 mx-1"></span>

    {{-- Référentiel --}}
    <a href="{{ route('crop-catalogue.index') }}"
       class="{{ $tabBase }} {{ $isCatalogue ? $tabActive : $tabIdle }}">
        <i class="fa-solid fa-book-open"></i> {{ __("Catalogue") }}
    </a>
    <a href="{{ route('crop-protocols.index') }}"
       class="{{ $tabBase }} {{ $isProtocols ? $tabActive : $tabIdle }}">
        <i class="fa-solid fa-list-check"></i> {{ __("Protocoles") }}
    </a>
    <a href="{{ route('crop-recipes.index') }}"
       class="{{ $tabBase }} {{ $isRecipes ? $tabActive : $tabIdle }}">
        <i class="fa-solid fa-flask"></i> {{ __("Recettes") }}
    </a>
</div>
