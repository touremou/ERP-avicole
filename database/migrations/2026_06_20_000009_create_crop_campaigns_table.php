<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAMPAGNES AGRICOLES (module: cultures).
 *
 * Une campagne regroupe les cycles de culture d'une même saison culturale
 * (saisons guinéennes : grande saison des pluies, petite saison, saison sèche)
 * pour piloter objectifs vs réalisé à l'échelle de l'exploitation. Un cycle de
 * culture peut être rattaché à une campagne (campaign_id nullable).
 *
 * À ne pas confondre avec le `Campaign` de l'élevage (bandes/lots animaux) :
 * domaine distinct, table distincte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_campaigns', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();
            $table->boolean('is_synced')->default(true);
            $table->timestamp('last_sync_at')->nullable();

            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();

            $table->string('code')->nullable();                 // CAM-2026-GSP
            $table->string('name');                             // Grande saison pluies 2026
            $table->unsignedSmallInteger('year');
            $table->string('season')->default('grande_saison_pluies'); // cf. CropCampaign::SEASONS
            $table->date('start_date');
            $table->date('end_date_planned')->nullable();
            $table->decimal('target_production_t', 12, 2)->nullable(); // objectif (tonnes)
            $table->string('status')->default('planifiee');     // cf. CropCampaign::STATUS_*
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['farm_id', 'year', 'status']);
        });

        // Rattachement d'un cycle de culture à une campagne.
        Schema::table('crop_cycles', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('plot_id')
                ->constrained('crop_campaigns')->nullOnDelete();
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::table('crop_cycles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });

        Schema::dropIfExists('crop_campaigns');
    }
};
