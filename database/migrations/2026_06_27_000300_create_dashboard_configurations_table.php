<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Liste des clés de blocs MASQUÉS par l'utilisateur (tout est visible
            // par défaut). Approche par liste de masquage : ajouter un nouveau bloc
            // au tableau de bord ne nécessite aucune migration ni rétro-remplissage.
            $table->json('hidden_blocks')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_configurations');
    }
};
