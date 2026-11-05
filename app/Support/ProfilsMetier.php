<?php

namespace App\Support;

use App\Models\Module;
use App\Models\ModulePermission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * PROFILS MÉTIER — des rôles prêts à l'emploi, chacun limité à ses modules,
 * et l'ESPACE DE TRAVAIL (profil d'interface) que l'application terrain
 * adapte : tuiles « Mon poste », indicateurs, ordre du « + ».
 *
 * Décision de l'exploitation. Les rôles livrés (Administrateur, Technicien,
 * Vendeur, Ouvrier) portaient le MÊME niveau sur tous les modules : un
 * vendeur pouvait saisir en élevage et à l'abattoir, un technicien voyait la
 * caisse et la trésorerie — l'application terrain montrait presque tout à
 * presque tout le monde. Ces profils s'AJOUTENT aux rôles existants, sans
 * toucher aux droits de quiconque : l'administrateur réaffecte chacun depuis
 * la page Utilisateurs, et peut ensuite ajuster la matrice de chaque profil.
 *
 * Le stock (consultation Logistique) est accordé à tous les profils de
 * terrain : le pointage y choisit l'aliment, le soin son produit, la
 * provenderie et les cultures leurs intrants.
 */
final class ProfilsMetier
{
    /** Espaces de travail du terrain : clé → libellé. */
    public const ESPACES = [
        'direction'   => 'Direction',
        'gerant'      => 'Gérant de site',
        'elevage'     => "Technicien d'élevage",
        'terrain'     => 'Agent de terrain',
        'abattoir'    => 'Opérateur abattoir',
        'provenderie' => 'Agent provenderie',
        'cultures'    => 'Technicien cultures',
        'vente'       => 'Vendeur / caissier',
        'magasin'     => 'Magasinier',
        'finance'     => 'Comptable / trésorier',
        'rh'          => 'Responsable RH',
    ];

    /** Modules consultés par (presque) tout le monde. */
    private const SOCLE = ['dashboard' => 'L', 'notifications' => 'L'];

    /**
     * Les profils : nom système → [libellé, icône, espace, matrice module → niveaux].
     * Un module absent de la matrice n'est pas accessible.
     *
     * @var array<string, array{display_name: string, icon: string, workspace: string, modules: array<string, string>}>
     */
    public const PROFILS = [
        'profil-direction' => [
            'display_name' => 'Direction', 'icon' => '🏛️', 'workspace' => 'direction',
            'modules' => ['*' => 'LCMS'],
        ],
        'profil-gerant' => [
            'display_name' => 'Gérant de site', 'icon' => '🧭', 'workspace' => 'gerant',
            'modules' => [
                '*' => 'L',
                'elevage' => 'LCM', 'production' => 'LCM', 'provenderie' => 'LCM', 'abattoir' => 'LCM',
                'commerce' => 'LCM', 'caisse' => 'LCM', 'depenses' => 'LCM', 'logistique' => 'LCM',
                'ressources' => 'LCM', 'annuaire' => 'LCM', 'rh' => 'LCM', 'cultures' => 'LCM',
                'planning' => 'LCM', 'admin' => '',
            ],
        ],
        'profil-technicien-elevage' => [
            'display_name' => "Technicien d'élevage", 'icon' => '🐔', 'workspace' => 'elevage',
            'modules' => [
                'elevage' => 'LCM', 'production' => 'LCM', 'logistique' => 'LC', 'ressources' => 'LC',
                'depenses' => 'LC', 'annuaire' => 'L', 'planning' => 'L',
            ],
        ],
        'profil-agent-terrain' => [
            'display_name' => 'Agent de terrain', 'icon' => '🧑‍🌾', 'workspace' => 'terrain',
            'modules' => [
                'elevage' => 'LC', 'production' => 'LC', 'ressources' => 'LC', 'logistique' => 'L',
            ],
        ],
        'profil-operateur-abattoir' => [
            'display_name' => 'Opérateur abattoir', 'icon' => '🔪', 'workspace' => 'abattoir',
            'modules' => [
                'abattoir' => 'LCM', 'logistique' => 'L', 'elevage' => 'L', 'annuaire' => 'L',
            ],
        ],
        'profil-agent-provenderie' => [
            'display_name' => 'Agent provenderie', 'icon' => '🏭', 'workspace' => 'provenderie',
            'modules' => [
                'provenderie' => 'LCM', 'logistique' => 'LC', 'annuaire' => 'L',
            ],
        ],
        'profil-technicien-cultures' => [
            'display_name' => 'Technicien cultures', 'icon' => '🌱', 'workspace' => 'cultures',
            'modules' => [
                'cultures' => 'LCM', 'ressources' => 'LC', 'logistique' => 'LC',
            ],
        ],
        'profil-vendeur' => [
            'display_name' => 'Vendeur / caissier', 'icon' => '🧾', 'workspace' => 'vente',
            'modules' => [
                'commerce' => 'LC', 'caisse' => 'LCM', 'annuaire' => 'LC', 'logistique' => 'L',
            ],
        ],
        'profil-magasinier' => [
            'display_name' => 'Magasinier', 'icon' => '📦', 'workspace' => 'magasin',
            'modules' => [
                'logistique' => 'LCM', 'provenderie' => 'L', 'depenses' => 'LC', 'annuaire' => 'L',
            ],
        ],
        'profil-comptable' => [
            'display_name' => 'Comptable / trésorier', 'icon' => '💰', 'workspace' => 'finance',
            'modules' => [
                '*' => 'L',
                'tresorerie' => 'LCM', 'depenses' => 'LCM', 'commerce' => 'LCM', 'caisse' => 'L',
                'admin' => '', 'rh' => '',
            ],
        ],
        // Vétérinaire — souvent un praticien EXTERNE en Guinée, appelé sur un
        // foyer : soins, diagnostics, prescriptions ; l'abattoir en lecture
        // (inspection ante / post mortem) ; pas d'argent, pas de stock à toucher.
        'profil-veterinaire' => [
            'display_name' => 'Vétérinaire', 'icon' => '🩺', 'workspace' => 'elevage',
            'modules' => [
                'elevage' => 'LCM', 'production' => 'L', 'abattoir' => 'L', 'logistique' => 'L', 'annuaire' => 'L',
            ],
        ],
        // Auditeur / contrôleur — bailleur, banque, partenaire d'un projet
        // financé, commissaire aux comptes : TOUT en lecture, rien à saisir.
        'profil-auditeur' => [
            'display_name' => 'Auditeur / contrôleur', 'icon' => '🔍', 'workspace' => 'direction',
            'modules' => ['*' => 'L', 'admin' => ''],
        ],
        'profil-responsable-rh' => [
            'display_name' => 'Responsable RH', 'icon' => '🧑‍💼', 'workspace' => 'rh',
            'modules' => [
                'rh' => 'LCM', 'planning' => 'LCM', 'annuaire' => 'L',
            ],
        ],
    ];

