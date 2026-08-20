<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UNE TABLE ÉCRITE À CHAQUE LOT, LUE PAR PERSONNE.
 *
 * `batch_tasks` était remplie par SanitarySchedulerService à chaque création et
 * à chaque transfert de lot. Rien ne l'affichait : aucun écran, aucune route,
 * aucune API, rien côté mobile. Sa seule autre mention était DependencyGuard,
 * où ces lignes invisibles BLOQUAIENT la suppression d'un lot sous le libellé
 * « tâches de lot » — un motif que personne ne pouvait aller consulter.
 *
 * Elle portait en plus une TROISIÈME façon de calculer l'échéance d'une étape
 * de protocole : `transfer_date ?? arrival_date ?? created_at + day_number`,
 * là où le tableau de bord et l'alerte sanitaire passent tous deux par
 * `Batch::protocolStepDue()` (naissance + day_number, et seulement si l'étape
 * nous incombe). Une même règle, écrite deux fois de deux façons.
 *
 * Les échéances de protocole en retard restent affichées — tableau de bord et
 * alertes sanitaires — depuis cette déclaration unique. On retire donc le
 * doublon muet, pas la fonction.
 *
 * La table est supprimée : ses lignes n'ont jamais été montrées à quiconque, et
 * les laisser continuerait de bloquer des suppressions légitimes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('batch_tasks');
    }

    /**
     * On sait recréer la STRUCTURE ; les lignes, non — elles n'ont jamais été
     * lues, donc rien d'observable n'est perdu.
     */
    public function down(): void
    {
        if (Schema::hasTable('batch_tasks')) {
            return;
        }

        Schema::create('batch_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->onDelete('cascade');
            $table->string('action_name');
            $table->string('type')->default('Vaccin');
            $table->string('method')->nullable();
            $table->integer('day_number')->default(0);
            $table->date('planned_date')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->timestamps();
        });
    }
};
