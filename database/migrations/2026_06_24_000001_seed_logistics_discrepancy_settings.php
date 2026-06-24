<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expose dans Paramètres > Abattoir tous les réglages du moteur d'écart
 * (App\Services\Discrepancy\DiscrepancyEvaluator, config/logistique.php).
 *
 * Contexte : seules 4 tolérances (volaille, œufs, aliment, fumier) étaient
 * seedées et donc éditables. Les autres clés référencées par le code
 * (tolérances multiespèces) n'existaient pas en base : elles tombaient
 * toujours sur leur valeur de repli, sans pouvoir être réglées. On ajoute
 * ici les tolérances manquantes + la tolérance « produits finis » (nouvelle)
 * + les deux seuils de sévérité (attention / critique), eux aussi codés en
 * dur auparavant.
 *
 * Idempotent : on n'insère que les clés absentes — aucune valeur déjà
 * personnalisée par un administrateur n'est écrasée.
 */
return new class extends Migration
{
    /** Clés ajoutées par cette migration (group = abattoir). */
    private array $keys = [
        // [key, valeur défaut, libellé, ordre, description]
        ['tolerance_live_poultry',        '0', 'Tolérance écart volaille vivante',   8,  'Écart admis à la réception pour la volaille vivante (three-way matching).'],
        ['tolerance_slaughtered_poultry', '0', 'Tolérance écart volaille abattue',   9,  'Écart admis à la réception pour la volaille abattue.'],
        ['tolerance_live_animals',        '0', 'Tolérance écart animal vivant',      10, 'Écart admis pour les animaux vivants (toute espèce) — comptés à la tête.'],
        ['tolerance_carcass',             '1', 'Tolérance écart carcasse / viande',  11, 'Écart admis pour les carcasses / viandes (variance de pesée).'],
        ['tolerance_milk',                '1', 'Tolérance écart lait',               12, 'Écart admis pour le lait (variance de volume).'],
        ['tolerance_finished_goods',      '1', 'Tolérance écart produits finis',     13, 'Écart admis pour les produits finis (découpe, poussins...).'],
        ['tolerance_equipment',           '0', 'Tolérance écart matériel',           14, 'Écart admis pour le matériel — comptage exact.'],
        ['tolerance_other',               '1', 'Tolérance écart autre (défaut)',     15, 'Tolérance par défaut, appliquée aux types non listés.'],
        ['severity_attention',            '2', 'Seuil sévérité « attention »',       16, 'Taux d\'écart global (%) au-delà duquel un rapport est classé « attention ».'],
        ['severity_critique',             '5', 'Seuil sévérité « critique »',        17, 'Taux d\'écart global (%) au-delà duquel un rapport est classé « critique ».'],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->keys as [$key, $value, $label, $order, $description]) {
            $exists = DB::table('settings')
                ->where('group', 'abattoir')
                ->where('key', $key)
                ->whereNull('farm_id')
                ->exists();

            if ($exists) {
                continue; // ne pas écraser une valeur déjà réglée
            }

            DB::table('settings')->insert([
                'group'         => 'abattoir',
                'key'           => $key,
                'value'         => $value,
                'type'          => 'number',
                'label'         => $label,
                'description'   => $description,
                'options'       => null,
                'unit'          => '%',
                'display_order' => $order,
                'is_sensitive'  => false,
                'farm_id'       => null,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        // Le moteur lit ces valeurs via le cache des settings : on le vide.
        \App\Models\Setting::clearCache();
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('group', 'abattoir')
            ->whereNull('farm_id')
            ->whereIn('key', array_column($this->keys, 0))
            ->delete();

        \App\Models\Setting::clearCache();
    }
};
