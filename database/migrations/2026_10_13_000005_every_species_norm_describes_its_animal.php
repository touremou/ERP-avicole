<?php

use App\Models\ProductionNorm;
use Database\Seeders\ProductionNormSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CHAQUE COURBE DÉCRIT L'ANIMAL DE SON TYPE — décision de l'exploitation
 * (« fiabiliser les autres espèces »), valeurs proposées.
 *
 *   • « Poussin Standard » n'avait pas d'espèce : générique, il était proposé
 *     pour des moutons. Sa courbe s'arrêtait à 4 semaines avec des poids de
 *     chair, pour une poussinière de 90 jours → élevage de poulettes (13 sem.).
 *   • Laitières (chèvre Saanen, vache métisse) : courbes de CROISSANCE d'un
 *     jeune pour des troupeaux EN LACTATION → courbes de lactation.
 *   • Types sans courbe (caille de chair, truie, taureau, reproducteur chair,
 *     pintade pondeuse, pigeonneau) → courbes ajoutées ; caille pondeuse :
 *     courbe prolongée jusqu'à la fin de son cycle (34 sem.).
 *   • Canard de Pékin, pintade, bovins : fin de courbe ≠ cycle du type →
 *     cycle par souche.
 *
 * Une souche n'est remplacée que si ses lignes sont ENCORE celles livrées :
 * une courbe ajustée par la ferme est respectée.
 */
return new class extends Migration
{
    /** Courbes livrées avant ce correctif : [souche => [[semaine, poids g], …]]. */
    private const ANCIENNES = [
        'Poussin Standard'       => [[1, 160], [2, 400], [3, 750], [4, 1100]],
        'Pintade locale'         => [[2, 180], [4, 500], [8, 1100], [12, 1400]],
        'Chèvre Saanen'          => [[1, 18000], [40, 60000]],
        'Vache Laitière (Métis)' => [[1, 90000], [52, 400000]],
        'Caille Japonaise'       => [[1, 30], [2, 70], [4, 150], [6, 200], [8, 220]],
    ];

    /** Souches nouvelles : ajoutées si absentes. */
    private const NOUVELLES = ['Caille de chair', 'Truie Large White', "Taureau N'Dama",
        'Reproducteur chair (poule)', 'Pintade pondeuse locale', 'Pigeonneau de chair'];

    public function up(): void
    {
        if (! DB::table('production_norms')->exists()) {
            return;   // référentiel jamais semé : le semeur posera tout
        }

        $livrees = collect((new ProductionNormSeeder)->lignes())->groupBy('model_name');
        $especes = DB::table('species')->pluck('id', 'slug');
        $especeDe = fn (string $souche) => $especes[ProductionNorm::guessSpeciesSlug($souche)] ?? null;

        DB::transaction(function () use ($livrees, $especeDe) {
            // 1. Courbes livrées encore intactes : remplacées.
            foreach (self::ANCIENNES as $souche => $anciennes) {
                $actuelles = DB::table('production_norms')->where('model_name', $souche)
                    ->orderBy('week_number')->get(['week_number', 'target_weight'])
                    ->map(fn ($l) => [(int) $l->week_number, (int) round($l->target_weight)])->all();

                if ($actuelles !== $anciennes) {
                    continue;   // ajustée par la ferme (ou absente) : on n'y touche pas
                }

                $cycle = DB::table('production_norms')->where('model_name', $souche)->whereNotNull('cycle_days')->value('cycle_days');
                DB::table('production_norms')->where('model_name', $souche)->delete();
                $this->poser($livrees[$souche], $especeDe($souche), $cycle);
            }

            // 2. Souches nouvelles.
            foreach (self::NOUVELLES as $souche) {
                if (! DB::table('production_norms')->where('model_name', $souche)->exists()) {
                    $this->poser($livrees[$souche], $especeDe($souche), null);
                }
            }

            // 3. Toute norme sans espèce reçoit la sienne (Poussin Standard…).
            DB::table('production_norms')->whereNull('species_id')->get(['id', 'model_name'])
                ->each(fn ($n) => ($id = $especeDe($n->model_name))
                    ? DB::table('production_norms')->where('id', $n->id)->update(['species_id' => $id])
                    : null);

            // 4. Cycle par souche, là où la ferme n'en a pas réglé.
            foreach (ProductionNormSeeder::CYCLES as $souche => $jours) {
                DB::table('production_norms')->where('model_name', $souche)->whereNull('cycle_days')
                    ->update(['cycle_days' => $jours]);
            }
        });
    }

    private function poser($lignes, ?int $especeId, ?int $cycle): void
    {
        foreach ($lignes as $ligne) {
            $ligne['species_id'] = $especeId;
            $ligne['cycle_days'] = $cycle ?? ProductionNormSeeder::CYCLES[$ligne['model_name']] ?? null;
            ProductionNorm::create($ligne);
        }
    }

    public function down(): void
    {
        // Référentiel : pas de retour arrière des valeurs (le semeur fait foi).
    }
};
