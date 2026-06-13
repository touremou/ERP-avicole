<?php

namespace Database\Seeders;

use App\Models\Formula;
use App\Models\FoodNorm;
use App\Models\Farm;
use App\Models\ProductionNorm;
use App\Models\ProductionType;
use App\Models\Protocol;
use App\Models\RawMaterial;
use App\Models\Species;
use Illuminate\Database\Seeder;

/**
 * Référentiels multiespèces : matières premières, normes nutritionnelles,
 * protocoles de prophylaxie, courbes de croissance et formules-types.
 *
 * Valeurs zootechniques INDICATIVES (contexte Afrique de l'Ouest / Guinée) —
 * à ajuster par chaque ferme selon ses souches et ses fournisseurs.
 * Idempotent : updateOrCreate sur des clés naturelles.
 */
class ReferentialSeeder extends Seeder
{
    private ?int $farmId = null;

    public function run(): void
    {
        $this->farmId = Farm::query()->value('id');

        $this->seedRawMaterials();
        $this->seedFoodNorms();
        $this->seedProtocols();
        $this->seedGrowthCurves();
        $this->seedSampleFormulas();
    }

    // ─────────────────────────────────────────────
    // 1. MATIÈRES PREMIÈRES (ingrédients d'aliment)
    // ─────────────────────────────────────────────
    private function seedRawMaterials(): void
    {
        // [nom, unité, EM kcal/kg, PB %, Lys %, Ca %, coût GNF/kg]
        $materials = [
            ['Maïs jaune',            'kg', 3350, 8.0,  0.25, 0.02, 3000],
            ['Son de blé',            'kg', 1300, 15.0, 0.60, 0.10, 2000],
            ['Son de riz',            'kg', 2900, 12.0, 0.50, 0.07, 1500],
            ['Tourteau d\'arachide',  'kg', 2600, 45.0, 1.50, 0.20, 4500],
            ['Tourteau de soja',      'kg', 2400, 46.0, 2.90, 0.30, 6000],
            ['Farine de poisson',     'kg', 2800, 60.0, 4.80, 5.00, 9000],
            ['Coquilles / Calcaire',  'kg', 0,    0.0,  0.0,  38.0, 800],
            ['Phosphate bicalcique',  'kg', 0,    0.0,  0.0,  24.0, 5000],
            ['Drêche de brasserie',   'kg', 2000, 25.0, 0.80, 0.30, 1200],
            ['Manioc (cossettes)',    'kg', 3200, 3.0,  0.10, 0.10, 1800],
            ['Fourrage / Foin',       'kg', 1800, 10.0, 0.40, 0.50, 500],
            ['Luzerne déshydratée',   'kg', 1500, 16.0, 0.70, 1.40, 2500],
            ['Huile de palme',        'kg', 8800, 0.0,  0.0,  0.0,  10000],
            ['CMV (compl. minéral vitaminé)', 'kg', 0, 0.0, 0.0, 12.0, 8000],
            ['Sel',                   'kg', 0,    0.0,  0.0,  0.0,  500],
        ];

        foreach ($materials as [$name, $unit, $em, $pb, $lys, $ca, $cost]) {
            RawMaterial::updateOrCreate(
                ['name' => $name],
                [
                    'farm_id'         => $this->farmId,
                    'unit'            => $unit,
                    'energy_kcal'     => $em,
                    'protein_rate'    => $pb,
                    'lysine_rate'     => $lys,
                    'calcium_rate'    => $ca,
                    'unit_cost'       => $cost,
                    'alert_threshold' => 50,
                    'is_active'       => true,
                ]
            );
        }
    }

