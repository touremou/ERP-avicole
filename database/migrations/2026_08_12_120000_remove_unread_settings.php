<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TROIS RÉGLAGES QUE RIEN NE LIT — retirés de l'écran.
 *
 * L'écran des Réglages offre 204 champs. Trois d'entre eux ne sont lus par AUCUNE
 * ligne de code : ni littéralement, ni par une clef construite, ni depuis config/.
 * Le promoteur peut donc les modifier, enregistrer, et n'obtenir rigoureusement
 * aucun effet — sans qu'un message le lui dise.
 *
 * C'est le défaut symétrique de ceux corrigés tout au long de cet audit. Là, une
 * règle avait des lecteurs et pas de rédacteur ; ici un réglage a un rédacteur —
 * l'utilisateur — et aucun lecteur. Le résultat est le même : on croit avoir agi.
 *
 * Un champ inerte est PIRE qu'un champ absent, parce qu'il détourne d'un réglage
 * qui, lui, fonctionne. Les trois ont chacun leur remplaçant déjà en place :
 *
 * ── abattoir.tolerance_poultry — « Tolérance écart volaille »
 *
 * Le plus grave des trois, à cause de sa description : « Anti-fraude three-way
 * matching ». Le moteur d'écart (DiscrepancyEvaluator, via config/logistique.php)
 * ne connaît pas de type de produit « volaille » : il distingue
 * `volaille_vivante` et `volaille_abattue`, réglés par `tolerance_live_poultry` et
 * `tolerance_slaughtered_poultry`. Régler « Tolérance écart volaille » à 5 % en
 * croyant assouplir la réception laissait la tolérance réelle à 0 %.
 *
 * ── abattoir.yield_carcass — « Rendement carcasse cible »
 *
 * Abandonnée volontairement le 7 août : aucun des procédés de transformation —
 * fumage, grillage, marinade, autre — n'est une carcasse, et chacun a désormais sa
 * propre cible (cf. la migration add_transformation_yield_targets et
 * UnwiredSafeguardsTest). Elle est restée dans la table, donc à l'écran.
 *
 * Au passage, une correction : la note de ce lot affirmait que `yield_carcass`
 * « n'a JAMAIS existé dans la table des réglages ». C'est inexact — la migration
 * de création des réglages la crée bien (valeur 72). Elle existait, elle était
 * offerte, et elle ne servait à rien.
 *
 * ── elevage.tabaski_date — « Date Tabaski / Eid al-Adha »
 *
 * Sa description promet « le décompte automatique J-60/J-30/J-7 pour les lots
 * ovins ». Ce décompte existe et fonctionne, mais il lit la date d'une CAMPAGNE
 * (campaigns.target_date, cf. DashboardController), pas ce réglage. Renseigner la
 * date ici ne déclenchait aucun décompte — et la bonne source est la campagne, qui
 * permet d'en suivre plusieurs plutôt qu'une seule date globale.
 *
 * ─── RÉVERSIBILITÉ ───
 *
 * `down()` recrée les trois lignes avec leurs valeurs d'origine. Les valeurs
 * saisies par l'exploitation sont perdues, ce qui est sans conséquence : elles
 * n'avaient aucun effet.
 */
return new class extends Migration
{
    private const UNREAD = [
        ['group' => 'abattoir', 'key' => 'tolerance_poultry', 'value' => '0',  'type' => 'number', 'label' => 'Tolérance écart volaille',  'unit' => '%', 'display_order' => 4,  'description' => 'Anti-fraude three-way matching'],
        ['group' => 'abattoir', 'key' => 'yield_carcass',     'value' => '72', 'type' => 'number', 'label' => 'Rendement carcasse cible', 'unit' => '%', 'display_order' => 1,  'description' => null],
        ['group' => 'elevage',  'key' => 'tabaski_date',      'value' => '',   'type' => 'date',   'label' => 'Date Tabaski / Eid al-Adha', 'unit' => null, 'display_order' => 26, 'description' => 'Permet le décompte automatique J-60/J-30/J-7 pour les lots ovins.'],
    ];

    public function up(): void
    {
        foreach (self::UNREAD as $setting) {
            DB::table('settings')
                ->where('group', $setting['group'])
                ->where('key', $setting['key'])
                ->whereNull('farm_id')
                ->delete();
        }
    }

    public function down(): void
    {
        $now = now();

        foreach (self::UNREAD as $setting) {
            $exists = DB::table('settings')
                ->where('group', $setting['group'])
                ->where('key', $setting['key'])
                ->whereNull('farm_id')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('settings')->insert($setting + [
                'farm_id'      => null,
                'is_sensitive' => false,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }
};
