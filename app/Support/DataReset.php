<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REMISE À ZÉRO DES DONNÉES DE TEST — avant la mise en service.
 *
 * Le besoin : une installation sur laquelle on s'est formé — lots fictifs,
 * ventes d'essai, pointages pour voir — doit repartir propre le jour où elle
 * passe en service. L'application ne PEUT PAS distinguer une vente de test
 * d'une vraie vente ; la seule réponse honnête est donc de vider les
 * MOUVEMENTS en gardant la CONFIGURATION et les RÉFÉRENTIELS réels.
 *
 * ─── TROIS CLASSES, ET AUCUNE TABLE HORS CLASSE ───
 *
 *   • CONFIGURATION — gardée telle quelle : réglages, rôles, comptes, espèces,
 *     normes, catalogue, modèles, et le cadre technique (migrations, files…).
 *   • RÉFÉRENTIELS — gardés, mais ce que les mouvements de test y avaient
 *     déposé est remis d'aplomb (cf. `remettreDAplomb`) : un stock sans ses
 *     mouvements ne peut pas garder sa quantité, un client sans ses ventes ne
 *     peut pas garder son solde.
 *   • MOUVEMENTS — vidés.
 *
 * Une table que ces listes ne connaissent pas n'est ni vidée ni gardée en
 * silence : la commande REFUSE de tourner tant qu'elle n'est pas classée
 * (`nonClassees`). C'est le sens prudent pour un geste irréversible — une
 * table ajoutée demain et oubliée ici ne sera pas effacée par surprise.
 *
 * Le choix de garder bâtiments, employés, clients, fournisseurs et articles de
 * stock est celui de l'exploitant (2026-10-04).
 */
class DataReset
{
    /** Gardées telles quelles. */
    public const CONFIGURATION = [
        // Cadre technique
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'sessions', 'password_reset_tokens', 'personal_access_tokens',
        // Comptes, droits, sites
        'users', 'roles', 'permissions', 'permission_role', 'modules', 'module_permissions',
        'farms', 'farm_user', 'licenses',
        'notification_preferences', 'notification_templates', 'dashboard_configurations', 'push_subscriptions',
        // Réglages et leur journal
        'settings', 'setting_audits', 'activity_log',
        // Référentiel zootechnique et agronomique
        'species', 'production_types', 'production_norms', 'food_norms',
        'crop_species', 'crop_varieties', 'crop_protocols', 'crop_protocol_items',
        'crop_recipes', 'crop_recipe_items',
        // Modèles de travail
        'protocols', 'protocol_steps', 'task_templates', 'formulas', 'formula_items',
        'cutting_recipes', 'cutting_recipe_lines',
        // Catalogue de vente et tarifs
        'products', 'price_lists', 'sale_price_lists', 'sale_price_list_items',
        // Plans et matériel de mesure
        'budgets', 'telemetry_sensors',
    ];

    /** Gardés, avec remise d'aplomb des valeurs dérivées des mouvements. */
    public const REFERENTIELS = [
        'buildings', 'employees', 'employee_contract_events', 'employee_assignments',
        'clients', 'providers', 'stocks', 'raw_materials',
        'plots', 'incubators', 'mill_machines', 'energy_sources', 'water_sources',
        'treasury_accounts',
    ];

    /** Vidées. */
    public const MOUVEMENTS = [
        // Élevage, santé, production
        'batches', 'daily_checks', 'daily_check_extensions', 'health_checks', 'health_incidents',
        'egg_productions', 'egg_movements', 'milk_productions', 'planned_batches', 'campaigns',
        'chick_dispatches', 'incubations', 'incubator_maintenances',
        // Provenderie, achats, stocks
        'feed_purchases', 'mill_productions', 'mill_production_machine',
        'stock_movements', 'stock_adjustments', 'receptions', 'reception_items',
        'dispatches', 'dispatch_items', 'discrepancy_reports', 'stored_lots', 'stored_lot_checks',
        'supplier_invoices', 'supplier_payments',
        // Ventes, encaissements, caisse
        'sales', 'sale_items', 'sale_returns', 'sale_return_items', 'payments', 'payment_reminders',
        'cash_register_sessions',
        // Trésorerie, dépenses
        'treasury_transactions', 'expenses',
        // Abattoir, transformation
        'slaughter_orders', 'slaughter_receptions', 'slaughter_results', 'slaughter_byproducts',
        'cutting_sessions', 'cut_products', 'finished_products', 'finished_product_adjustments',
        'transformations', 'ccp_records', 'temperature_logs', 'cleaning_logs',
        // Cultures
        'crop_campaigns', 'crop_cycles', 'crop_inputs', 'crop_protocol_completions',
        'crop_transformations', 'crop_calendar_events', 'harvests', 'weather_readings',
        // RH, paie
        'employee_attendances', 'employee_leaves', 'payroll_periods', 'payslips', 'payslip_lines',
        // Ressources, maintenance
        'energy_readings', 'water_readings', 'fuel_purchases', 'maintenance_logs', 'asset_maintenance_logs',
        // Tâches, notifications, synchro, télémétrie
        'task_assignments', 'notifications', 'notification_logs', 'sync_operations', 'telemetry_logs',
    ];

    /**
     * Les congés annuels qui ont PRÉLEVÉ des jours sur le solde.
     *
     * `PayrollController::applyLeaveApproval` décompte `days_count` à
     * l'approbation, pour le seul congé annuel ; `endLeave` rend la part non
     * prise ET réduit `days_count` d'autant. Le prélèvement net d'un congé est
     * donc son `days_count` actuel, dans ces statuts. Une demande jamais
     * approuvée n'a rien prélevé.
     */
    private const STATUTS_CONGE_PRELEVE = ['approuve', 'en_cours', 'termine'];