    // ─────────────────────────────────────────────
    // 2. NORMES NUTRITIONNELLES (cibles EM/PB par espèce/phase)
    // ─────────────────────────────────────────────
    private function seedFoodNorms(): void
    {
        // [animal_type (clé), nom, phase, EM kcal/kg, PB %, Lys %, Meth %, Ca %, P %, prix cible GNF/kg]
        $norms = [
            ['chair',                  'Poulet de Chair — Démarrage', 'Démarrage', 3000, 22, 1.30, 0.50, 1.00, 0.70, 5500],
            ['chair_finition',         'Poulet de Chair — Finition',  'Finition',  3200, 19, 1.05, 0.42, 0.90, 0.65, 5200],
            ['ponte',                  'Poule Pondeuse — Ponte',      'Ponte',     2750, 17, 0.80, 0.38, 3.80, 0.45, 5000],
            ['dinde_chair',            'Dinde — Chair',               'Croissance',2900, 24, 1.50, 0.55, 1.20, 0.75, 6000],
            ['caille',                 'Caille — Ponte/Chair',        'Standard',  2900, 24, 1.30, 0.50, 2.50, 0.60, 6500],
            ['ovin_engraissement',     'Ovin — Engraissement',        'Finition',  2600, 14, 0.0,  0.0,  0.60, 0.35, 3500],
            ['caprin_engraissement',   'Caprin — Engraissement',      'Finition',  2600, 15, 0.0,  0.0,  0.70, 0.35, 3500],
            ['caprin_laitiere',        'Chèvre — Laitière',           'Lactation', 2700, 16, 0.0,  0.0,  0.90, 0.45, 4000],
            ['tilapia_grossissement',  'Tilapia — Grossissement',     'Grossissement', 3000, 30, 0.0, 0.0, 1.50, 1.00, 7000],
            ['silure_grossissement',   'Silure — Grossissement',      'Grossissement', 3200, 35, 0.0, 0.0, 1.50, 1.00, 8000],
            ['lapin_engraissement',    'Lapin — Engraissement',       'Croissance',2500, 16, 0.70, 0.0, 1.10, 0.60, 4000],
            ['porc_engraissement',     'Porc — Engraissement',        'Croissance',3100, 16, 0.90, 0.0, 0.80, 0.65, 4500],
            ['tilapia_alevinage',      'Tilapia — Alevinage',         'Démarrage', 3200, 38, 0.0,  0.0,  1.50, 1.10, 8500],
            ['caprin_reproducteur',    'Chèvre — Reproducteur',       'Entretien', 2500, 13, 0.0,  0.0,  0.80, 0.40, 3800],
            ['ovin_reproducteur',      'Ovin — Reproducteur',         'Entretien', 2500, 12, 0.0,  0.0,  0.70, 0.38, 3600],
        ];

        foreach ($norms as [$type, $name, $phase, $em, $pb, $lys, $meth, $ca, $p, $price]) {
            FoodNorm::updateOrCreate(
                ['animal_type' => $type, 'phase' => $phase],
                [
                    'farm_id'         => $this->farmId,
                    'name'            => $name,
                    'target_em'       => $em,
                    'target_pb'       => $pb,
                    'target_lys'      => $lys,
                    'target_meth'     => $meth,
                    'target_ca'       => $ca,
                    'target_p'        => $p,
                    'target_price_kg' => $price,
                    'is_active'       => true,
                ]
            );
        }
    }

