<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UN MODÈLE DE TÂCHE NE VISAIT QUE DES TYPES DE PRODUCTION — PAS DES ESPÈCES.
 *
 * Or un même type existe pour plusieurs espèces : « reproducteur » vaut pour la
 * poule ET le bélier, « engraissement » pour l'ovin, le lapin ET le porc. Le
 * planificateur générait donc « Retournement des œufs en incubation » pour un
 * lot de béliers, et « Curage de l'étable » pour un clapier.
 *
 * `species_families` (vide = toutes) borne un modèle aux familles d'espèces
 * concernées. La famille plutôt que l'espèce : un élevage qui ajoute une espèce
 * de la même famille (une pintade, un zébu) en hérite sans réglage.
 */
return new class extends Migration
{
    /** Modèles livrés dont le geste n'a de sens que pour certaines familles. */
    private const FAMILLES = [
        'Retournement des œufs en incubation'               => ['volaille'],
        'Contrôle température et hygrométrie incubateur'    => ['volaille'],
        'Désinfection de l’incubateur'                      => ['volaille'],
        'Curage et raclage de l’étable'                     => ['grand_ruminant', 'petit_ruminant', 'porcin'],
        'Contrôle abreuvement et fourrage'                  => ['grand_ruminant', 'petit_ruminant', 'lagomorphe'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('task_templates', 'species_families')) {
            Schema::table('task_templates', function (Blueprint $table) {
                $table->json('species_families')->nullable()->after('batch_types');
            });
        }

        foreach (self::FAMILLES as $nom => $familles) {
            DB::table('task_templates')->where('name', $nom)->whereNull('species_families')
                ->update(['species_families' => json_encode($familles)]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('task_templates', 'species_families')) {
            Schema::table('task_templates', function (Blueprint $table) {
                $table->dropColumn('species_families');
            });
        }
    }
};
