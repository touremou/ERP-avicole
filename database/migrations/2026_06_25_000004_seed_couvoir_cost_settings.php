<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paramètres de coût du couvoir (process costing), éditables dans
 * Paramètres > Couvoir :
 *   - couvoir.egg_unit_cost     : coût unitaire par défaut de l'œuf mis à couver.
 *   - couvoir.overhead_per_egg  : frais d'incubation par œuf (énergie, main-d'œuvre,
 *                                  amortissement) — base de l'absorption complète.
 *
 * Servent de valeurs de repli quand l'opérateur ne saisit pas ces coûts au
 * lancement d'une incubation (cf. App\Actions\Incubation\StartIncubation).
 */
return new class extends Migration
{
    private array $settings = [
        ['key' => 'egg_unit_cost',    'value' => '0', 'label' => 'Coût unitaire de l\'œuf (défaut)',  'order' => 10, 'desc' => 'Valeur par défaut du coût d\'un œuf mis à couver (achat ou interne).'],
        ['key' => 'overhead_per_egg', 'value' => '0', 'label' => 'Frais d\'incubation par œuf',        'order' => 11, 'desc' => 'Énergie + main-d\'œuvre + amortissement, alloués par œuf. Absorption complète du coût de revient du poussin.'],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->settings as $s) {
            $exists = DB::table('settings')
                ->where('group', 'couvoir')->where('key', $s['key'])->whereNull('farm_id')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('settings')->insert([
                'group'         => 'couvoir',
                'key'           => $s['key'],
                'value'         => $s['value'],
                'type'          => 'number',
                'label'         => $s['label'],
                'description'   => $s['desc'],
                'options'       => null,
                'unit'          => setting('general.currency', 'GNF'),
                'display_order' => $s['order'],
                'is_sensitive'  => false,
                'farm_id'       => null,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        \App\Models\Setting::clearCache();
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'couvoir')
            ->whereIn('key', array_column($this->settings, 'key'))->whereNull('farm_id')->delete();
        \App\Models\Setting::clearCache();
    }
};
