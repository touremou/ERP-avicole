<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REGISTRE DES OPÉRATIONS DE SYNCHRO SANS TRACE PROPRE.
 *
 * Chaque opération de la synchro terrain est idempotente par son `uuid`, et
 * chaque gardien va le chercher dans la ligne que l'opération a créée :
 * `Sale::where('uuid', …)`, `StockAdjustment::where('uuid', …)`, etc.
 *
 * Ce procédé a un angle mort : une opération qui réussit SANS créer de ligne
 * n'inscrit son uuid nulle part, et un rejeu la ré-exécute.
 *
 * Le cas mesuré est le COMPTAGE D'INVENTAIRE conforme. « Aucun écart » est un
 * succès métier — le comptage confirme le stock — mais aucun ajustement n'est
 * écrit, donc aucun uuid. Rejouée après qu'un mouvement soit survenu, la même
 * opération trouve alors un écart et l'applique : elle annule ce qui s'est
 * passé entre les deux essais.
 *
 * Cette table donne à ces opérations-là un endroit où exister. Elle ne remplace
 * pas les uuid portés par les documents : elle complète, pour les seuls cas où
 * il n'y a pas de document à porter l'uuid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_operations', function (Blueprint $table) {
            $table->id();

            // Le type d'opération (ex. « inventory_count.create ») ET l'uuid :
            // deux terrains différents peuvent produire le même uuid pour deux
            // opérations de natures différentes sans se gêner.
            $table->string('type', 50);
            $table->uuid('uuid');

            $table->timestamps();

            $table->unique(['type', 'uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_operations');
    }
};
