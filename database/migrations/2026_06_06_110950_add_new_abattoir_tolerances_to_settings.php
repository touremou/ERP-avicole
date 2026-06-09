<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Exécuter la migration.
     */
    public function up(): void
    {
        $now = now();
        
        // Les 4 nouvelles tolérances à ajouter
        $newSettings = [
            ['group' => 'abattoir', 'key' => 'tolerance_live_poultry', 'value' => '0', 'type' => 'number', 'label' => 'Tolérance écart volaille vivante', 'unit' => '%', 'display_order' => 8],
            ['group' => 'abattoir', 'key' => 'tolerance_slaughtered_poultry', 'value' => '0', 'type' => 'number', 'label' => 'Tolérance écart volaille abattue', 'unit' => '%', 'display_order' => 9],
            ['group' => 'abattoir', 'key' => 'tolerance_equipment', 'value' => '0', 'type' => 'number', 'label' => 'Tolérance écart matériel', 'unit' => '%', 'display_order' => 10],
            ['group' => 'abattoir', 'key' => 'tolerance_other', 'value' => '1', 'type' => 'number', 'label' => 'Tolérance écart autres', 'unit' => '%', 'display_order' => 11],
        ];

        // On utilise updateOrInsert pour éviter les doublons si on relance la migration
        foreach ($newSettings as $s) {
            DB::table('settings')->updateOrInsert(
                [
                    'group' => $s['group'], 
                    'key' => $s['key'],
                    'farm_id' => null // Pour matcher la contrainte d'unicité
                ],
                array_merge([
                    'is_sensitive' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $s)
            );
        }
    }

    /**
     * Annuler la migration.
     */
    public function down(): void
    {
        // En cas de rollback, on supprime uniquement ces 4 clés
        DB::table('settings')
            ->where('group', 'abattoir')
            ->whereIn('key', [
                'tolerance_live_poultry', 
                'tolerance_slaughtered_poultry', 
                'tolerance_equipment', 
                'tolerance_other'
            ])
            ->delete();
    }
};