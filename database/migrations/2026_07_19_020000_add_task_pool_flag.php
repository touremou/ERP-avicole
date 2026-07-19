<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tâches en LIBRE-SERVICE (pool partagé).
 *
 * Jusqu'ici chaque tâche était assignée à UN employé. On introduit le pool :
 * une tâche `is_pool` n'a pas de titulaire — elle est visible par tous les
 * ouvriers de la ferme, et le PREMIER qui la « prend » se l'attribue (le verrou
 * anti-doublon empêche qu'un autre la prenne ou la termine). La libérer la
 * renvoie au pool.
 *
 * Le drapeau vit sur le TEMPLATE (config du superviseur) et est copié sur
 * l'ASSIGNATION (dénormalisé → disponible hors-ligne).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_templates', function (Blueprint $t) {
            $t->boolean('is_pool')->default(false)->after('priority');
        });
        Schema::table('task_assignments', function (Blueprint $t) {
            $t->boolean('is_pool')->default(false)->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('task_templates', function (Blueprint $t) {
            $t->dropColumn('is_pool');
        });
        Schema::table('task_assignments', function (Blueprint $t) {
            $t->dropColumn('is_pool');
        });
    }
};
