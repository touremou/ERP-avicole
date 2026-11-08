<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TAXONOMIE ANIMALE — les souches et races, paramétrées par l'administrateur.
 *
 * Demande de l'exploitation : impossible d'ajouter des moutons « locaux » ou
 * une souche de volaille non prévue. La souche d'un lot (batches.model_name)
 * était un texte choisi dans les seules COURBES de référence
 * (production_norms) : une race sans courbe de croissance — Djallonké, lapin
 * local — ne pouvait pas être choisie, et le champ était obligatoire.
 *
 *   • animal_breeds : les souches / races, rattachées à une espèce (la table
 *     `species` existante tient lieu de référentiel des espèces) ;
 *   • batches.breed_id : la souche du lot. `model_name` est CONSERVÉ et
 *     recopié depuis la souche : les courbes de croissance (ProductionNorm)
 *     s'y rattachent par ce nom.
 *
 * Rattrapage : chaque souche déjà connue (courbes, lots existants) devient une
 * souche du référentiel, et les lots existants y sont rattachés.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('animal_breeds')) {
            Schema::create('animal_breeds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('species_id')->constrained('species')->cascadeOnDelete();
                $table->string('name', 100);
                $table->string('description', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['species_id', 'name']);
            });
        }

        if (! Schema::hasColumn('batches', 'breed_id')) {
            Schema::table('batches', function (Blueprint $table) {
                $table->foreignId('breed_id')->nullable()->after('model_name')
                    ->constrained('animal_breeds')->nullOnDelete();
            });
        }

        $now = now();
        // Les souches des lots existants (celles des courbes : par le semeur).
        $connues = DB::table('batches')->whereNotNull('species_id')
            ->whereNotNull('model_name')->where('model_name', '!=', 'Non spécifié')
            ->select('species_id', 'model_name')->distinct()->get();

        foreach ($connues as $souche) {
            $nom = trim((string) $souche->model_name);
            if ($nom === '') {
                continue;
            }
            DB::table('animal_breeds')->insertOrIgnore([
                'species_id' => $souche->species_id, 'name' => mb_substr($nom, 0, 100),
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Souches des courbes de référence + races locales courantes.
        (new \Database\Seeders\AnimalBreedSeeder())->run();

        foreach (DB::table('animal_breeds')->get(['id', 'species_id', 'name']) as $souche) {
            DB::table('batches')->whereNull('breed_id')
                ->where('species_id', $souche->species_id)->where('model_name', $souche->name)
                ->update(['breed_id' => $souche->id]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('batches', 'breed_id')) {
            Schema::table('batches', function (Blueprint $table) {
                $table->dropConstrainedForeignId('breed_id');
            });
        }
        Schema::dropIfExists('animal_breeds');
    }
};