    // ─────────────────────────────────────────────
    // 3. PROTOCOLES DE PROPHYLAXIE PAR ESPÈCE
    // ─────────────────────────────────────────────
    private function seedProtocols(): void
    {
        // type = slug du type de production ; steps : [jour, action, type, produit, méthode]
        $protocols = [
            [
                'name' => 'Prophylaxie Poulet de Chair', 'type' => 'chair', 'strain' => 'Ross 308',
                'description' => 'Programme avicole chair standard (Newcastle, Gumboro, anticoccidien).',
                'steps' => [
                    [1,  'Anti-stress de démarrage', 'Vitamine',     'Vitamines + électrolytes', 'Eau de boisson'],
                    [7,  'Newcastle + Bronchite (HB1+IB)', 'Vaccin', 'HB1 + IB', 'Œil / eau'],
                    [14, 'Gumboro (Maladie de Gumboro)', 'Vaccin',   'Vaccin Gumboro', 'Eau de boisson'],
                    [21, 'Newcastle rappel (Lasota)', 'Vaccin',      'Lasota', 'Eau de boisson'],
                    [28, 'Anticoccidien préventif', 'Traitement',    'Amprolium', 'Eau de boisson'],
                ],
            ],
            [
                'name' => 'Prophylaxie Poule Pondeuse', 'type' => 'ponte', 'strain' => 'ISA Brown',
                'description' => 'Programme pondeuse (Newcastle, Gumboro, variole, rappels).',
                'steps' => [
                    [7,  'Newcastle + Bronchite', 'Vaccin',   'HB1 + IB', 'Œil / eau'],
                    [14, 'Gumboro', 'Vaccin',                 'Vaccin Gumboro', 'Eau de boisson'],
                    [42, 'Variole aviaire', 'Vaccin',         'Vaccin variole', 'Transfixion aile'],
                    [112,'Newcastle rappel pré-ponte', 'Vaccin', 'Lasota', 'Eau de boisson'],
                ],
            ],
            [
                'name' => 'Prophylaxie Petits Ruminants (Ovins)', 'type' => 'engraissement', 'strain' => 'Djallonké',
                'description' => 'Ovins d\'engraissement : PPR, pasteurellose, déparasitage (Tabaski).',
                'steps' => [
                    [0,  'Déparasitage interne', 'Traitement',  'Albendazole', 'Voie orale'],
                    [0,  'Déparasitage externe', 'Traitement',  'Acaricide', 'Pulvérisation / pour-on'],
                    [7,  'PPR (Peste des Petits Ruminants)', 'Vaccin', 'Vaccin PPR', 'Sous-cutanée'],
                    [14, 'Pasteurellose', 'Vaccin',            'Vaccin pasteurellose', 'Sous-cutanée'],
                    [30, 'Complément minéral & vitaminé', 'Vitamine', 'CMV + oligo-éléments', 'Aliment'],
                    [60, 'Déparasitage rappel', 'Traitement',  'Albendazole', 'Voie orale'],
                ],
            ],
            [
                'name' => 'Prophylaxie Chèvre Laitière', 'type' => 'laitiere', 'strain' => 'Saanen',
                'description' => 'Caprin laitier : PPR, déparasitage, hygiène de traite (mammites).',
                'steps' => [
                    [0,  'Déparasitage interne', 'Traitement',  'Albendazole', 'Voie orale'],
                    [7,  'PPR', 'Vaccin',                       'Vaccin PPR', 'Sous-cutanée'],
                    [14, 'Pasteurellose', 'Vaccin',            'Vaccin pasteurellose', 'Sous-cutanée'],
                    [30, 'Hygiène de traite (prévention mammite)', 'Désinfection', 'Trempage trayons', 'Post-traite'],
                    [30, 'CMV lactation', 'Vitamine',          'CMV calcium', 'Aliment'],
                ],
            ],
            [
                'name' => 'Prophylaxie Lapin', 'type' => 'engraissement', 'strain' => 'Néo-Zélandais',
                'description' => 'Cuniculture : myxomatose, VHD, coccidiose.',
                'steps' => [
                    [0,  'Myxomatose', 'Vaccin',               'Vaccin myxomatose', 'Sous-cutanée'],
                    [0,  'VHD (maladie hémorragique)', 'Vaccin', 'Vaccin VHD', 'Sous-cutanée'],
                    [1,  'Anticoccidien préventif', 'Traitement', 'Toltrazuril', 'Eau de boisson'],
                    [21, 'Vitamines de croissance', 'Vitamine', 'Polyvitamines', 'Eau de boisson'],
                ],
            ],
            [
                'name' => 'Prophylaxie Porc', 'type' => 'engraissement', 'strain' => 'Large White',
                'description' => 'Porcin : fer (anémie), déparasitage, peste porcine.',
                'steps' => [
                    [3,  'Supplément de fer (anémie)', 'Traitement', 'Fer dextran', 'Intramusculaire'],
                    [7,  'Déparasitage', 'Traitement',         'Ivermectine', 'Sous-cutanée'],
                    [30, 'Peste Porcine Classique', 'Vaccin',  'Vaccin PPC', 'Intramusculaire'],
                    [60, 'Déparasitage rappel', 'Traitement',  'Ivermectine', 'Sous-cutanée'],
                ],
            ],
            [
                'name' => 'Prophylaxie Pisciculture (Bassin)', 'type' => 'grossissement', 'strain' => 'Tilapia',
                'description' => 'Aquaculture : désinfection du bassin, bain de sel, vitamine C, suivi qualité d\'eau.',
                'steps' => [
                    [0,  'Désinfection du bassin (chaulage)', 'Désinfection', 'Chaux vive', 'Avant mise en eau'],
                    [1,  'Bain de sel prophylactique', 'Traitement', 'Sel (NaCl) 1-3%', 'Bain à la mise en charge'],
                    [7,  'Vitamine C anti-stress', 'Vitamine',  'Vitamine C', 'Aliment'],
                    [30, 'Contrôle parasitaire externe', 'Traitement', 'Sel / formol vétérinaire', 'Bain court'],
                ],
            ],
        ];

        foreach ($protocols as $p) {
            $protocol = Protocol::updateOrCreate(
                ['name' => $p['name']],
                ['type' => $p['type'], 'strain' => $p['strain'], 'description' => $p['description']]
            );

            // Reconstruction idempotente des étapes.
            $protocol->steps()->delete();
            foreach ($p['steps'] as [$day, $action, $type, $product, $method]) {
                $protocol->steps()->create([
                    'day_number'        => $day,
                    'action_name'       => $action,
                    'type'              => $type,
                    'product_suggested' => $product,
                    'method'            => $method,
                ]);
            }
        }
    }

