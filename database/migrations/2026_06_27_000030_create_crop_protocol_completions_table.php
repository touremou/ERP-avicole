<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Validation explicite des étapes d'un itinéraire technique appliqué à un cycle.
 *
 * Jusqu'ici, une étape de protocole n'était réputée « faite » que par INFÉRENCE
 * (un intrant/événement saisi dont le nom recoupe l'action, ou une récolte pour
 * l'étape « récolte »). Cette heuristique reste utile mais ne permet pas de
 * VALIDER manuellement une étape (sarclage, observation, irrigation… sans
 * intrant associé), ce qui bloquait le suivi de l'itinéraire.
 *
 * On matérialise donc une validation explicite par (cycle × étape), horodatée et
 * tracée par utilisateur. Le moteur de calendrier (CropProtocolAlertService) la
 * traite en priorité ; l'inférence par nom devient un simple repli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_protocol_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();
            $table->foreignId('crop_cycle_id')->constrained('crop_cycles')->cascadeOnDelete();
            $table->foreignId('crop_protocol_item_id')->constrained('crop_protocol_items')->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            // Une étape n'est validée qu'une seule fois par cycle.
            $table->unique(['crop_cycle_id', 'crop_protocol_item_id'], 'crop_step_completion_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_protocol_completions');
    }
};
