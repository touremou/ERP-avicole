<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UNE VOLAILLE REPRODUCTRICE POND — le référentiel disait le contraire.
 *
 * `production_types.metrics_enabled` porte, pour chaque type de production, ce
 * que le lot mesure. Le type « reproducteur » des espèces AVICOLES y était semé
 * avec `eggs => false`.
 *
 * Or QUATRE endroits du code affirment l'inverse :
 *
 *   • `IncubationController` : « Lots éligibles à l'incubation :
 *     pondeurs/REPRODUCTEURS avicoles uniquement — on s'appuie donc sur
 *     tracksEggs() et l'espèce » ;
 *   • le repli legacy de `Batch::tracksEggs()` liste ponte/repro/reproducteur ;
 *   • `Batch::minLayingAgeDays()` traite explicitement repro/reproducteur ;
 *   • `ProductionType::feedSector()` range les reproducteurs en secteur
 *     « Ponte » — ils mangent de l'aliment pondeuse, parce qu'ils pondent.
 *
 * Le code déclarait la règle ; la donnée de référence la contredisait. Et c'est
 * la donnée qui gagnait : `tracks('eggs')` lit `metrics_enabled`, sans repli.
 *
 * ─── CE QUE ÇA EMPÊCHAIT ───
 *
 * Une bande de reproducteurs ne pouvait ni faire l'objet d'une collecte d'œufs
 * (`EggProductionController` filtre sur `tracksEggs()`), ni être choisie comme
 * origine d'une incubation — alors qu'elle est la source même des œufs à
 * couver. Le module qui fait éclore les œufs excluait les bandes qui les
 * produisent.
 *
 * ─── PÉRIMÈTRE ───
 *
 * Les seules VOLAILLES. Le slug « reproducteur » est partagé avec les ovins,
 * caprins, bovins, lapins et porcs, qui ne pondent pas : leur ligne ne bouge
 * pas. C'est la distinction que fait déjà `IncubationController`.
 *
 * On corrige aussi toute ligne « ponte » avicole restée à `eggs => false` : la
 * migration du 13/06/2026 créait à la volée les types manquants avec un gabarit
 * figé où `eggs` valait toujours faux, quel que soit le slug.
 *
 * ─── POURQUOI CORRIGER LA DONNÉE, ET NON LE CODE ───
 *
 * `metrics_enabled` n'a AUCUN écran d'administration — aucun contrôleur, aucune
 * vue ne l'écrit. Personne n'a donc pu choisir « false » délibérément : c'est
 * une valeur de semis, et la corriger n'écrase le choix de personne. Ajouter un
 * repli dans `tracks()` aurait au contraire créé une seconde source de vérité à
 * côté de la colonne, exactement ce que cette base combat ailleurs.
 */
return new class extends Migration
{
    /** Slugs de types de production qui pondent, chez les volailles. */
    private const SLUGS_PONDEURS = ['ponte', 'repro', 'reproducteur'];

    public function up(): void
    {
        $this->appliquer(true);
    }

    /**
     * Le retour arrière ne remet PAS `eggs => false` : il rétablirait un
     * référentiel faux, et surtout il couperait la collecte d'œufs de bandes
     * qui, entre-temps, en auront enregistré. Une migration descendante ne doit
     * pas détruire des données devenues valides.
     */
    public function down(): void
    {
        // Volontairement sans effet — cf. le commentaire ci-dessus.
    }

    private function appliquer(bool $pond): void
    {
        $volailles = DB::table('species')->where('family', 'volaille')->pluck('id');

        if ($volailles->isEmpty()) {
            return;
        }

        $types = DB::table('production_types')
            ->whereIn('species_id', $volailles)
            ->whereIn('slug', self::SLUGS_PONDEURS)
            ->get(['id', 'metrics_enabled']);

        foreach ($types as $type) {
            $metrics = json_decode((string) $type->metrics_enabled, true) ?: [];

            if (($metrics['eggs'] ?? null) === $pond) {
                continue;   // déjà juste : on ne touche pas la ligne
            }

            $metrics['eggs'] = $pond;

            DB::table('production_types')
                ->where('id', $type->id)
                ->update(['metrics_enabled' => json_encode($metrics), 'updated_at' => now()]);
        }
    }
};