    // ─────────────────────────────────────────────
    // 4. COURBES DE CROISSANCE (poids cible par semaine) — races locales
    // ─────────────────────────────────────────────
    private function seedGrowthCurves(): void
    {
        // model_name => [batch_type, phase, [semaine => poids cible en grammes]]
        $curves = [
            'Mouton Djallonké'      => ['engraissement', [1 => 18000, 4 => 21000, 8 => 24500, 13 => 28000]],
            'Lapin Néo-Zélandais'   => ['engraissement', [1 => 700, 4 => 1500, 8 => 2300, 10 => 2600]],
            'Porc Large White'      => ['engraissement', [1 => 20000, 8 => 45000, 16 => 90000, 22 => 110000]],
            'Tilapia du Nil'        => ['grossissement',  [1 => 5, 8 => 80, 16 => 250, 24 => 400]],
        ];

        foreach ($curves as $model => [$batchType, $weeks]) {
            foreach ($weeks as $week => $weight) {
                ProductionNorm::updateOrCreate(
                    ['batch_type' => $batchType, 'week_number' => $week, 'model_name' => $model],
                    ['phase_name' => 'Croissance', 'target_weight' => $weight, 'target_laying_rate' => 0]
                );
            }
        }
    }

    // ─────────────────────────────────────────────
    // 5. FORMULES-TYPES (recettes par espèce)
    // ─────────────────────────────────────────────
    private function seedSampleFormulas(): void
    {
        // code => [nom, target_type, species_slug, pt_slug, [matière => %]]
        $recipes = [
            'CH-DEM' => ['Poulet Chair — Démarrage', 'chair', 'poulet', 'chair', [
                'Maïs jaune' => 55, 'Tourteau de soja' => 25, 'Son de blé' => 10,
                'Farine de poisson' => 5, 'Coquilles / Calcaire' => 2, 'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],
            'PO-PONTE' => ['Poule Pondeuse — Ponte', 'ponte', 'poulet', 'ponte', [
                'Maïs jaune' => 55, 'Tourteau de soja' => 18, 'Son de blé' => 12,
                'Coquilles / Calcaire' => 9, 'Farine de poisson' => 3, 'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],
            'CAP-LAIT' => ['Chèvre Laitière — Lactation', 'caprin_laitiere', 'chevre', 'laitiere', [
                'Maïs jaune' => 30, 'Son de blé' => 25, 'Tourteau d\'arachide' => 15,
                'Drêche de brasserie' => 15, 'Fourrage / Foin' => 10, 'CMV (compl. minéral vitaminé)' => 3, 'Sel' => 2,
            ]],
            'OV-ENG' => ['Ovin — Engraissement', 'ovin_engraissement', 'mouton', 'engraissement', [
                'Maïs jaune' => 35, 'Son de blé' => 25, 'Tourteau d\'arachide' => 12,
                'Drêche de brasserie' => 13, 'Fourrage / Foin' => 12, 'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],
            'TIL-GROSS' => ['Tilapia — Grossissement', 'tilapia_grossissement', 'tilapia', 'grossissement', [
                'Farine de poisson' => 30, 'Tourteau de soja' => 25, 'Son de riz' => 20,
                'Maïs jaune' => 20, 'CMV (compl. minéral vitaminé)' => 3, 'Sel' => 2,
            ]],
            'CH-FIN' => ['Poulet Chair — Finition', 'chair_finition', 'poulet', 'chair', [
                'Maïs jaune' => 62, 'Tourteau de soja' => 22, 'Son de blé' => 6,
                'Huile de palme' => 3, 'Coquilles / Calcaire' => 2, 'Phosphate bicalcique' => 2,
                'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],
            'TIL-ALV' => ['Tilapia — Alevinage', 'tilapia_alevinage', 'tilapia', 'alevinage', [
                'Farine de poisson' => 40, 'Tourteau de soja' => 28, 'Son de riz' => 18,
                'Maïs jaune' => 8, 'Huile de palme' => 3, 'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],
            'LAP-ENG' => ['Lapin — Engraissement', 'lapin_engraissement', 'lapin', 'engraissement', [
                'Luzerne déshydratée' => 35, 'Son de blé' => 25, 'Maïs jaune' => 18,
                'Tourteau de soja' => 15, 'Coquilles / Calcaire' => 2, 'CMV (compl. minéral vitaminé)' => 3, 'Sel' => 2,
            ]],
            'POR-ENG' => ['Porc — Engraissement', 'porc_engraissement', 'porc', 'engraissement', [
                'Maïs jaune' => 48, 'Son de blé' => 22, 'Tourteau de soja' => 18,
                'Farine de poisson' => 5, 'Coquilles / Calcaire' => 2, 'Phosphate bicalcique' => 1,
                'CMV (compl. minéral vitaminé)' => 3, 'Sel' => 1,
            ]],
            'CAP-REP' => ['Chèvre — Reproducteur', 'caprin_reproducteur', 'chevre', 'reproducteur', [
                'Maïs jaune' => 32, 'Son de blé' => 25, 'Tourteau d\'arachide' => 12,
                'Fourrage / Foin' => 15, 'Drêche de brasserie' => 11, 'CMV (compl. minéral vitaminé)' => 3, 'Sel' => 2,
            ]],
        ];

        $materials = RawMaterial::pluck('id', 'name');

        foreach ($recipes as $code => [$name, $targetType, $speciesSlug, $ptSlug, $composition]) {
            $species = Species::where('slug', $speciesSlug)->first();
            $pt = $species
                ? ProductionType::where('species_id', $species->id)->where('slug', $ptSlug)->first()
                : null;

            $formula = Formula::updateOrCreate(
                ['code' => $code],
                [
                    'farm_id'            => $this->farmId,
                    'name'               => $name,
                    'target_type'        => $targetType,
                    'species_id'         => $species?->id,
                    'production_type_id' => $pt?->id,
                    'total_batch_weight' => 1000,
                    'is_active'          => true,
                ]
            );

            $formula->items()->delete();
            foreach ($composition as $matName => $pct) {
                if (! isset($materials[$matName])) {
                    continue;
                }
                $formula->items()->create([
                    'farm_id'         => $this->farmId,
                    'raw_material_id' => $materials[$matName],
                    'percentage'      => $pct,
                    'quantity_kg'     => ($pct / 100) * 1000,
                ]);
            }
        }
    }
}
