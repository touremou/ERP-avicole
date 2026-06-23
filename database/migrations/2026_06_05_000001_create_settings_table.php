<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Supprimer l'ancienne table settings (structure simplifiée de mars 2026) si elle existe
        Schema::dropIfExists('setting_audits');
        Schema::dropIfExists('settings');

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 50)->index();           // general, elevage, abattoir...
            $table->string('key', 100);                      // cycle_chair, tva_rate...
            $table->text('value')->nullable();                // La valeur stockée
            $table->string('type', 20)->default('string');   // string, number, boolean, select, password, json, textarea
            $table->string('label')->nullable();              // Libellé affiché dans l'IHM
            $table->text('description')->nullable();          // Aide contextuelle
            $table->string('options')->nullable();            // Pour type=select : "log,callmebot,ultramsg,twilio"
            $table->string('unit', 20)->nullable();           // %, jours, kg, GNF, S/m²
            $table->integer('display_order')->default(0);
            $table->boolean('is_sensitive')->default(false);  // Masquer la valeur (API keys)
            $table->foreignId('farm_id')->nullable();         // Si paramètre spécifique à une ferme
            $table->timestamps();

            $table->unique(['group', 'key', 'farm_id']);
        });

        // Log des modifications
        Schema::create('setting_audits', function (Blueprint $table) {
            $table->id();
            $table->string('group', 50);
            $table->string('key', 100);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // ═══ SEED DES PARAMÈTRES PAR DÉFAUT ═══
        $this->seedDefaults();
    }

    private function seedDefaults(): void
    {
        $now = now();
        $settings = [

            // ─── GÉNÉRAL ───
            ['group' => 'general', 'key' => 'company_name',    'value' => 'AviSmart',           'type' => 'string',   'label' => 'Nom de l\'entreprise',        'unit' => null,     'display_order' => 1],
            ['group' => 'general', 'key' => 'company_address',  'value' => '',                   'type' => 'textarea', 'label' => 'Adresse complète',             'unit' => null,     'display_order' => 2],
            ['group' => 'general', 'key' => 'company_phone',    'value' => '',                   'type' => 'string',   'label' => 'Téléphone principal',          'unit' => null,     'display_order' => 3],
            ['group' => 'general', 'key' => 'currency',         'value' => 'GNF',                'type' => 'string',   'label' => 'Devise',                       'unit' => null,     'display_order' => 4],
            ['group' => 'general', 'key' => 'tva_rate',         'value' => '18',                 'type' => 'number',   'label' => 'Taux de TVA',                  'unit' => '%',      'display_order' => 5],
            ['group' => 'general', 'key' => 'timezone',         'value' => 'Africa/Conakry',     'type' => 'select',   'label' => 'Fuseau horaire',               'unit' => null,     'display_order' => 6, 'options' => 'Africa/Conakry,Africa/Dakar,Africa/Abidjan,Europe/Paris,UTC'],
            ['group' => 'general', 'key' => 'eggs_per_tray',    'value' => '30',                 'type' => 'number',   'label' => 'Œufs par alvéole',             'unit' => 'œufs',   'display_order' => 7],
            ['group' => 'general', 'key' => 'feed_bag_weight',  'value' => '50',                 'type' => 'number',   'label' => 'Poids standard d\'un sac',     'unit' => 'kg',     'display_order' => 8],

            // ─── ÉLEVAGE ───
            ['group' => 'elevage', 'key' => 'cycle_chair',         'value' => '42',    'type' => 'number', 'label' => 'Durée cycle chair',               'unit' => 'jours',   'display_order' => 1],
            ['group' => 'elevage', 'key' => 'cycle_ponte',         'value' => '540',   'type' => 'number', 'label' => 'Durée cycle ponte',               'unit' => 'jours',   'display_order' => 2],
            ['group' => 'elevage', 'key' => 'cycle_reproducteur',  'value' => '450',   'type' => 'number', 'label' => 'Durée cycle reproducteur',        'unit' => 'jours',   'display_order' => 3],
            ['group' => 'elevage', 'key' => 'cycle_poussiniere',   'value' => '90',    'type' => 'number', 'label' => 'Durée cycle poussinière',         'unit' => 'jours',   'display_order' => 4],
            ['group' => 'elevage', 'key' => 'density_max',         'value' => '15',    'type' => 'number', 'label' => 'Densité maximale',                'unit' => 'S/m²',    'display_order' => 5],
            ['group' => 'elevage', 'key' => 'mortality_alert',     'value' => '5',     'type' => 'number', 'label' => 'Seuil alerte mortalité',          'unit' => '%',        'display_order' => 6],
            ['group' => 'elevage', 'key' => 'avg_weight_chair',    'value' => '2.2',   'type' => 'number', 'label' => 'Poids vif moyen chair',           'unit' => 'kg',       'display_order' => 7],
            ['group' => 'elevage', 'key' => 'avg_weight_ponte',    'value' => '1.8',   'type' => 'number', 'label' => 'Poids vif moyen pondeuse',        'unit' => 'kg',       'display_order' => 8],
            ['group' => 'elevage', 'key' => 'mating_ratio_min',    'value' => '8',     'type' => 'number', 'label' => 'Ratio coquage minimum',           'unit' => '%',        'display_order' => 9],
            ['group' => 'elevage', 'key' => 'mating_ratio_max',    'value' => '12',    'type' => 'number', 'label' => 'Ratio coquage maximum',           'unit' => '%',        'display_order' => 10],

            // ─── PRODUCTION ŒUFS ───
            ['group' => 'production', 'key' => 'hdp_target',       'value' => '80',    'type' => 'number', 'label' => 'Objectif HDP ponte',              'unit' => '%',        'display_order' => 1],
            ['group' => 'production', 'key' => 'hdp_alert_low',    'value' => '50',    'type' => 'number', 'label' => 'Seuil alerte HDP bas',            'unit' => '%',        'display_order' => 2],
            ['group' => 'production', 'key' => 'max_passages',     'value' => '4',     'type' => 'number', 'label' => 'Passages collecte max/jour',      'unit' => null,       'display_order' => 3],

            // ─── PROVENDERIE ───
            ['group' => 'provenderie', 'key' => 'fc_target_chair',  'value' => '1.8',  'type' => 'number', 'label' => 'IC cible chair',                  'unit' => 'kg/kg',   'display_order' => 1],
            ['group' => 'provenderie', 'key' => 'fc_target_ponte',  'value' => '2.3',  'type' => 'number', 'label' => 'IC cible ponte',                  'unit' => 'kg/kg',   'display_order' => 2],
            ['group' => 'provenderie', 'key' => 'fc_alert',         'value' => '2.5',  'type' => 'number', 'label' => 'IC seuil alerte',                 'unit' => 'kg/kg',   'display_order' => 3],

            // ─── ABATTOIR ───
            ['group' => 'abattoir', 'key' => 'yield_carcass',       'value' => '72',   'type' => 'number', 'label' => 'Rendement carcasse cible',        'unit' => '%',        'display_order' => 1],
            ['group' => 'abattoir', 'key' => 'yield_cutting',       'value' => '85',   'type' => 'number', 'label' => 'Rendement découpe cible',         'unit' => '%',        'display_order' => 2],
            ['group' => 'abattoir', 'key' => 'yield_smoking',       'value' => '65',   'type' => 'number', 'label' => 'Rendement fumage cible',          'unit' => '%',        'display_order' => 3],
            ['group' => 'abattoir', 'key' => 'tolerance_poultry',   'value' => '0',    'type' => 'number', 'label' => 'Tolérance écart volaille',        'unit' => '%',        'display_order' => 4, 'description' => 'Anti-fraude three-way matching'],
            ['group' => 'abattoir', 'key' => 'tolerance_eggs',      'value' => '2',    'type' => 'number', 'label' => 'Tolérance écart œufs',            'unit' => '%',        'display_order' => 5],
            ['group' => 'abattoir', 'key' => 'tolerance_feed',      'value' => '1',    'type' => 'number', 'label' => 'Tolérance écart aliment',         'unit' => '%',        'display_order' => 6],
            ['group' => 'abattoir', 'key' => 'tolerance_manure',    'value' => '5',    'type' => 'number', 'label' => 'Tolérance écart fumier',          'unit' => '%',        'display_order' => 7],

            // ─── COUVOIR ───
            ['group' => 'couvoir', 'key' => 'incubation_days',      'value' => '21',   'type' => 'number', 'label' => 'Durée d\'incubation',             'unit' => 'jours',   'display_order' => 1],
            ['group' => 'couvoir', 'key' => 'fertility_target',     'value' => '85',   'type' => 'number', 'label' => 'Objectif fertilité',              'unit' => '%',        'display_order' => 2],
            ['group' => 'couvoir', 'key' => 'hatchability_target',  'value' => '80',   'type' => 'number', 'label' => 'Objectif éclosabilité',           'unit' => '%',        'display_order' => 3],
            ['group' => 'couvoir', 'key' => 'mirage_day',           'value' => '10',   'type' => 'number', 'label' => 'Jour du mirage',                  'unit' => 'J',        'display_order' => 4],

            // ─── PLANNING ───
            ['group' => 'planning', 'key' => 'order_lead_days',     'value' => '56',   'type' => 'number', 'label' => 'Délai commande poussins',         'unit' => 'jours',   'display_order' => 1, 'description' => 'J-56 avant arrivée prévue'],
            ['group' => 'planning', 'key' => 'void_sanitaire_days', 'value' => '21',   'type' => 'number', 'label' => 'Durée vide sanitaire',            'unit' => 'jours',   'display_order' => 2],

            // ─── ÉNERGIE ───
            ['group' => 'energie', 'key' => 'fuel_price_liter',     'value' => '12000', 'type' => 'number', 'label' => 'Prix carburant',                  'unit' => 'GNF/L',   'display_order' => 1],
            ['group' => 'energie', 'key' => 'kwh_price_edg',        'value' => '1500',  'type' => 'number', 'label' => 'Prix kWh EDG',                   'unit' => 'GNF/kWh', 'display_order' => 2],
            ['group' => 'energie', 'key' => 'water_price_m3',       'value' => '5000',  'type' => 'number', 'label' => 'Prix eau SEEG',                  'unit' => 'GNF/m³',  'display_order' => 3],
            ['group' => 'energie', 'key' => 'autonomy_alert_hours', 'value' => '24',    'type' => 'number', 'label' => 'Seuil alerte autonomie carburant', 'unit' => 'heures',  'display_order' => 4],
            ['group' => 'energie', 'key' => 'anomaly_threshold_pct', 'value' => '50',   'type' => 'number', 'label' => 'Seuil anomalie conso (écart)',     'unit' => '%',       'display_order' => 5],
            ['group' => 'energie', 'key' => 'ventilation_heat_threshold', 'value' => '36', 'type' => 'number', 'label' => 'Seuil chaleur risque ventilation', 'unit' => '°C',    'display_order' => 6],
            ['group' => 'energie', 'key' => 'ventilation_reliance_hours', 'value' => '5',  'type' => 'number', 'label' => 'Sollicitation groupe (dépendance)', 'unit' => 'h/jour', 'display_order' => 7],

            // ─── NOTIFICATIONS WHATSAPP ───
            ['group' => 'whatsapp', 'key' => 'driver',           'value' => 'log',   'type' => 'select',   'label' => 'Driver WhatsApp',       'options' => 'log,callmebot,ultramsg,wati,twilio', 'display_order' => 1],
            ['group' => 'whatsapp', 'key' => 'api_key',          'value' => '',       'type' => 'password', 'label' => 'Clé API',              'is_sensitive' => true,  'display_order' => 2],
            ['group' => 'whatsapp', 'key' => 'api_url',          'value' => '',       'type' => 'string',   'label' => 'URL API',              'display_order' => 3],
            ['group' => 'whatsapp', 'key' => 'instance_id',      'value' => '',       'type' => 'string',   'label' => 'Instance ID',          'display_order' => 4],
            ['group' => 'whatsapp', 'key' => 'admin_phone',      'value' => '',       'type' => 'string',   'label' => 'Téléphone admin',      'unit' => '+224...',  'display_order' => 5],
            ['group' => 'whatsapp', 'key' => 'daily_summary_hour', 'value' => '07:00', 'type' => 'string', 'label' => 'Heure résumé quotidien', 'unit' => 'HH:MM',  'display_order' => 6],
        ];

        foreach ($settings as $s) {
            DB::table('settings')->insert(array_merge([
                'description'   => $s['description'] ?? null,
                'options'       => $s['options'] ?? null,
                'is_sensitive'  => $s['is_sensitive'] ?? false,
                'farm_id'       => null,
                'created_at'    => $now,
                'updated_at'    => $now,
            ], $s));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_audits');
        Schema::dropIfExists('settings');
    }
};