    /** Tables du schéma réel. */
    public static function tables(): array
    {
        return array_map(
            fn ($t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t,
            Schema::getTableListing(),
        );
    }

    /** Tables que les trois classes ne connaissent pas — la commande refuse alors. */
    public static function nonClassees(): array
    {
        $classees = array_merge(self::CONFIGURATION, self::REFERENTIELS, self::MOUVEMENTS);

        return array_values(array_diff(self::tables(), $classees));
    }

    /**
     * Ce que la remise à zéro FERAIT — sans rien écrire.
     *
     * @return array{vider: array<string,int>, aplomb: array<int,string>, aRessaisir: array<int,string>}
     */
    public static function plan(): array
    {
        $existantes = self::tables();

        $vider = [];
        foreach (self::MOUVEMENTS as $table) {
            if (in_array($table, $existantes, true)) {
                $vider[$table] = DB::table($table)->count();
            }
        }

        return [
            'vider'      => array_filter($vider),
            'aplomb'     => self::descriptionAplomb(),
            'aRessaisir' => self::aRessaisir(),
        ];
    }

    /**
     * Exécute la remise à zéro. Irréversible : l'appelant a déjà pris la
     * sauvegarde et recueilli la confirmation.
     *
     * @return array<string, int> lignes supprimées par table
     */
    public static function executer(): array
    {
        $existantes = self::tables();

        return DB::transaction(function () use ($existantes) {
            // Les jours à rendre se lisent AVANT que les congés ne disparaissent.
            $joursARendre = Schema::hasColumn('employees', 'annual_leave_balance')
                ? DB::table('employee_leaves')
                    ->where('type', 'conge_annuel')
                    ->whereIn('status', self::STATUTS_CONGE_PRELEVE)
                    ->groupBy('employee_id')
                    ->selectRaw('employee_id, SUM(days_count) as jours')
                    ->pluck('jours', 'employee_id')
                : collect();

            $supprimees = [];

            Schema::disableForeignKeyConstraints();
            try {
                foreach (self::MOUVEMENTS as $table) {
                    if (in_array($table, $existantes, true)) {
                        $supprimees[$table] = DB::table($table)->delete();
                    }
                }
            } finally {
                Schema::enableForeignKeyConstraints();
            }

            self::remettreDAplomb($joursARendre);

            return array_filter($supprimees);
        });
    }

    /**
     * Les valeurs des référentiels qui découlaient des mouvements effacés.
     *
     * Chaque règle vient d'une lecture du code qui écrit la valeur ; aucune
     * n'invente un état. Ce qui ne peut pas se déduire — le niveau réel d'une
     * cuve, le compteur d'heures d'un groupe — n'est PAS touché : c'est
     * signalé à ressaisir (`aRessaisir`).
     */
    private static function remettreDAplomb(\Illuminate\Support\Collection $joursARendre): void
    {
        // Stocks : sans mouvements, aucune quantité n'est expliquée. L'inventaire
        // d'ouverture se saisit après. Le prix de référence reste.
        DB::table('stocks')->update(['current_quantity' => 0]);
        DB::table('raw_materials')->update(['stock_qty' => 0]);

        // Clients : le solde est la somme des ventes moins les encaissements.
        DB::table('clients')->update(['balance' => 0]);

        // Trésorerie : sans écritures, chaque compte revient à son ouverture.
        DB::table('treasury_accounts')->update(['current_balance' => DB::raw('opening_balance')]);

        // Bâtiments : plus aucun lot ne les occupe ; la maintenance, décidée par
        // un humain, reste.
        DB::table('buildings')
            ->whereIn('status', ['Occupé', 'En désinfection'])
            ->update(['status' => 'Vide']);
        if (Schema::hasColumn('buildings', 'disinfection_started_at')) {
            DB::table('buildings')->update(['disinfection_started_at' => null]);
        }

        // Parcelles et couveuses : leurs cycles et incubations sont partis.
        DB::table('plots')->where('status', 'en_culture')->update(['status' => 'disponible']);
        DB::table('incubators')->where('status', 'Occupé')->update(['status' => 'Disponible']);

        // Employés : les jours prélevés par les congés de test sont RENDUS, au
        // jour près ; un statut « Congé » venu d'un congé effacé redevient actif.
        foreach ($joursARendre as $employeId => $jours) {
            DB::table('employees')->where('id', $employeId)
                ->increment('annual_leave_balance', (float) $jours);
        }
        DB::table('employees')->where('status', 'Congé')->update(['status' => 'Actif']);
    }

    /** @return array<int, string> */
    private static function descriptionAplomb(): array
    {
        return [
            'Quantités en stock (articles et matières premières) remises à 0 — inventaire d’ouverture à saisir',
            'Soldes clients remis à 0',
            'Comptes de trésorerie ramenés à leur solde d’ouverture',
            'Bâtiments « Occupé » ou « En désinfection » repassés à « Vide »',
            'Parcelles « en culture » et couveuses « Occupé » libérées',
            'Jours de congé prélevés par les congés effacés rendus aux employés ; statut « Congé » repassé à « Actif »',
            'Numérotation des documents : repart de 1 (factures, BL, dépenses…), puisqu’elle suit le dernier document existant',
        ];
    }

    /** @return array<int, string> */
    private static function aRessaisir(): array
    {
        return [
            'Niveau réel des cuves de gasoil et des citernes (energy_sources, water_sources)',
            'Compteur d’heures des groupes électrogènes et des machines du moulin',
            'Inventaire d’ouverture des stocks et des matières premières',
            'Soldes d’ouverture des comptes de trésorerie, s’ils ne sont pas déjà justes',
        ];
    }
}
