<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROTOCOLES DE TRAITEMENT / ITINÉRAIRES TECHNIQUES (PHASE 2).
 *
 * Pendant végétal des protocoles de prophylaxie d'élevage (Protocol /
 * ProtocolStep) : un standard agronomique partagé (non multi-ferme) qui décrit,
 * par culture et zone agro-écologique, les interventions échelonnées en jours
 * après semis (DAP). Sert de calendrier de référence rattachable à un cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_protocols', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->boolean('is_synced')->default(true);
            $table->timestamp('last_sync_at')->nullable();

            $table->string('crop_name')->nullable();  // cible (CropSpecies.name / CropCycle.crop_name) ; null = générique
            $table->string('agro_zone')->nullable();   // une des 4 zones, ou null = toutes zones
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('source')->nullable();      // ex. "IRAG", "FAO"
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['crop_name', 'is_active']);
        });

        Schema::create('crop_protocol_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crop_protocol_id')->constrained()->cascadeOnDelete();
            $table->integer('day_number');             // jours après semis (DAP)
            $table->string('stage')->nullable();       // ex. "Levée", "Tallage", "Floraison"
            $table->string('action_name');
            $table->string('type');                    // semis, fertilisation, sarclage, traitement, irrigation, observation, recolte, autre
            $table->string('product_suggested')->nullable();
            $table->string('dose')->nullable();        // ex. "200 kg/ha", "2 l/ha"
            $table->string('method')->nullable();      // ex. "épandage", "pulvérisation foliaire"
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('crop_protocol_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_protocol_items');
        Schema::dropIfExists('crop_protocols');
    }
};
