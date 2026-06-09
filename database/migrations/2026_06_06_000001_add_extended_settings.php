<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) return;

        $now = now();

        $settings = [
            // ─── GÉNÉRAL (compléments) ───
            ['group' => 'general', 'key' => 'company_logo',  'value' => '',       'type' => 'string',   'label' => 'Chemin du logo',              'unit' => 'storage/...', 'display_order' => 9, 'description' => 'Ex: logos/avismart.png (dans storage/app/public)'],
            ['group' => 'general', 'key' => 'date_format',    'value' => 'd/m/Y', 'type' => 'select',   'label' => 'Format de date',              'options' => 'd/m/Y,Y-m-d,m/d/Y,d-m-Y', 'display_order' => 10],
            ['group' => 'general', 'key' => 'items_per_page', 'value' => '20',    'type' => 'number',   'label' => 'Éléments par page',           'unit' => 'lignes', 'display_order' => 11],
            ['group' => 'general', 'key' => 'country',        'value' => 'Guinée','type' => 'string',   'label' => 'Pays',                        'display_order' => 12],
            ['group' => 'general', 'key' => 'fiscal_id',      'value' => '',      'type' => 'string',   'label' => 'N° Identification Fiscale',   'display_order' => 13, 'description' => 'NIF affiché sur les documents imprimés'],
            ['group' => 'general', 'key' => 'rccm',           'value' => '',      'type' => 'string',   'label' => 'N° RCCM',                    'display_order' => 14],

            // ─── ÉLEVAGE (préfixes lots) ───
            ['group' => 'elevage', 'key' => 'batch_prefix_chair',       'value' => 'LOT',  'type' => 'string', 'label' => 'Préfixe code lot chair',        'display_order' => 11],
            ['group' => 'elevage', 'key' => 'batch_prefix_ponte',       'value' => 'LOT',  'type' => 'string', 'label' => 'Préfixe code lot ponte',        'display_order' => 12],
            ['group' => 'elevage', 'key' => 'batch_prefix_poussiniere', 'value' => 'POUS', 'type' => 'string', 'label' => 'Préfixe code lot poussinière',  'display_order' => 13],
            ['group' => 'elevage', 'key' => 'batch_prefix_repro',       'value' => 'REP',  'type' => 'string', 'label' => 'Préfixe code lot reproducteur', 'display_order' => 14],

            // ─── PRODUCTION (compléments) ───
            ['group' => 'production', 'key' => 'egg_grades',      'value' => 'S,M,L,XL', 'type' => 'string', 'label' => 'Calibres œufs (séparés par ,)', 'display_order' => 4],
            ['group' => 'production', 'key' => 'peak_laying_week', 'value' => '28',       'type' => 'number', 'label' => 'Semaine pic de ponte',          'unit' => 'semaines', 'display_order' => 5],

            // ─── RH & PAIE (nouveau groupe) ───
            ['group' => 'rh', 'key' => 'annual_leave_days',  'value' => '30',       'type' => 'number',   'label' => 'Congés annuels par défaut',       'unit' => 'jours',    'display_order' => 1],
            ['group' => 'rh', 'key' => 'rest_day',            'value' => 'dimanche', 'type' => 'select',   'label' => 'Jour de repos hebdomadaire',      'options' => 'dimanche,samedi,vendredi,aucun', 'display_order' => 2],
            ['group' => 'rh', 'key' => 'overtime_rate',        'value' => '1.5',      'type' => 'number',   'label' => 'Taux heures supplémentaires',     'unit' => '×',        'display_order' => 3],
            ['group' => 'rh', 'key' => 'payment_methods',     'value' => 'especes,orange_money,virement', 'type' => 'string', 'label' => 'Méthodes de paiement (séparées par ,)', 'display_order' => 4],
            ['group' => 'rh', 'key' => 'payslip_footer',      'value' => 'Document généré par AviSmart ERP', 'type' => 'textarea', 'label' => 'Pied de page fiche de paie', 'display_order' => 5],

            // ─── STOCKS (nouveau groupe) ───
            ['group' => 'stocks', 'key' => 'default_alert_threshold', 'value' => '10',  'type' => 'number', 'label' => 'Seuil alerte stock par défaut', 'unit' => 'unités', 'display_order' => 1],
            ['group' => 'stocks', 'key' => 'categories', 'value' => 'oeufs,aliment,medicament,materiel,produits_finis', 'type' => 'string', 'label' => 'Catégories de stock', 'display_order' => 2],

            // ─── VENTES (nouveau groupe) ───
            ['group' => 'ventes', 'key' => 'invoice_prefix_bl',   'value' => 'BL', 'type' => 'string', 'label' => 'Préfixe bon de livraison',      'display_order' => 1],
            ['group' => 'ventes', 'key' => 'invoice_prefix_tva',  'value' => 'FA', 'type' => 'string', 'label' => 'Préfixe facture TVA',            'display_order' => 2],
            ['group' => 'ventes', 'key' => 'payment_delay_days',  'value' => '30', 'type' => 'number', 'label' => 'Délai paiement par défaut',      'unit' => 'jours',  'display_order' => 3],
            ['group' => 'ventes', 'key' => 'credit_limit_default','value' => '5000000', 'type' => 'number', 'label' => 'Plafond crédit client',     'unit' => 'GNF',    'display_order' => 4],
            ['group' => 'ventes', 'key' => 'invoice_footer',      'value' => 'Merci pour votre confiance.', 'type' => 'textarea', 'label' => 'Pied de page facture', 'display_order' => 5],
        ];

        foreach ($settings as $s) {
            // Ne pas écraser si le paramètre existe déjà
            $exists = DB::table('settings')
                ->where('group', $s['group'])
                ->where('key', $s['key'])
                ->whereNull('farm_id')
                ->exists();

            if (! $exists) {
                DB::table('settings')->insert(array_merge([
                    'options'      => $s['options'] ?? null,
                    'description'  => $s['description'] ?? null,
                    'is_sensitive' => false,
                    'farm_id'      => null,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ], $s));
            }
        }
    }

    public function down(): void
    {
        $keys = [
            'general' => ['company_logo','date_format','items_per_page','country','fiscal_id','rccm'],
            'elevage' => ['batch_prefix_chair','batch_prefix_ponte','batch_prefix_poussiniere','batch_prefix_repro'],
            'production' => ['egg_grades','peak_laying_week'],
        ];

        foreach ($keys as $group => $groupKeys) {
            DB::table('settings')->where('group', $group)->whereIn('key', $groupKeys)->delete();
        }

        DB::table('settings')->whereIn('group', ['rh', 'stocks', 'ventes'])->delete();
    }
};
