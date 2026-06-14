<?php
namespace Database\Seeders;

use App\Models\Species;
use App\Models\ProductionType;
use Illuminate\Database\Seeder;

class SpeciesSeeder extends Seeder
{
    public function run(): void
    {
        $speciesData = [
            // ── VOLAILLES ──
            ['slug'=>'poulet',  'name_fr'=>'Poulet',          'local_name'=>null,              'family'=>'volaille',        'unit_label'=>'Tête',   'habitat_label'=>'Poulailler',  'icon'=>'🐔','color'=>'amber',   'tracks_eggs'=>true,  'tracks_milk'=>false,'tracks_water_quality'=>false,'sort_order'=>1],
            ['slug'=>'dinde',   'name_fr'=>'Dinde / Dindon',   'local_name'=>null,              'family'=>'volaille',        'unit_label'=>'Tête',   'habitat_label'=>'Dindonnière', 'icon'=>'🦃','color'=>'orange',  'tracks_eggs'=>false, 'tracks_milk'=>false,'tracks_water_quality'=>false,'sort_order'=>2],
            ['slug'=>'caille',  'name_fr'=>'Caille',           'local_name'=>null,              'family'=>'volaille',        'unit_label'=>'Tête',   'habitat_label'=>'Cailletière', 'icon'=>'🥚','color'=>'yellow',  'tracks_eggs'=>true,  'tracks_milk'=>false,'tracks_water_quality'=>false,'sort_order'=>3],
            ['slug'=>'pigeon',  'name_fr'=>'Pigeon',           'local_name'=>null,              'family'=>'volaille',        'unit_label'=>'Couple', 'habitat_label'=>'Colombier',   'icon'=>'🕊️','color'=>'slate',   'tracks_eggs'=>false, 'tracks_milk'=>false,'tracks_water_quality'=>false,'sort_order'=>4],
            ['slug'=>'pintade', 'name_fr'=>'Pintade',          'local_name'=>null,              'family'=>'volaille',        'unit_label'=>'Tête',   'habitat_label'=>'Pintadière',  'icon'=>'🐦','color'=>'purple',  'tracks_eggs'=>true,  'tracks_milk'=>false,'tracks_water_quality'=>false,'sort_order'=>5],
            ['slug'=>'canard',  'name_fr'=>'Canard',           'local_name'=>null,              'family'=>'volaille',        'unit_label'=>'Tête',   'habitat_label'=>'Canardière',  'icon'=>'🦆','color'=>'teal',    'tracks_eggs'=>false, 'tracks_milk'=>false,'tracks_water_quality'=>false,'sort_order'=>6],
            // ── PETITS RUMINANTS ──
            ['slug'=>'mouton',  'name_fr'=>'Mouton / Ovin',    'local_name'=>'Bélier Djallonké','family'=>'petit_ruminant',  'unit_label'=>'Tête',   'habitat_label'=>'Bergerie',    'icon'=>'🐑','color'=>'sky',     'tracks_eggs'=>false, 'tracks_milk'=>false,'tracks_water_quality'=>false,'sort_order'=>10],
            ['slug'=>'chevre',  'name_fr'=>'Chèvre / Caprin',  'local_name'=>'Chèvre Djallonké','family'=>'petit_ruminant',  'unit_label'=>'Tête',   'habitat_label'=>'Chèvrerie',   'icon'=>'🐐','color'=>'emerald', 'tracks_eggs'=>false, 'tracks_milk'=>true, 'tracks_water_quality'=>false,'sort_order'=>11],
            // ── GRANDS RUMINANTS ──
            ['slug'=>'vache',   'name_fr'=>'Vache / Bovin',    'local_name'=>'Zébu / N\'Dama', 'family'=>'grand_ruminant',  'unit_label'=>'Tête',   'habitat_label'=>'Étable',      'icon'=>'🐄','color'=>'lime',    'tracks_eggs'=>false, 'tracks_milk'=>true, 'tracks_water_quality'=>false,'sort_order'=>15],
            // ── AQUACULTURE ──
            ['slug'=>'tilapia',      'name_fr'=>'Tilapia',         'local_name'=>null,'family'=>'aquaculture','unit_label'=>'Sujet','habitat_label'=>'Bassin','icon'=>'🐟','color'=>'blue',  'tracks_eggs'=>false,'tracks_milk'=>false,'tracks_water_quality'=>true,'sort_order'=>20],
            ['slug'=>'carpe',        'name_fr'=>'Carpe',           'local_name'=>null,'family'=>'aquaculture','unit_label'=>'Sujet','habitat_label'=>'Bassin','icon'=>'🐠','color'=>'indigo','tracks_eggs'=>false,'tracks_milk'=>false,'tracks_water_quality'=>true,'sort_order'=>21],
            ['slug'=>'silure',       'name_fr'=>'Silure / Poisson-chat','local_name'=>null,'family'=>'aquaculture','unit_label'=>'Sujet','habitat_label'=>'Bassin','icon'=>'🐡','color'=>'cyan', 'tracks_eggs'=>false,'tracks_milk'=>false,'tracks_water_quality'=>true,'sort_order'=>22],
            // ── AUTRES ──
            ['slug'=>'lapin', 'name_fr'=>'Lapin',  'local_name'=>null,'family'=>'lagomorphe','unit_label'=>'Tête','habitat_label'=>'Clapier',  'icon'=>'🐇','color'=>'rose', 'tracks_eggs'=>false,'tracks_milk'=>false,'tracks_water_quality'=>false,'sort_order'=>30],
            ['slug'=>'porc',  'name_fr'=>'Porc',   'local_name'=>null,'family'=>'porcin',    'unit_label'=>'Tête','habitat_label'=>'Porcherie','icon'=>'🐷','color'=>'pink', 'tracks_eggs'=>false,'tracks_milk'=>false,'tracks_water_quality'=>false,'sort_order'=>40],
        ];

        $metricsBase  = ['mortality'=>true,'feed'=>true,'weight'=>true,'eggs'=>false,'milk'=>false,'water_quality'=>false,'born'=>false,'weaned'=>false];
        $metricsEggs  = array_merge($metricsBase, ['eggs'=>true]);
        $metricsRumin = array_merge($metricsBase, ['born'=>true,'weaned'=>true]);
        $metricsLait  = array_merge($metricsRumin,['milk'=>true]);
        $metricsAqua  = array_merge($metricsBase, ['water_quality'=>true]);

        $productionTypesData = [
            'poulet'  => [
                ['slug'=>'chair',        'name_fr'=>'Poulet de Chair',     'metrics_enabled'=>$metricsBase, 'kpi_primary'=>'fcr','cycle_days_default'=>45],
                ['slug'=>'ponte',        'name_fr'=>'Poule Pondeuse',      'metrics_enabled'=>$metricsEggs, 'kpi_primary'=>'hdp','cycle_days_default'=>540],
                ['slug'=>'poussiniere',  'name_fr'=>'Poussinière',         'metrics_enabled'=>$metricsBase, 'kpi_primary'=>'survie','cycle_days_default'=>90],
                ['slug'=>'reproducteur', 'name_fr'=>'Reproducteur',        'metrics_enabled'=>$metricsBase, 'kpi_primary'=>'fcr','cycle_days_default'=>450],
            ],
            'dinde'   => [
                ['slug'=>'chair',        'name_fr'=>'Dinde de Chair',      'metrics_enabled'=>$metricsBase, 'kpi_primary'=>'fcr','cycle_days_default'=>120],
                ['slug'=>'reproducteur', 'name_fr'=>'Dindon Reproducteur', 'metrics_enabled'=>$metricsBase, 'kpi_primary'=>'fcr','cycle_days_default'=>300],
            ],
            'caille'  => [
                ['slug'=>'ponte',        'name_fr'=>'Caille Pondeuse',     'metrics_enabled'=>$metricsEggs, 'kpi_primary'=>'hdp','cycle_days_default'=>240],
                ['slug'=>'chair',        'name_fr'=>'Caille de Chair',     'metrics_enabled'=>$metricsBase, 'kpi_primary'=>'fcr','cycle_days_default'=>42],
            ],
            'pigeon'  => [
                ['slug'=>'chair',        'name_fr'=>'Pigeonneau de Chair', 'metrics_enabled'=>$metricsBase, 'kpi_primary'=>'gmq','cycle_days_default'=>28],
            ],
            'pintade' => [
                ['slug'=>'chair',        'name_fr'=>'Pintade de Chair',    'metrics_enabled'=>$metricsBase, 'kpi_primary'=>'fcr','cycle_days_default'=>100],
                ['slug'=>'ponte',        'name_fr'=>'Pintade Pondeuse',    'metrics_enabled'=>$metricsEggs, 'kpi_primary'=>'hdp','cycle_days_default'=>365],
            ],
            'canard'  => [
                ['slug'=>'chair',        'name_fr'=>'Canard de Chair',     'metrics_enabled'=>$metricsBase, 'kpi_primary'=>'fcr','cycle_days_default'=>70],
            ],
            'mouton'  => [
                ['slug'=>'engraissement','name_fr'=>'Ovin Engraissement',  'metrics_enabled'=>$metricsRumin,'kpi_primary'=>'gmq','cycle_days_default'=>90],
                ['slug'=>'reproducteur', 'name_fr'=>'Ovin Reproducteur',   'metrics_enabled'=>$metricsRumin,'kpi_primary'=>'gmq','cycle_days_default'=>180],
            ],
            'chevre'  => [
                ['slug'=>'engraissement','name_fr'=>'Caprin Engraissement','metrics_enabled'=>$metricsRumin,'kpi_primary'=>'gmq','cycle_days_default'=>90],
                ['slug'=>'laitiere',     'name_fr'=>'Chèvre Laitière',     'metrics_enabled'=>$metricsLait, 'kpi_primary'=>'hdp_lait','cycle_days_default'=>210],
                ['slug'=>'reproducteur', 'name_fr'=>'Bouc Reproducteur',   'metrics_enabled'=>$metricsRumin,'kpi_primary'=>'gmq','cycle_days_default'=>180],
            ],
            'vache'   => [
                ['slug'=>'engraissement','name_fr'=>'Bovin Engraissement', 'metrics_enabled'=>$metricsRumin,'kpi_primary'=>'gmq','cycle_days_default'=>270],
                ['slug'=>'laitiere',     'name_fr'=>'Vache Laitière',      'metrics_enabled'=>$metricsLait, 'kpi_primary'=>'hdp_lait','cycle_days_default'=>305],
                ['slug'=>'reproducteur', 'name_fr'=>'Taureau Reproducteur','metrics_enabled'=>$metricsRumin,'kpi_primary'=>'gmq','cycle_days_default'=>365],
            ],
            'tilapia' => [
                ['slug'=>'grossissement','name_fr'=>'Tilapia Grossissement','metrics_enabled'=>$metricsAqua,'kpi_primary'=>'survie','cycle_days_default'=>180],
                ['slug'=>'alevinage',    'name_fr'=>'Alevinage Tilapia',    'metrics_enabled'=>$metricsAqua,'kpi_primary'=>'survie','cycle_days_default'=>30],
            ],
            'carpe'   => [
                ['slug'=>'grossissement','name_fr'=>'Carpe Grossissement', 'metrics_enabled'=>$metricsAqua,'kpi_primary'=>'survie','cycle_days_default'=>180],
            ],
            'silure'  => [
                ['slug'=>'grossissement','name_fr'=>'Silure Grossissement','metrics_enabled'=>$metricsAqua,'kpi_primary'=>'survie','cycle_days_default'=>150],
            ],
            'lapin'   => [
                ['slug'=>'engraissement','name_fr'=>'Lapin Engraissement', 'metrics_enabled'=>$metricsRumin,'kpi_primary'=>'gmq','cycle_days_default'=>70],
                ['slug'=>'reproducteur', 'name_fr'=>'Lapin Reproducteur',  'metrics_enabled'=>$metricsRumin,'kpi_primary'=>'gmq','cycle_days_default'=>365],
            ],
            'porc'    => [
                ['slug'=>'engraissement','name_fr'=>'Porc Engraissement',  'metrics_enabled'=>$metricsRumin,'kpi_primary'=>'gmq','cycle_days_default'=>150],
                ['slug'=>'reproducteur', 'name_fr'=>'Truie Reproductrice', 'metrics_enabled'=>$metricsRumin,'kpi_primary'=>'gmq','cycle_days_default'=>365],
            ],
        ];

        foreach ($speciesData as $sd) {
            $species = Species::updateOrCreate(['slug' => $sd['slug']], $sd);
            foreach ($productionTypesData[$sd['slug']] ?? [] as $pt) {
                ProductionType::updateOrCreate(
                    ['species_id' => $species->id, 'slug' => $pt['slug']],
                    $pt
                );
            }
        }
    }
}
