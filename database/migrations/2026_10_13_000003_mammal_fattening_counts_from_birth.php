<?php

use App\Models\Batch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ENGRAISSEMENT DES OVINS, CAPRINS, PORCS ET LAPINS : EN ÂGE.
 *
 * Décision de l'exploitation (valeurs proposées). Les courbes de ces quatre
 * souches comptaient leurs semaines depuis l'arrivée, et le cycle de leur
 * type était une durée d'embouche (90 j) — alors que l'âge, la fin de bande
 * et la ligne de norme se lisent désormais depuis la naissance pour ces
 * élevages (Batch::cycleCompteDepuisLaNaissance).
 *
 *   1. courbes de normes ré-exprimées par âge (ProductionNormSeeder) ;
 *   2. cycle par défaut du type = âge à la vente, s'il est encore la valeur
 *      livrée (une durée réglée par la ferme est respectée) ;
 *   3. fin prévue des bandes ACTIVES recalculée.
 */
return new class extends Migration
{
    /** [espèce, ancienne valeur livrée, nouvelle] pour le type « engraissement ». */
    private const CYCLES = [
        'mouton' => [90, 364],
        'chevre' => [90, 364],
        'lapin'  => [70, 77],
        'porc'   => [150, 168],
    ];

    private const SOUCHES = ['Mouton Djallonké', 'Chèvre du Sahel', 'Lapin Néo-Zélandais', 'Porc Large White'];

    public function up(): void
    {
        // 1. Courbes : on remplace les lignes de ces souches par celles du semeur.
        $seeder = new \Database\Seeders\ProductionNormSeeder;
        $lignes = (fn () => $this->sheepGoatRabbitPig())->call($seeder);
        $especes = DB::table('species')->pluck('id', 'slug');

        DB::transaction(function () use ($lignes, $especes) {
            foreach (self::SOUCHES as $souche) {
                $existantes = DB::table('production_norms')->where('model_name', $souche)->where('batch_type', 'engraissement');
                if (! $existantes->exists()) {
                    continue;   // référentiel jamais semé : rien à reprendre
                }
                $espece = (clone $existantes)->value('species_id');
                $dureeSaisie = (clone $existantes)->whereNotNull('cycle_days')->value('cycle_days');
                $existantes->delete();

                foreach (array_filter($lignes, fn ($l) => $l['model_name'] === $souche) as $ligne) {
                    $ligne['species_id'] = $espece ?? $especes[\App\Models\ProductionNorm::guessSpeciesSlug($souche)] ?? null;
                    $ligne['cycle_days'] = $dureeSaisie ?? \Database\Seeders\ProductionNormSeeder::CYCLES[$souche] ?? null;
                    \App\Models\ProductionNorm::create($ligne);
                }
            }
        });

        // 2. Cycle par défaut du type, s'il est resté la valeur livrée.
        foreach (self::CYCLES as $slug => [$ancien, $nouveau]) {
            if (! isset($especes[$slug])) continue;
            DB::table('production_types')
                ->where('species_id', $especes[$slug])->where('slug', 'engraissement')
                ->where('cycle_days_default', $ancien)
                ->update(['cycle_days_default' => $nouveau]);
        }

        // 3. Fin prévue des bandes actives de mammifères et poissons : l'ancrage a changé.
        Batch::withoutGlobalScopes()->whereIn('status', ['Actif', 'actif'])->get()
            ->reject(fn (Batch $b) => $b->isVolaille())
            ->each(function (Batch $b) {
                $b->calculateExpectedEndDate();
                $b->saveQuietly();
            });
    }

    public function down(): void
    {
        foreach (self::CYCLES as $slug => [$ancien, $nouveau]) {
            $espece = DB::table('species')->where('slug', $slug)->value('id');
            if (! $espece) continue;
            DB::table('production_types')
                ->where('species_id', $espece)->where('slug', 'engraissement')
                ->where('cycle_days_default', $nouveau)
                ->update(['cycle_days_default' => $ancien]);
        }
    }
};
