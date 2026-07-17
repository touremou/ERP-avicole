<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Module extends Model
{
    protected $fillable = [
        'name', 'slug', 'icon', 'color', 'description', 'display_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'module_permissions')
            ->withPivot(['can_read', 'can_create', 'can_modify', 'can_delete'])
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('display_order');
    }

    /**
     * SOURCE DE VÉRITÉ UNIQUE : préfixe de nom de route → slug du module.
     *
     * Cette table est l'unique référence pour rattacher une route à son
     * module. Elle est consommée par :
     *   - AppServiceProvider : résolution des Gates génériques L/C/M/S
     *     (middleware `can:L|C|M|S`) au module concerné par la requête ;
     *   - la navigation (détection du module actif).
     *
     * RÈGLE INDUSTRIELLE : une fonctionnalité appartient à UN SEUL module.
     * Tout contrôleur qui fait un contrôle interne `Gate::denies('slug.X')`
     * DOIT utiliser le slug défini ici pour son préfixe de route, sous peine
     * d'incohérence entre le middleware de route, le contrôleur et la nav.
     *
     * Note : « tasks » (tâches opérationnelles assignées au personnel) relève
     * de l'Annuaire/RH, PAS du module Planning qui concerne la planification
     * des bandes/lots.
     */
    public static function routePrefixMap(): array
    {
        return [
            // Élevage
            'elevage.'          => 'elevage',
            'buildings.'        => 'elevage',
            'batches.'          => 'elevage',
            'campaigns.'        => 'elevage',
            'health.'           => 'elevage',
            'daily-checks.'     => 'elevage',
            'protocols.'        => 'elevage',
            'reports.'          => 'elevage',

            // Logistique
            'logistique.'       => 'logistique',
            'stocks.'           => 'logistique',
            'dispatches.'       => 'logistique',
            'stock-adjustments.' => 'logistique',

            // Production Végétale (parcelles, cycles de culture, récoltes)
            'cultures.'            => 'cultures',
            'plots.'               => 'cultures',
            'crop-cycles.'         => 'cultures',
            'crop-transformations.' => 'cultures',
            'crop-catalogue.'      => 'cultures',
            'crop-campaigns.'      => 'cultures',
            'crop-recipes.'         => 'cultures',
            'crop-protocols.'       => 'cultures',
            'crop-reports.'         => 'cultures',
            'crop-calendar-events.' => 'cultures',
            'weather.'              => 'cultures',
            'harvests.'             => 'cultures',

            // Provenderie
            'provenderie.'      => 'provenderie',
            'raw-materials.'    => 'provenderie',
            'formulas.'         => 'provenderie',
            'norms.'            => 'provenderie',
            'production.'       => 'provenderie',
            'machines.'         => 'provenderie',
            'feed-purchases.'   => 'provenderie',

            // Production (œufs, couvoir, lait) — 'productions.' (pluriel) = hub,
            // distinct de 'production.' (Provenderie) : pas de collision de préfixe.
            'productions.'      => 'production',
            'incubations.'      => 'production',
            'chick-dispatches.' => 'production',
            'incubators.'       => 'production',
            'egg-productions.'  => 'production',
            'egg-movements.'    => 'production',
            'milk-productions.' => 'production',

            // Commerce (vente, caisse, après-vente — un seul module intégré)
            // Commerce = VENTES back-office (clients, factures, recouvrement,
            // tarifs, avoirs, catalogue).
            'commerce.'         => 'commerce',
            'clients.'          => 'commerce',
            'sales.'            => 'commerce',
            'payments.'         => 'commerce',
            'returns.'          => 'commerce',
            'products.'         => 'commerce',

            // Caisse = POS front-office (point de vente, sessions de caisse) —
            // module distinct (un caissier n'accède pas au back-office ventes).
            'caisse.'           => 'caisse',
            'pos.'              => 'caisse',
            'cash-register.'    => 'caisse',

            // Finance (hub + registre dépenses + trésorerie + achats fournisseurs + budgets)
            // Dépenses / Achats = saisie (registre des dépenses, achats
            // fournisseurs, budgets).
            'finance.'          => 'depenses',
            'expenses.'         => 'depenses',
            'purchases.'        => 'depenses',
            'budgets.'          => 'depenses',

            // Trésorerie = comptes/soldes/mouvements/virements — module distinct
            // (un saisisseur de dépenses ne voit pas les soldes bancaires).
            'tresorerie.'       => 'tresorerie',
            'treasury.'         => 'tresorerie',

            // Ressources (eau & énergie)
            'utilities.'        => 'ressources',

            // Notifications
            'notifications.'    => 'notifications',

            // Planning (planification des bandes/lots)
            'planning.'         => 'planning',

            // Abattoir
            'slaughter.'        => 'abattoir',

            // Annuaire / RH (hub, employés, présence, fournisseurs, paie, tâches)
            // Annuaire = TIERS (fournisseurs) uniquement.
            'annuaire.'         => 'annuaire',
            'providers.'        => 'annuaire',

            // RH INTERNE = employés, paie, pointage, congés, tâches — module
            // distinct (cloisonnement : un accès Tiers n'ouvre pas la RH).
            'rh.'               => 'rh',
            'employees.'        => 'rh',
            'attendance.'       => 'rh',
            'payroll.'          => 'rh',
            'tasks.'            => 'rh',

            // Administration
            'users.'            => 'admin',
            'roles.'            => 'admin',
            'admin.'            => 'admin',
            'settings.'         => 'admin',
            'farms.'            => 'admin',
            'trash.'            => 'admin',
            'api.species.'      => 'admin',
        ];
    }

    /**
     * Modules NON affichés comme tuiles du lanceur (méga-menu) : leur accès est
     * intégré ailleurs pour un menu plus industriel —
     *   - planning      → carte « Planning » du hub Élevage ;
     *   - notifications  → cloche d'en-tête + menu utilisateur.
     * Leurs modules/permissions/routes restent intacts (juste pas de tuile).
     */
    public static function nonLauncherSlugs(): array
    {
        return ['planning', 'notifications'];
    }

    /**
     * Route d'atterrissage (« accueil ») d'un module, pour les lanceurs de
     * modules (drawer de navigation). Renvoie un nom de route Laravel.
     */
    public static function landingRoute(string $slug): ?string
    {
        return [
            'dashboard'     => 'dashboard',
            'elevage'       => 'elevage.index',
            'production'    => 'productions.index',
            'provenderie'   => 'provenderie.dashboard',
            'cultures'      => 'cultures.dashboard',
            'planning'      => 'planning.index',
            'abattoir'      => 'slaughter.dashboard',
            'commerce'      => 'commerce.index',
            'caisse'        => 'pos.index',
            'logistique'    => 'logistique.index',
            'ressources'    => 'utilities.dashboard',
            'notifications' => 'notifications.preferences',
            'annuaire'      => 'annuaire.index',
            'rh'            => 'rh.index',
            'admin'         => 'users.index',
            'depenses'      => 'finance.index',
            'tresorerie'    => 'treasury.index',
        ][$slug] ?? null;
    }
}
