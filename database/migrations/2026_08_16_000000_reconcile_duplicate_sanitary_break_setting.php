<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DEUX RÉGLAGES POUR UNE SEULE DURÉE DE VIDE SANITAIRE.
 *
 * Paramètres proposait, dans deux onglets différents et sous des libellés
 * quasi identiques :
 *
 *   • Élevage  › « Durée du vide sanitaire »  → elevage.sanitary_break_days = 14
 *   • Planning › « Durée vide sanitaire »     → planning.void_sanitaire_days = 21
 *
 * Deux champs, deux valeurs, une seule réalité biologique. Et aucun des deux ne
 * gouvernait la libération du bâtiment, qui appliquait une constante codée en
 * dur (cf. Building::sanitaryBreakDays()).
 *
 * On ne garde que la clef du module Élevage, là où le vide sanitaire se décide.
 *
 * PRÉSERVATION DU CHOIX DE LA FERME. Les deux champs n'ont pas la même valeur
 * livrée (14 et 21) : « les deux au défaut » ne veut donc pas dire « les deux à
 * la même valeur ». On reporte la valeur que la ferme a effectivement modifiée ;
 * si elle a touché aux deux, on retient la PLUS LONGUE. Une migration peut
 * allonger un vide sanitaire sans dommage — jamais l'écourter, ce serait décider
 * à sa place d'un raccourci sanitaire.
 *
 * Si la ferme n'a touché à ni l'un ni l'autre, on conserve 14 : c'est ce que le
 * système APPLIQUAIT réellement jusqu'ici (la libération automatique). Le
 * planning annoncera donc 14 au lieu de 21 — non pas un raccourcissement du
 * vide, mais la fin d'un écart d'une semaine entre ce qu'il annonçait et ce que
 * le système faisait. La durée réelle, elle, ne change pas.
 */
return new class extends Migration
{
    private const CANONICAL_GROUP = 'elevage';
    private const CANONICAL_KEY   = 'sanitary_break_days';
    private const CANONICAL_SHIPPED = '14';

    private const LEGACY_GROUP = 'planning';
    private const LEGACY_KEY   = 'void_sanitaire_days';
    private const LEGACY_SHIPPED = '21';

    public function up(): void
    {
        $canonical = DB::table('settings')
            ->where('group', self::CANONICAL_GROUP)->where('key', self::CANONICAL_KEY)
            ->whereNull('farm_id')->first();

        $legacy = DB::table('settings')
            ->where('group', self::LEGACY_GROUP)->where('key', self::LEGACY_KEY)
            ->whereNull('farm_id')->first();

        if ($legacy && $canonical) {
            $canonicalTouched = (string) $canonical->value !== self::CANONICAL_SHIPPED;
            $legacyTouched    = (string) $legacy->value !== self::LEGACY_SHIPPED;

            $keep = match (true) {
                // Les deux réglés : on retient le plus long, jamais le plus court.
                $canonicalTouched && $legacyTouched
                    => (string) max((int) $canonical->value, (int) $legacy->value),
                // Seul l'ancien champ porte l'intention de la ferme.
                $legacyTouched  => (string) $legacy->value,
                // Sinon la clef canonique fait foi (réglée, ou valeur appliquée).
                default         => (string) $canonical->value,
            };

            if ($keep !== (string) $canonical->value) {
                DB::table('settings')->where('id', $canonical->id)
                    ->update(['value' => $keep, 'updated_at' => now()]);
            }
        }

        // L'ancien champ ne doit disparaître qu'une fois son éventuelle valeur
        // reportée, sinon le choix de la ferme s'évaporerait avec lui.
        if ($legacy) {
            DB::table('settings')->where('id', $legacy->id)->delete();
        }

        // Libellé plus explicite : c'est désormais la durée qui gouverne
        // l'affichage, le planning ET la libération automatique.
        DB::table('settings')
            ->where('group', self::CANONICAL_GROUP)->where('key', self::CANONICAL_KEY)
            ->whereNull('farm_id')
            ->update([
                'label'      => 'Durée du vide sanitaire (libération automatique du bâtiment)',
                'unit'       => 'jours',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // On ne recrée pas le doublon : c'était le défaut.
    }
};
