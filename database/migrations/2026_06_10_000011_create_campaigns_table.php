<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campagnes saisonnières (Tabaski/Eid, Ramadan, fêtes...).
 *
 * Contexte Afrique de l'Ouest : à la Tabaski, la demande de moutons est
 * multipliée par ~10. La marge se joue sur une campagne : achat groupé
 * (août), engraissement 60–90 j, vente groupée au pic. Une campagne
 * agrège plusieurs lots vers un objectif (têtes, budget, prix de vente)
 * et suit la marge projetée vs réalisée + un compte à rebours.
 *
 * Générique (type) pour rester réutilisable au-delà de la Tabaski.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('type', 30)->default('tabaski');          // tabaski|ramadan|fetes|autre
            $table->string('target_family', 30)->default('petit_ruminant');
            $table->string('status', 20)->default('preparation');     // preparation|engraissement|vente|cloturee

            $table->date('start_date')->nullable();
            $table->date('target_date');                              // date pic / fête

            $table->unsignedInteger('target_head_count')->nullable(); // objectif têtes
            $table->decimal('target_budget', 16, 2)->nullable();      // budget achat GNF
            $table->decimal('target_sale_price', 14, 2)->nullable();  // prix de vente cible / tête

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'target_date']);
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('parent_batch_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });
        Schema::dropIfExists('campaigns');
    }
};
