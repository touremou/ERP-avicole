<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'ÂGE D'UN LOT SE COMPTAIT DEPUIS LA RÉCEPTION, PAS DEPUIS LA NAISSANCE.
 *
 * `Batch::getAgeAttribute()` rendait « jours depuis arrival_date + 1 » : la
 * réception valait jour 1 de vie. Or un lot s'achète à n'importe quel âge — des
 * poulettes prêtes à pondre arrivent à 16 semaines, un poulet de chair peut être
 * repris en cours de bande.
 *
 * ─── LE MODÈLE RETENU ───
 *
 * La DATE DE NAISSANCE, ancrage de tout l'élevage industriel : les guides Cobb,
 * Ross, Lohmann, Hy-Line sont indexés sur l'âge depuis l'éclosion, et c'est la
 * date imprimée sur le bon du couvoir.
 *
 * On stocke une DATE plutôt qu'un âge relatif parce qu'elle est insensible au
 * délai entre la réception et sa saisie — un âge saisi trois jours plus tard
 * serait faux de trois jours, une date non — et parce que c'est un fait sur les
 * animaux, pas sur notre paperasse. « Naissance » plutôt qu'« éclosion » : cette
 * application suit aussi des ruminants et des poissons.
 *
 * ─── LA REPRISE NE CHANGE AUCUN ÂGE AFFICHÉ ───
 *
 * `birth_date = arrival_date` pour tous les lots existants. C'est exactement ce
 * que dit le défaut historique de `age_at_arrival` (1 = « reçu à un jour »), et
 * cela reproduit à la journée près l'âge affiché jusqu'ici.
 *
 * Rien ne bouge donc pour les bandes en cours. Seuls les lots saisis DÉSORMAIS
 * avec une date de naissance antérieure se comporteront différemment — ce qui est
 * le but.
 *
 * ─── `age_at_arrival` N'EST PAS SUPPRIMÉE ───
 *
 * Elle existe depuis la migration d'origine, n'est lue par personne et n'est
 * écrite que par le chemin d'incubation. La retirer d'une base en production est
 * une décision destructive qui ne se justifie pas ici : elle reste, inerte, et la
 * reprise ci-dessous s'en sert une dernière fois pour reconstituer la naissance
 * des lots qui portaient autre chose que le défaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('arrival_date');
        });

        // Reprise : la naissance se déduit de l'arrivée et de l'âge qu'on portait
        // à ce moment-là. Le jour d'arrivée compte pour un jour de vie, d'où le
        // « − 1 » : age_at_arrival = 1 (le défaut) donne birth_date = arrival_date.
        DB::table('batches')
            ->whereNull('birth_date')
            ->whereNotNull('arrival_date')
            ->orderBy('id')
            ->chunkById(500, function ($lots) {
                foreach ($lots as $lot) {
                    $age = max(1, (int) ($lot->age_at_arrival ?? 1));

                    DB::table('batches')->where('id', $lot->id)->update([
                        'birth_date' => \Carbon\Carbon::parse($lot->arrival_date)
                            ->subDays($age - 1)
                            ->toDateString(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn('birth_date');
        });
    }
};
