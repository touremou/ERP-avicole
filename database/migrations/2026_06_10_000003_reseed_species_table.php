<?php

use Illuminate\Database\Migrations\Migration;

/**
 * SpeciesSeeder n'est appelé que depuis DatabaseSeeder::run(), qui plante
 * avant de l'atteindre (références à des classes sans `use`). Sur une
 * installation fraîche, les tables `species`/`production_types` restent
 * donc vides et la page Admin > Espèces est vide.
 *
 * SpeciesSeeder est idempotent (Species::updateOrCreate / ProductionType::
 * updateOrCreate par slug), on peut donc le rejouer ici sans risque, y
 * compris sur une base déjà peuplée.
 */
return new class extends Migration {
    public function up(): void
    {
        (new \Database\Seeders\SpeciesSeeder())->run();
    }

    public function down(): void
    {
        // Pas de rollback : suppression des espèces potentiellement utilisées par des lots.
    }
};