    /** Les niveaux d'un profil sur un module (« LCM », « L », « »). */
    public static function niveaux(string $profil, string $module): string
    {
        $modules = self::PROFILS[$profil]['modules'] ?? [];

        if (array_key_exists($module, $modules)) {
            return $modules[$module];
        }

        return $modules['*'] ?? (self::SOCLE[$module] ?? '');
    }

    /**
     * Installe les profils manquants — IDEMPOTENT. Un profil déjà présent
     * (même nom système) n'est PAS retouché : l'administrateur a pu ajuster sa
     * matrice, et la réinstallation ne doit pas défaire son travail.
     *
     * @return array<int, string>  les profils créés
     */
    public static function installer(): array
    {
        $modules = Module::query()->get(['id', 'slug']);
        if ($modules->isEmpty()) {
            return [];   // installation neuve : les modules arrivent avec le seed
        }

        $crees = [];

        DB::transaction(function () use ($modules, &$crees) {
            foreach (self::PROFILS as $nom => $profil) {
                if (Role::query()->where('name', $nom)->exists()) {
                    continue;
                }

                $role = Role::create([
                    'name'         => $nom,
                    'display_name' => $profil['display_name'],
                    'label'        => $profil['display_name'],
                    'icon'         => $profil['icon'],
                    'workspace'    => $profil['workspace'],
                    'permissions'  => [],
                ]);

                foreach ($modules as $module) {
                    $niveaux = self::niveaux($nom, $module->slug);
                    if ($niveaux === '') {
                        continue;
                    }

                    ModulePermission::updateOrCreate(
                        ['role_id' => $role->id, 'module_id' => $module->id],
                        [
                            'can_read'   => str_contains($niveaux, 'L'),
                            'can_create' => str_contains($niveaux, 'C'),
                            'can_modify' => str_contains($niveaux, 'M'),
                            'can_delete' => str_contains($niveaux, 'S'),
                        ],
                    );
                }

                $crees[] = $nom;
            }
        });

        return $crees;
    }

    /**
     * L'espace de travail DÉDUIT des droits, pour un rôle qui n'en déclare pas
     * (les rôles existants, un rôle créé à la main) : le métier dont l'agent
     * peut SAISIR le plus de choses, dans l'ordre de priorité du terrain.
     *
     * @param  callable(string $module, string $niveau): bool  $peut
     */
    public static function espaceDeduit(callable $peut): string
    {
        if ($peut('admin', 'S')) {
            return 'direction';
        }

        $saisit = fn (string $module) => $peut($module, 'C');

        $operations = array_filter(['elevage', 'production', 'abattoir', 'provenderie', 'cultures', 'commerce', 'logistique'], $saisit);
        if (count($operations) >= 4) {
            return 'gerant';
        }

        return match (true) {
            $saisit('elevage') && $peut('elevage', 'M') => 'elevage',
            $saisit('elevage') || $saisit('production') => 'terrain',
            $saisit('abattoir')                          => 'abattoir',
            $saisit('provenderie')                       => 'provenderie',
            $saisit('cultures')                          => 'cultures',
            $saisit('commerce') || $saisit('caisse')     => 'vente',
            $saisit('logistique')                        => 'magasin',
            $saisit('tresorerie') || $saisit('depenses') => 'finance',
            $saisit('rh')                                => 'rh',
            default                                      => 'terrain',
        };
    }
}
