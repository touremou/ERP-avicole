<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cœur sanitaire HACCP du module Abattoir/Transformation (spec BioCrest,
 * évolutions E1/E3/E4/E7) — FUSIONNÉ avec l'existant (hypothèse H1 levée) :
 * pas de schéma tr_* parallèle, on greffe sur slaughter_orders.
 *
 *  - slaughter_receptions : réception du vif (CCP 1) — contrôle ante-mortem,
 *    pesée, décision. Une réception refusée ne donne jamais d'ordre (RG-04).
 *  - ccp_records : relevés CCP 1-4, INSERT-ONLY (RG-06) — la correction est
 *    une écriture d'annulation (corrects_record_id), jamais un update.
 *  - temperature_logs : registre des températures (E4), indépendant des lots.
 *  - cleaning_logs : registre nettoyage/désinfection (E7).
 *  - slaughter_orders : + reception_id et champs de blocage qualité (RG-02).
 *
 * Double horodatage partout : releve_at (heure réelle du geste, horloge
 * client — saisie possible hors-ligne) ET synced_at (heure d'arrivée
 * serveur). L'écart est une information légitime pour un inspecteur, pas
 * un défaut à masquer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slaughter_receptions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable()->unique();     // généré CLIENT (idempotence sync)
            $t->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();
            $t->foreignId('provider_id')->constrained('providers'); // l'éleveur livreur
            $t->date('reception_date');
            $t->timestamp('arrived_at')->nullable();
            $t->unsignedInteger('announced_quantity')->nullable();
            $t->unsignedInteger('received_quantity');
            $t->unsignedInteger('rejected_quantity')->default(0);   // écartés ante-mortem
            $t->decimal('total_live_weight_kg', 8, 2);
            $t->string('sanitary_state', 20);           // conforme | reserves | non_conforme
            $t->string('fasting_respected', 20);        // oui | non | partielle (diète 8-12 h)
            $t->string('decision', 30);                 // accepte | accepte_avec_decote | refuse
            $t->text('decision_reason')->nullable();    // OBLIGATOIRE si décision ≠ accepte
            $t->string('doc_photo_path')->nullable();   // photo du certificat sanitaire
            $t->foreignId('controller_id')->constrained('users');
            $t->timestamp('releve_at')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamp('validated_at')->nullable();  // posé à la création → immuable
            $t->boolean('is_synced')->default(false);
            $t->timestamp('last_sync_at')->nullable();
            $t->timestamps();
            $t->index(['reception_date', 'provider_id']);
        });

        Schema::create('ccp_records', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable()->unique();
            $t->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();
            $t->string('ccp', 30);                      // ccp1_reception | ccp2_evisceration |
                                                        // ccp3_refroidissement | ccp4_chaine_froid
            $t->foreignId('slaughter_order_id')->nullable()->constrained('slaughter_orders');
            $t->string('equipment_ref', 50)->nullable(); // "CF-01", "Camion-A" (CCP 4)
            $t->json('mesures');                        // {"temperature_coeur": 3.4} …
            $t->boolean('conforme');                    // calculé SERVEUR selon les seuils Settings
            $t->text('corrective_action')->nullable();  // OBLIGATOIRE si non conforme
            $t->foreignId('operator_id')->constrained('users');
            $t->timestamp('releve_at')->nullable();      // heure client ; nullable = pas de ON UPDATE CURRENT_TIMESTAMP implicite
            $t->timestamp('synced_at')->nullable();
            // Correction = écriture d'annulation, l'historique reste intact.
            $t->foreignId('corrected_by_id')->nullable()->constrained('users');
            $t->foreignId('corrects_record_id')->nullable()->constrained('ccp_records');
            $t->timestamps();
            $t->index(['ccp', 'releve_at']);
        });

        Schema::create('temperature_logs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable()->unique();
            $t->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();
            $t->string('point', 40);                    // chambre_froide_positive | congelation |
                                                        // salle_decoupe | echaudage | vehicule
            $t->string('equipment_ref', 50)->nullable();
            $t->decimal('temperature', 5, 1);
            $t->boolean('conforme');                    // calculé SERVEUR (seuils Settings)
            $t->text('corrective_action')->nullable();
            $t->foreignId('operator_id')->constrained('users');
            $t->timestamp('releve_at')->nullable();      // heure client ; nullable = pas de ON UPDATE CURRENT_TIMESTAMP implicite
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->index(['point', 'releve_at']);
        });

        Schema::create('cleaning_logs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable()->unique();
            $t->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();
            $t->string('zone', 100);                    // surfaces, sols, couteaux, CF, véhicule…
            $t->string('product_used', 100);            // produit agréé contact alimentaire
            $t->string('dosage', 50)->nullable();
            $t->text('notes')->nullable();
            $t->string('photo_path')->nullable();
            $t->foreignId('operator_id')->constrained('users');
            $t->timestamp('done_at')->nullable();        // heure réelle (client) ; nullable = pas de ON UPDATE implicite
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->index(['zone', 'done_at']);
        });

        // L'ENUM d'origine ('planifie','en_cours','termine','annule') devient
        // un varchar pour accueillir le statut 'bloque' (RG-02) sans ALTER
        // d'enum spécifique MySQL.
        Schema::table('slaughter_orders', function (Blueprint $t) {
            $t->string('status', 20)->default('planifie')->change();
        });

        Schema::table('slaughter_orders', function (Blueprint $t) {
            $t->foreignId('reception_id')->nullable()->after('batch_id')
                ->constrained('slaughter_receptions')->nullOnDelete();
            // Blocage qualité (RG-02) : posé automatiquement par un CCP non
            // conforme ou manuellement (abattoir.M) ; libération = abattoir.S.
            $t->text('blocked_reason')->nullable();
            $t->foreignId('blocked_by_id')->nullable()->constrained('users');
            $t->timestamp('blocked_at')->nullable();
            $t->text('release_reason')->nullable();
            $t->foreignId('released_by_id')->nullable()->constrained('users');
            $t->timestamp('released_at')->nullable();
        });

        // ── Seuils HACCP paramétrables (groupe Réglages « abattoir ») ──
        // ⚠ Valeurs indicatives — À FAIRE VALIDER par le vétérinaire conseil.
        $now = now();
        $seuils = [
            ['key' => 'ccp3_core_temp_max',    'value' => '4',   'label' => 'CCP3 — T° à cœur max après refroidissement', 'unit' => '°C', 'display_order' => 20],
            ['key' => 'ccp2_soiled_max_pct',   'value' => '2',   'label' => 'CCP2 — % max de carcasses souillées',        'unit' => '%',  'display_order' => 21],
            ['key' => 'cold_positive_min',     'value' => '0',   'label' => 'Chambre froide positive — T° min',           'unit' => '°C', 'display_order' => 22],
            ['key' => 'cold_positive_max',     'value' => '4',   'label' => 'Chambre froide positive — T° max',           'unit' => '°C', 'display_order' => 23],
            ['key' => 'freezer_max',           'value' => '-18', 'label' => 'Congélation — T° max',                       'unit' => '°C', 'display_order' => 24],
            ['key' => 'cutting_room_max',      'value' => '12',  'label' => 'Salle de découpe — T° max',                  'unit' => '°C', 'display_order' => 25],
            ['key' => 'scalding_min',          'value' => '52',  'label' => 'Échaudage — T° min',                         'unit' => '°C', 'display_order' => 26],
            ['key' => 'scalding_max',          'value' => '58',  'label' => 'Échaudage — T° max',                         'unit' => '°C', 'display_order' => 27],
            ['key' => 'vehicle_max',           'value' => '4',   'label' => 'Véhicule frigorifique — T° max',             'unit' => '°C', 'display_order' => 28],
            ['key' => 'temp_readings_per_day', 'value' => '2',   'label' => 'Relevés de température requis / jour',       'unit' => '',   'display_order' => 29],
        ];

        foreach ($seuils as $s) {
            DB::table('settings')->updateOrInsert(
                ['group' => 'abattoir', 'key' => $s['key'], 'farm_id' => null],
                array_merge($s, [
                    'group'        => 'abattoir',
                    'type'         => 'number',
                    'is_sensitive' => false,
                    'description'  => 'Seuil HACCP — à valider par le vétérinaire conseil',
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ])
            );
        }
    }

    public function down(): void
    {
        Schema::table('slaughter_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reception_id');
            $t->dropConstrainedForeignId('blocked_by_id');
            $t->dropConstrainedForeignId('released_by_id');
            $t->dropColumn(['blocked_reason', 'blocked_at', 'release_reason', 'released_at']);
        });
        Schema::dropIfExists('cleaning_logs');
        Schema::dropIfExists('temperature_logs');
        Schema::dropIfExists('ccp_records');
        Schema::dropIfExists('slaughter_receptions');
        DB::table('settings')->where('group', 'abattoir')
            ->whereIn('key', ['ccp3_core_temp_max','ccp2_soiled_max_pct','cold_positive_min','cold_positive_max',
                              'freezer_max','cutting_room_max','scalding_min','scalding_max','vehicle_max','temp_readings_per_day'])
            ->delete();
    }
};
