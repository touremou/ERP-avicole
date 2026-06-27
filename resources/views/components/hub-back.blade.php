{{--
    Ancre de retour vers le TABLEAU DE BORD (hub) du module courant.

    Résout automatiquement le hub depuis le nom de la route en cours
    (Module::routePrefixMap → slug → Module::landingRoute). Rendue UNE seule fois
    via le layout → toute page de SECTION en bénéficie sans édition page par page.

    NE s'affiche PAS sur :
      - le hub lui-même (ni hors d'un module mappé) ;
      - les pages formulaire/détail (create / edit / show / routes paramétrées) :
        elles ont déjà leur propre flèche « retour à la liste » → évite le doublon.
--}}
@php
    $route = request()->route();
    $routeName = $route?->getName();

    // Page « feuille » (formulaire ou détail) : on laisse sa flèche propre gérer le retour.
    $isLeaf = $routeName && (
        str_ends_with($routeName, '.create')
        || str_ends_with($routeName, '.edit')
        || str_ends_with($routeName, '.show')
        || ! empty($route?->parameters())
    );

    $hubRoute = null;
    if ($routeName && ! $isLeaf) {
        foreach (\App\Models\Module::routePrefixMap() as $prefix => $slug) {
            if (str_starts_with($routeName, $prefix)) {
                $landing = \App\Models\Module::landingRoute($slug);
                if ($landing
                    && \Illuminate\Support\Facades\Route::has($landing)
                    && ! request()->routeIs($landing)) {
                    $hubRoute = $landing;
                }
                break;
            }
        }
    }
@endphp

@if($hubRoute)
<a href="{{ route($hubRoute) }}"
   class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline shrink-0"
   title="{{ __('Tableau de bord') }}">
    <i class="fa-solid fa-arrow-left"></i>
</a>
@endif
