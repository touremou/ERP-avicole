{{--
    Ancre de RETOUR contextuelle, rendue une fois via le layout (à gauche de $header).

    Cible hiérarchique :
      - Niveau 1 (page « xxx.index », la section elle-même) → retour au HUB du module.
      - Niveau 2+ (page « xxx.yyy » d'une section, ex. reports.profit_loss) → retour
        à la SECTION parente (« xxx.index »), pas au hub.

    NE s'affiche PAS :
      - sur le hub lui-même, ni hors d'un module mappé ;
      - sur les modules « non-lanceur » (planning/notifications, intégrés ailleurs) ;
      - sur les pages formulaire/détail (create/edit/show/route paramétrée) : elles
        ont déjà leur propre flèche « retour à la liste ».
--}}
@php
    $route = request()->route();
    $routeName = $route?->getName();

    $isLeaf = $routeName && (
        str_ends_with($routeName, '.create')
        || str_ends_with($routeName, '.edit')
        || str_ends_with($routeName, '.show')
        || ! empty($route?->parameters())
    );

    // Sous-sections dont le parent logique N'EST PAS le hub du module mais une
    // autre section. Ex. le registre des suivis quotidiens (« Historique
    // Suivi » des lots) doit remonter vers la liste des LOTS, pas vers le hub
    // Élevage (que l'utilisateur perçoit comme un tableau de bord).
    $parentOverride = [
        'daily-checks.index' => 'batches.index',
        // Les 4 registres HACCP remontent vers LEUR hub (« Registres HACCP »),
        // pas vers le tableau de bord de l'abattoir.
        'slaughter.registres.ccp'          => 'slaughter.registres.index',
        'slaughter.registres.temperatures' => 'slaughter.registres.index',
        'slaughter.registres.nettoyage'    => 'slaughter.registres.index',
        'slaughter.registres.sous_produits' => 'slaughter.registres.index',
        // La Trésorerie est une SECTION du hub Finance dans le parcours
        // utilisateur (tuile « Comptes & mouvements ») : son retour remonte au
        // hub Finance, pas à sa propre racine — sinon l'utilisateur venu du hub
        // tourne en boucle dans le module Trésorerie.
        'treasury.index' => 'finance.index',
        // Le centre de rapports est une entrée TRANSVERSE (menu de premier
        // niveau, accessible aux profils élevage/finance/admin) : son retour
        // remonte au tableau de bord, pas au hub Élevage — un profil finance
        // sans droit élevage y serait rejeté.
        'reports.index' => 'dashboard',
    ];

    $target = null;
    if ($routeName && ! $isLeaf && isset($parentOverride[$routeName]) && \Illuminate\Support\Facades\Route::has($parentOverride[$routeName])) {
        $target = $parentOverride[$routeName];
    } elseif ($routeName && ! $isLeaf) {
        // Module du route courant (et exclusion des modules non-lanceur).
        $slug = null;
        foreach (\App\Models\Module::routePrefixMap() as $prefix => $s) {
            if (str_starts_with($routeName, $prefix)) { $slug = $s; break; }
        }

        if ($slug && ! in_array($slug, \App\Models\Module::nonLauncherSlugs(), true)) {
            $sectionIndex = explode('.', $routeName)[0] . '.index';

            if ($routeName !== $sectionIndex && \Illuminate\Support\Facades\Route::has($sectionIndex)) {
                // Niveau 2+ → section parente.
                $target = $sectionIndex;
            } else {
                // Niveau 1 (la section index) → hub du module.
                $landing = \App\Models\Module::landingRoute($slug);
                if ($landing && \Illuminate\Support\Facades\Route::has($landing) && ! request()->routeIs($landing)) {
                    $target = $landing;
                }
            }
        }
    }
@endphp

@if($target)
<a href="{{ route($target) }}"
   class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline shrink-0"
   title="{{ __('Retour') }}">
    <i class="fa-solid fa-arrow-left"></i>
</a>
@endif
