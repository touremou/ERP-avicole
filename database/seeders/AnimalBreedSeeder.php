<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Races locales courantes en Guinée et en Afrique de l'Ouest, pour que le
 * terrain puisse créer un lot dès l'installation. L'administrateur en ajoute,
 * en renomme ou en désactive ensuite (Administration › Taxonomie animale).
 * Idempotent : une souche déjà présente n'est pas dupliquée.
 */
class AnimalBreedSeeder extends Seeder
{
    public const RACES_LOCALES = [
        'poulet' => ['Poulet local (bicyclette)', 'Poulet croisé'],
        'pintade' => ['Pintade locale'],
        'canard' => ['Canard de Barbarie'],
        'mouton' => ['Djallonké', 'Peul-peul', 'Croisé'],
        'chevre' => ['Djallonké', 'Chèvre du Sahel', 'Croisée'],
        'vache' => ["N'Dama", 'Zébu', 'Croisée'],
        'lapin' => ['Lapin local', 'Néo-Zélandais', 'Californien'],
        'porc' => ['Porc local', 'Large White'],
        'tilapia' => ['Tilapia du Nil'],
    ];

    public function run(): void
    {
        $now = now();

        // Les souches des COURBES de référence (production_norms) : elles
        // restent proposées, et gardent leurs objectifs de croissance.
        $courbes = DB::table('production_norms')->whereNotNull('species_id')
            ->select('species_id', 'model_name')->distinct()->get();
        foreach ($courbes as $courbe) {
            $nom = trim((string) $courbe->model_name);
            if ($nom !== '') {
                DB::table('animal_breeds')->insertOrIgnore([
                    'species_id' => $courbe->species_id, 'name' => mb_substr($nom, 0, 100),
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        foreach (self::RACES_LOCALES as $slug => $races) {
            $especeId = DB::table('species')->where('slug', $slug)->value('id');
            if (! $especeId) {
                continue;
            }
            foreach ($races as $race) {
                DB::table('animal_breeds')->insertOrIgnore([
                    'species_id' => $especeId, 'name' => $race, 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }
}
