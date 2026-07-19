<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verrouillage de tâche (anti-doublon) — modèle OPTIMISTE, adapté à l'offline.
 *
 * Deux ouvriers pouvaient exécuter la même tâche. On introduit une « prise »
 * (claim) : ouvrir une tâche pour l'exécuter pose status=en_cours + started_at
 * + claimed_by. La tâche est alors verrouillée pour les autres (grisée). Un
 * verrou temps-réel étant impossible entre appareils hors-ligne, la résolution
 * se fait à la SYNCHRO (le 2ᵉ tombe en « déjà prise »), et une prise trop
 * ancienne (timeout) est automatiquement libérée.
 *
 * `started_at` existe déjà (colonne créée mais jamais utilisée) — on ajoute
 * seulement QUI a pris la tâche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_assignments', function (Blueprint $t) {
            $t->foreignId('claimed_by')->nullable()->after('started_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_assignments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('claimed_by');
        });
    }
};
