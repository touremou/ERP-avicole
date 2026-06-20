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
            'buildings.'        => 'elevage',
            'batches.'          => 'elevage',
            'campaigns.'        => 'elevage',
            'health.'           => 'elevage',
            'daily-checks.'     => 'elevage',
            'protocols.'        => 'elevage',
            'reports.'          => 'elevage',

            // Logistique
            'stocks.'           => 'logistique',
            'dispatches.'       => 'logistique',

            // Production Végétale (parcelles, cycles de culture, récoltes)
            'cultures.'         => 'cultures',
            'plots.'            => 'cultures',
            'crop-cycles.'      => 'cultures',
            'harvests.'         => 'cultures',

            // Provenderie
            'provenderie.'      => 'provenderie',
            'raw-materials.'    => 'provenderie',
            'formulas.'         => 'provenderie',
            'norms.'            => 'provenderie',
            'production.'       => 'provenderie',
            'machines.'         => 'provenderie',
            'feed-purchases.'   => 'provenderie',

            // Production (œufs, couvoir, lait)
            'incubations.'      => 'production',
            'chick-dispatches.' => 'production',
            'incubators.'       => 'production',
            'egg-productions.'  => 'production',
            'egg-movements.'    => 'production',
            'milk-productions.' => 'production',

            // Commerce
            'clients.'          => 'commerce',
            'sales.'            => 'commerce',
            'payments.'         => 'commerce',

            // Dépenses
            'expenses.'         => 'depenses',

            // Ressources (eau & énergie)
            'utilities.'        => 'ressources',

            // Notifications
            'notifications.'    => 'notifications',

            // Planning (planification des bandes/lots)
            'planning.'         => 'planning',

            // Abattoir
            'slaughter.'        => 'abattoir',

            // Annuaire / RH (employés, fournisseurs, paie, tâches)
            'employees.'        => 'annuaire',
            'providers.'        => 'annuaire',
            'payroll.'          => 'annuaire',
            'tasks.'            => 'annuaire',

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
     * Route d'atterrissage (« accueil ») d'un module, pour les lanceurs de
     * modules (drawer de navigation). Renvoie un nom de route Laravel.
     */
    public static function landingRoute(string $slug): ?string
    {
        return [
            'dashboard'     => 'dashboard',
            'elevage'       => 'buildings.index',
            'production'    => 'egg-productions.index',
            'provenderie'   => 'provenderie.dashboard',
            'cultures'      => 'cultures.dashboard',
            'planning'      => 'planning.index',
            'abattoir'      => 'slaughter.dashboard',
            'commerce'      => 'sales.index',
            'logistique'    => 'stocks.index',
            'ressources'    => 'utilities.dashboard',
            'notifications' => 'notifications.preferences',
            'annuaire'      => 'employees.index',
            'admin'         => 'users.index',
            'depenses'      => 'expenses.index',
        ][$slug] ?? null;
    }
}
