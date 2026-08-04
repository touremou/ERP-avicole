<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'unique « un relevé d'eau par jour » n'a JAMAIS été levée en production.
 *
 * Trouvé en rejouant la suite de tests sur MySQL 8.
 *
 * La migration du 18 juillet devait lever `water_reading_unique_per_day` pour
 * qu'un ravitaillement puisse coexister avec le relevé de consommation du jour.
 * Elle enveloppait le DROP dans un try/catch commenté « déjà absente ».
 *
 * Sur MySQL, cet index est le SEUL à couvrir `water_source_id`, colonne d'une
 * clé étrangère : le moteur refuse de le supprimer (« needed in a foreign key
 * constraint »). L'exception était avalée, la migration se déclarait réussie,
 * et l'index restait en place. Le défaut qu'elle prétendait corriger — 500 à
 * l'enregistrement d'un appoint un jour déjà relevé — n'a donc jamais quitté la
 * production. Il ne disparaissait que sur sqlite, où le DROP passe.
 *
 * On crée d'abord un index simple sur `water_source_id` pour que la clé
 * étrangère reste couverte, PUIS on lève l'unique. Et sans try/catch : une
 * migration qui échoue doit le dire.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('water_readings')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('water_readings'));

        if (! $indexes->contains(fn ($i) => $i['name'] === 'water_reading_unique_per_day')) {
            return;   // déjà levée (base recréée depuis) : rien à faire
        }

        // Couvrir la clé étrangère AVANT de retirer l'index qui la porte.
        if (! $indexes->contains(fn ($i) => $i['name'] === 'water_readings_water_source_id_index')) {
            Schema::table('water_readings', function (Blueprint $table) {
                $table->index('water_source_id', 'water_readings_water_source_id_index');
            });
        }

        Schema::table('water_readings', function (Blueprint $table) {
            $table->dropUnique('water_reading_unique_per_day');
        });
    }

    public function down(): void
    {
        // Pas de retour en arrière : la base contient désormais légitimement
        // plusieurs relevés par source et par jour ; recréer l'unique échouerait
        // sur des données réelles.
    }
};
