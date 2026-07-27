<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RENDEMENT CIBLE PAR PROCÉDÉ DE TRANSFORMATION.
 *
 * L'écran de saisie d'une transformation affichait le rendement en le comparant
 * à `abattoir.yield_smoking` (65 %) puis à `abattoir.yield_carcass` (72 %) —
 * une clef qui n'a JAMAIS existé dans la table des réglages, donc figée à son
 * repli. Les deux comparaisons étaient de surcroît dans le mauvais ordre : 65
 * étant inférieur à 72, la branche « orange » était inatteignable et tout
 * rendement supérieur à 65 % s'affichait au vert, y compris une carcasse à 66 %
 * réputée devoir atteindre 72.
 *
 * Le fond du problème est ailleurs : les procédés proposés sont le fumage, le
 * grillage, la marinade et « autre ». Aucun n'est une carcasse, et un seul —
 * le fumage — avait une cible. Une marinade était donc jugée à l'aune du fumage.
 *
 * On crée donc une cible PAR PROCÉDÉ, VIDE par défaut. Une cible vide veut dire
 * « pas de référence », et l'écran l'affiche ainsi ; y mettre un chiffre inventé
 * donnerait à une supposition l'autorité d'une mesure. C'est à la ferme de les
 * renseigner depuis Paramètres → Abattoir, à partir de ses propres relevés.
 */
return new class extends Migration
{
    private const TARGETS = [
        ['key' => 'yield_grille', 'label' => 'Rendement grillage cible', 'order' => 4],
        ['key' => 'yield_marine', 'label' => 'Rendement marinade cible', 'order' => 5],
        ['key' => 'yield_autre',  'label' => 'Rendement cible — autre procédé', 'order' => 6],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::TARGETS as $target) {
            $exists = DB::table('settings')
                ->where('group', 'abattoir')->where('key', $target['key'])->exists();

            if ($exists) {
                continue;   // idempotent : on ne réécrit pas une cible déjà réglée
            }

            DB::table('settings')->insert([
                'group'         => 'abattoir',
                'key'           => $target['key'],
                'value'         => '',      // aucune cible : à la ferme de la fixer
                'type'          => 'number',
                'label'         => $target['label'],
                'unit'          => '%',
                'display_order' => $target['order'],
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'abattoir')
            ->whereIn('key', array_column(self::TARGETS, 'key'))->delete();
    }
};
