<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * `transformations.batch_number` était la SEULE colonne numérotée sans index
 * unique.
 *
 * Toutes ses sœurs en portent un — ventes, dépenses, achats, ordres d'abattage,
 * ordres de provenderie, transformations de cultures. La numérotation étant
 * dérivée du MAX (« lire, ajouter un »), cet index est ce qui rattrape une
 * collision : ailleurs elle éclate à l'insertion, ici elle passait EN SILENCE.
 * Deux lots de transformation pouvaient porter le même numéro, et la traçabilité
 * d'un produit transformé remontait alors à deux origines.
 *
 * DÉ-DOUBLONNAGE PRÉALABLE OBLIGATOIRE : poser l'index sur une base qui contient
 * déjà des doublons ferait ÉCHOUER la migration — donc le déploiement, qui part
 * automatiquement à chaque poussée sur main. On renumérote d'abord, en gardant
 * le plus ancien enregistrement (le plus petit id) sur son numéro d'origine.
 */
return new class extends Migration
{
    public function up(): void
    {
        $doublons = DB::table('transformations')
            ->select('batch_number')
            ->groupBy('batch_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('batch_number');

        foreach ($doublons as $numero) {
            $ids = DB::table('transformations')
                ->where('batch_number', $numero)
                ->orderBy('id')
                ->pluck('id')
                ->slice(1); // le premier garde son numéro

            foreach ($ids as $id) {
                $nouveau = $this->numeroLibre((string) $numero);

                DB::table('transformations')->where('id', $id)->update(['batch_number' => $nouveau]);

                Log::warning("Numérotation : lot de transformation #{$id} renuméroté « {$numero} » → « {$nouveau} » (doublon existant).");
            }
        }

        Schema::table('transformations', function (Blueprint $table) {
            $table->unique('batch_number');
        });
    }

    /**
     * Prochain numéro libre dans la même famille que $modele.
     *
     * On conserve le format (préfixe, année, largeur) en n'incrémentant que la
     * séquence terminale. Un numéro hors format — legacy, saisie manuelle — se
     * voit suffixer, faute de séquence à incrémenter : l'unicité prime, et le
     * cas est journalisé.
     */
    private function numeroLibre(string $modele): string
    {
        if (preg_match('/^(?<base>.*?)(?<sequence>\d+)$/', $modele, $m)) {
            $base    = $m['base'];
            $largeur = strlen($m['sequence']);
            $suivant = (int) $m['sequence'];

            for ($i = 0; $i < 100000; $i++) {
                $suivant++;
                $candidat = $base . str_pad((string) $suivant, $largeur, '0', STR_PAD_LEFT);

                if (! DB::table('transformations')->where('batch_number', $candidat)->exists()) {
                    return $candidat;
                }
            }
        }

        $suffixe = 2;
        while (DB::table('transformations')->where('batch_number', "{$modele}-D{$suffixe}")->exists()) {
            $suffixe++;
        }

        return "{$modele}-D{$suffixe}";
    }

    public function down(): void
    {
        Schema::table('transformations', function (Blueprint $table) {
            $table->dropUnique(['batch_number']);
        });
    }
};
