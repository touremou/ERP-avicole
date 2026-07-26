<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T2 — Sécuriser le stock de SPÉCULATION.
 *
 * T1 a rendu honnête la comptabilité du gombo séché : plus de revenu fictif, un
 * coût de revient porté au stock. Restait le risque physique et le risque de
 * dérive, qui sont les deux vraies façons de perdre l'argent qu'on espérait
 * gagner en attendant :
 *
 *  1. LA MATIÈRE SE DÉGRADE. Du gombo séché stocké quatre mois reprend
 *     l'humidité, attire les insectes, moisit. Sans pesée périodique, la perte
 *     se découvre le jour de la vente — trop tard pour agir.
 *  2. « PLUS TARD » DÉRIVE. Sans prix-cible enregistré ni date butoir, on garde
 *     le stock par inertie et il finit au rebut.
 *
 * D'où deux tables :
 *
 *  - stored_lots : la DÉCISION de conserver. Prix-cible, échéance maximale,
 *    fréquence de contrôle, coût de revient figé à l'ouverture. C'est un acte de
 *    gestion, jamais créé automatiquement.
 *  - stored_lot_checks : le CONTRÔLE périodique. Pesée réelle (d'où la freinte),
 *    état sanitaire, action décidée — et le PRIX DU MARCHÉ observé sur place.
 *
 * Ce dernier point est le pivot : on n'a pas de flux de cotation. C'est la
 * personne qui va au magasin ou au marché qui relève le cours. Le prix-cible
 * devient alors exploitable — le système peut dire « objectif atteint, vendez »
 * sur une donnée constatée, plutôt que d'attendre une source qui n'existe pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_lots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();

            // Origine : une récolte mise de côté, ou un lot transformé (séchage).
            // Les deux sont nullables — on peut conserver un stock d'origine mixte.
            $table->foreignId('harvest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('crop_transformation_id')->nullable()->constrained()->nullOnDelete();
            // Ligne d'inventaire suivie : c'est elle qui porte la quantité réelle.
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();

            $table->string('label');
            $table->decimal('quantity_initial', 14, 3);
            $table->decimal('quantity_current', 14, 3);
            $table->string('unit', 20)->default('kg');

            // Coût de revient FIGÉ à l'ouverture : le CMP du stock bougera avec
            // les entrées suivantes, alors que la rentabilité de CETTE décision
            // se juge au coût de la matière engagée ce jour-là.
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->decimal('target_unit_price', 14, 2)->nullable();
            $table->decimal('last_market_price', 14, 2)->nullable();

            $table->date('opened_at');
            // Date butoir de détention : au-delà, on vend ou on déclasse.
            $table->date('hold_until')->nullable();
            $table->unsignedSmallInteger('check_interval_days')->default(14);

            $table->string('status', 20)->default('en_stock'); // en_stock | vendu | perte_totale | cloture
            $table->date('closed_at')->nullable();
            $table->string('closed_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['farm_id', 'status']);
            $table->index('hold_until');
        });

        Schema::create('stored_lot_checks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stored_lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('checked_at');
            // Pesée réelle du lot. La freinte s'en déduit (précédent − pesée) :
            // on saisit ce qu'on mesure, jamais l'écart lui-même.
            $table->decimal('weighed_quantity', 14, 3)->nullable();
            $table->decimal('shrinkage_quantity', 14, 3)->default(0);
            $table->string('condition', 20)->default('bon');
            $table->string('action_taken', 30)->default('aucune');
            // Cours constaté au marché le jour du contrôle (cf. docblock).
            $table->decimal('market_price', 14, 2)->nullable();
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['stored_lot_id', 'checked_at']);
        });

        // Un contrôle échu devient une TÂCHE du calendrier (même discipline que
        // les étapes d'itinéraire en S1) : sans cela, la consigne de contrôle
        // périodique n'existe nulle part et n'est comptée dans aucun indicateur.
        if (Schema::hasTable('task_assignments') && ! Schema::hasColumn('task_assignments', 'stored_lot_id')) {
            Schema::table('task_assignments', function (Blueprint $table) {
                $table->foreignId('stored_lot_id')->nullable()->after('crop_protocol_item_id')
                    ->constrained('stored_lots')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('task_assignments', 'stored_lot_id')) {
            Schema::table('task_assignments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('stored_lot_id');
            });
        }

        Schema::dropIfExists('stored_lot_checks');
        Schema::dropIfExists('stored_lots');
    }
};
