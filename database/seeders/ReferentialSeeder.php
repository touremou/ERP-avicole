<?php

namespace Database\Seeders;

use App\Models\Batch;
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
        // Les courbes de croissance / normes zootechniques sont désormais
        // gérées exclusivement par ProductionNormSeeder (source unique de
        // vérité). seedGrowthCurves() a été retiré pour éviter les doublons
        // de souches et les lignes sans ration/eau.
        $this->seedSampleFormulas();
        $this->seedFeedPhaseFormulas();
    }

    // ─────────────────────────────────────────────
    // 1. MATIÈRES PREMIÈRES (ingrédients d'aliment)
    // ─────────────────────────────────────────────
    private function seedRawMaterials(): void
    {
        // [nom, unité, EM kcal/kg, PB %, Lys %, Ca %, coût GNF/kg]
        $materials = [
            // Céréales et sous-produits
            ['Maïs jaune',            'kg', 3350, 8.0,  0.25, 0.02, 3000],
            ['Son de blé',            'kg', 1300, 15.0, 0.60, 0.10, 2000],
            ['Son de riz',            'kg', 2900, 12.0, 0.50, 0.07, 1500],
            ['Manioc (cossettes)',    'kg', 3200, 3.0,  0.10, 0.10, 1800],

            // Sources Protéiques (Végétales & Animales)
            ['Tourteau d\'arachide',  'kg', 2600, 45.0, 1.50, 0.20, 4500],
            ['Tourteau de soja',      'kg', 2400, 46.0, 2.90, 0.30, 6000],
            ['Tourteau de palmiste',  'kg', 2200, 15.0, 0.50, 0.25, 2000], // Très utilisé en AO
            ['Graines de coton',      'kg', 2800, 22.0, 0.90, 0.15, 2500], // Ruminants
            ['Farine de poisson',     'kg', 2800, 60.0, 4.80, 5.00, 9000],
            ['Farine de sang',        'kg', 3200, 80.0, 7.00, 0.30, 8000],

            // Minéraux et Additifs
            ['Coquilles / Calcaire',  'kg', 0,    0.0,  0.0,  38.0, 800],
            ['Phosphate bicalcique',  'kg', 0,    0.0,  0.0,  24.0, 5000],
            ['Poudre d\'os',          'kg', 0,    12.0, 0.0,  28.0, 3000],
            ['Sel',                   'kg', 0,    0.0,  0.0,  0.0,  500],
            ['CMV (compl. minéral vitaminé)', 'kg', 0, 0.0, 0.0, 12.0, 8000],

            // Fibres, Énergie liquide et Ruminants
            ['Drêche de brasserie',   'kg', 2000, 25.0, 0.80, 0.30, 1200],
            ['Fourrage / Foin',       'kg', 1800, 10.0, 0.40, 0.50, 500],
            ['Luzerne déshydratée',   'kg', 1500, 16.0, 0.70, 1.40, 2500],
            ['Huile de palme',        'kg', 8800, 0.0,  0.0,  0.0,  10000],
            ['Mélasse',               'kg', 2500, 3.0,  0.0,  0.80, 1500], // Énergie ruminants/porcs
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
                    'alert_threshold' => 100, // Augmenté pour éviter trop d'alertes
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
            // Volaille
            ['chair',                  'Poulet de Chair — Démarrage', 'Démarrage', 3000, 22, 1.30, 0.50, 1.00, 0.70, 5500],
            ['chair_croissance',       'Poulet de Chair — Croissance','Croissance',3100, 20, 1.15, 0.45, 0.95, 0.65, 5300],
            ['chair_finition',         'Poulet de Chair — Finition',  'Finition',  3200, 19, 1.05, 0.42, 0.90, 0.65, 5200],
            ['ponte_demarrage',        'Poule Pondeuse — Démarrage',  'Démarrage', 2900, 20, 1.00, 0.40, 1.00, 0.70, 5200],
            ['ponte',                  'Poule Pondeuse — Ponte',      'Ponte',     2750, 17, 0.80, 0.38, 3.80, 0.45, 5000],
            ['dinde_demarrage',        'Dinde — Démarrage',           'Démarrage', 2800, 28, 1.60, 0.60, 1.20, 0.80, 6500],
            ['dinde_chair',            'Dinde — Croissance',          'Croissance',2900, 24, 1.50, 0.55, 1.20, 0.75, 6000],
            ['caille_demarrage',       'Caille — Démarrage',          'Démarrage', 2900, 28, 1.40, 0.55, 1.00, 0.60, 6800],
            ['caille',                 'Caille — Ponte',              'Ponte',     2800, 22, 1.10, 0.45, 2.80, 0.55, 6200],

            // Ruminants
            ['bovin_laitier',          'Bovin — Vache Laitière',      'Lactation', 2800, 16, 0.0,  0.0,  0.90, 0.50, 3800],
            ['bovin_engraissement',    'Bovin — Engraissement',       'Finition',  2700, 13, 0.0,  0.0,  0.60, 0.40, 3200],
            ['ovin_engraissement',     'Ovin — Engraissement',        'Finition',  2600, 14, 0.0,  0.0,  0.60, 0.35, 3500],
            ['ovin_reproducteur',      'Ovin — Reproducteur',         'Entretien', 2500, 12, 0.0,  0.0,  0.70, 0.38, 3600],
            ['caprin_engraissement',   'Caprin — Engraissement',      'Finition',  2600, 15, 0.0,  0.0,  0.70, 0.35, 3500],
            ['caprin_laitiere',        'Chèvre — Laitière',           'Lactation', 2700, 16, 0.0,  0.0,  0.90, 0.45, 4000],
            ['caprin_reproducteur',    'Chèvre — Reproducteur',       'Entretien', 2500, 13, 0.0,  0.0,  0.80, 0.40, 3800],

            // Porcs & Lapins
            ['porc_demarrage',         'Porcelet — Démarrage',        'Démarrage', 3200, 20, 1.20, 0.40, 0.90, 0.70, 5500],
            ['porc_engraissement',     'Porc — Engraissement',        'Croissance',3100, 16, 0.90, 0.0,  0.80, 0.65, 4500],
            ['porc_maternite',         'Truie — Allaitante',          'Lactation', 3200, 17, 0.95, 0.0,  0.90, 0.75, 4800],
            ['lapin_engraissement',    'Lapin — Engraissement',       'Croissance',2500, 16, 0.70, 0.0,  1.10, 0.60, 4000],
            ['lapin_maternite',        'Lapine — Allaitante',         'Lactation', 2600, 18, 0.80, 0.0,  1.20, 0.70, 4200],

            // Aquaculture
            ['tilapia_alevinage',      'Tilapia — Alevinage',         'Démarrage', 3200, 38, 0.0,  0.0,  1.50, 1.10, 8500],
            ['tilapia_grossissement',  'Tilapia — Grossissement',     'Grossissement', 3000, 30, 0.0, 0.0, 1.50, 1.00, 7000],
            ['silure_alevinage',       'Silure — Alevinage',          'Démarrage', 3300, 45, 0.0,  0.0,  1.60, 1.20, 9500],
            ['silure_grossissement',   'Silure — Grossissement',      'Grossissement', 3200, 35, 0.0, 0.0, 1.50, 1.00, 8000],

            // ── Normes génériques par phase de secteur (cf. Batch::FEED_PHASES) ──
            // Ponte (phases manquantes ; 'ponte_demarrage' et 'ponte' existent déjà)
            ['ponte_croissance',       'Ponte — Croissance (Poulette)','Croissance', 2850, 16, 0.75, 0.35, 0.90, 0.55, 4800],
            ['ponte_entretien',        'Ponte — Entretien (Phase 2)', 'Entretien', 2700, 15.5, 0.70, 0.34, 3.60, 0.42, 4700],

            // Reproducteur (ruminants/porc/lapin reproducteurs)
            ['reproducteur_entretien', 'Reproducteur — Entretien',    'Entretien', 2400, 12, 0.0,  0.0,  0.50, 0.30, 3000],
            ['reproducteur_gestation', 'Reproducteur — Gestation',    'Gestation', 2500, 13, 0.0,  0.0,  0.60, 0.35, 3200],
            ['reproducteur_lactation', 'Reproducteur — Lactation',    'Lactation', 2700, 15, 0.0,  0.0,  0.90, 0.45, 3600],

            // Engraissement (générique multiespèce ruminants/porc/lapin)
            ['engraissement_demarrage','Engraissement — Démarrage',   'Démarrage', 2700, 16, 0.0,  0.0,  0.70, 0.40, 3800],
            ['engraissement_croissance','Engraissement — Croissance', 'Croissance', 2650, 15, 0.0,  0.0,  0.65, 0.38, 3600],
            ['engraissement_finition', 'Engraissement — Finition',    'Finition', 2600, 13, 0.0,  0.0,  0.60, 0.35, 3400],

            // Laitière (générique bovins/caprins)
            ['laitiere_preparation',   'Laitière — Préparation vêlage','Préparation', 2700, 14, 0.0, 0.0, 0.60, 0.40, 3700],
            ['laitiere_lactation',     'Laitière — Lactation',        'Lactation', 2850, 17, 0.0,  0.0,  1.00, 0.55, 4200],
            ['laitiere_tarissement',   'Laitière — Tarissement',      'Tarissement', 2400, 12, 0.0, 0.0, 0.40, 0.30, 3000],

            // Grossissement (générique aquaculture)
            ['grossissement_pre',      'Grossissement — Pré-grossissement','Pré-grossissement', 3000, 35, 0.0, 0.0, 1.60, 1.10, 7800],
            ['grossissement',          'Grossissement — Grossissement','Grossissement', 2950, 28, 0.0, 0.0, 1.40, 1.00, 7000],
            ['grossissement_finition', 'Grossissement — Finition',    'Finition', 2900, 25, 0.0,  0.0,  1.30, 0.90, 6500],

            // Alevinage (générique aquaculture)
            ['alevinage_1',            'Alevinage — 1er âge',         '1er âge', 3300, 42, 0.0,  0.0,  1.60, 1.20, 9800],
            ['alevinage_2',            'Alevinage — 2e âge',          '2e âge', 3200, 38, 0.0,  0.0,  1.50, 1.10, 9000],
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
        $protocols = [
            // VOLAILLE
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
                    [1,  'Complexe Vitaminé Démarrage', 'Vitamine',  'AD3E + C', 'Eau de boisson'],
                    [7,  'Newcastle + Bronchite', 'Vaccin',   'HB1 + IB', 'Œil / eau'],
                    [14, 'Gumboro', 'Vaccin',                 'Vaccin Gumboro', 'Eau de boisson'],
                    [42, 'Variole aviaire', 'Vaccin',         'Vaccin variole', 'Transfixion aile'],
                    [56, 'Déparasitage interne', 'Traitement','Levamisole', 'Eau de boisson'],
                    [112,'Newcastle rappel pré-ponte', 'Vaccin', 'Lasota', 'Eau de boisson'],
                ],
            ],
            [
                'name' => 'Prophylaxie Dinde', 'type' => 'chair', 'strain' => 'B.U.T. 6',
                'description' => 'Programme dindonneau (sensible à l\'histomonose et mycoplasmes).',
                'steps' => [
                    [1,  'Antibiotique démarrage (Mycoplasmes)', 'Traitement', 'Tylosine', 'Eau de boisson'],
                    [14, 'Newcastle', 'Vaccin',              'Pestis', 'Eau de boisson'],
                    [28, 'Variole aviaire', 'Vaccin',        'Vaccin Variole', 'Transfixion aile'],
                    [35, 'Prévention Histomonose (Crise rouge)', 'Traitement', 'Dimétridazole', 'Aliment / Eau'],
                ],
            ],
            [
                'name' => 'Prophylaxie Caille', 'type' => 'ponte', 'strain' => 'Coturnix',
                'description' => 'Programme coturniculture (oiseau très rustique, surtout des vitamines).',
                'steps' => [
                    [1,  'Vitamines Démarrage', 'Vitamine',  'Complexe B + AD3E', 'Eau de boisson'],
                    [15, 'Anticoccidien léger', 'Traitement','Amprolium', 'Eau de boisson'],
                    [35, 'Vitamines Pré-ponte', 'Vitamine',  'Calcium + Vit D3', 'Eau de boisson'],
                ],
            ],

            // RUMINANTS
            [
                'name' => 'Prophylaxie Bovin Engraissement', 'type' => 'engraissement', 'strain' => 'N\'Dama / Zébu',
                'description' => 'Programme taurillon : Déparasitage, Charbon, Pasteurellose.',
                'steps' => [
                    [0,  'Déparasitage interne/externe', 'Traitement',  'Ivermectine 1%', 'Sous-cutanée'],
                    [7,  'Pasteurellose bovine', 'Vaccin',              'Vaccin Pasteurellose', 'Sous-cutanée'],
                    [14, 'Charbon symptomatique/bactéridien', 'Vaccin', 'Vaccin Charbon', 'Sous-cutanée'],
                    [21, 'Cure de vitamines (Trypanosomiase)', 'Traitement', 'Trypamidium + Vitamines', 'Intramusculaire'],
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

            // AUTRES MAMMIFÈRES
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
                'description' => 'Porcin : fer (anémie), déparasitage, rouget/peste.',
                'steps' => [
                    [3,  'Supplément de fer (anémie)', 'Traitement', 'Fer dextran', 'Intramusculaire'],
                    [7,  'Déparasitage', 'Traitement',         'Ivermectine', 'Sous-cutanée'],
                    [30, 'Peste Porcine Classique', 'Vaccin',  'Vaccin PPC', 'Intramusculaire'],
                    [45, 'Rouget du porc', 'Vaccin',           'Vaccin Rouget', 'Intramusculaire'],
                    [60, 'Déparasitage rappel', 'Traitement',  'Ivermectine', 'Sous-cutanée'],
                ],
            ],

            // AQUACULTURE
            [
                'name' => 'Prophylaxie Pisciculture (Bassin)', 'type' => 'grossissement', 'strain' => 'Tilapia',
                'description' => 'Aquaculture : désinfection du bassin, bain de sel, vitamine C.',
                'steps' => [
                    [0,  'Désinfection du bassin (chaulage)', 'Désinfection', 'Chaux vive', 'Avant mise en eau'],
                    [1,  'Bain de sel prophylactique', 'Traitement', 'Sel (NaCl) 1-3%', 'Bain à la mise en charge'],
                    [7,  'Vitamine C anti-stress', 'Vitamine',  'Vitamine C', 'Aliment'],
                    [30, 'Contrôle parasitaire externe', 'Traitement', 'Sel / formol vétérinaire', 'Bain court'],
                ],
            ],
            [
                'name' => 'Gestion Sanitaire Silure', 'type' => 'grossissement', 'strain' => 'Clarias',
                'description' => 'Silure : Forte densité, tri obligatoire contre le cannibalisme, désinfection plaies.',
                'steps' => [
                    [0,  'Désinfection bassin', 'Désinfection', 'Permanganate de potassium', 'Avant mise en eau'],
                    [14, 'Tri par taille (Anti-cannibalisme)', 'Traitement', 'Filets de tri', 'Bassin'],
                    [21, 'Désinfection des plaies post-tri', 'Traitement', 'Bleu de méthylène', 'Bain court'],
                ],
            ],
        ];

        foreach ($protocols as $p) {
            $protocol = Protocol::updateOrCreate(
                ['name' => $p['name']],
                ['type' => $p['type'], 'strain' => $p['strain'], 'description' => $p['description']]
            );

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
    // 4. COURBES DE CROISSANCE → déplacées vers ProductionNormSeeder
    //    (source unique de vérité du référentiel zootechnique).
    // ─────────────────────────────────────────────

    // ─────────────────────────────────────────────
    // 5. FORMULES-TYPES (recettes par espèce)
    // ─────────────────────────────────────────────
    private function seedSampleFormulas(): void
    {
        // code => [nom, target_type, species_slug, pt_slug, [matière => %]]
        $recipes = [
            // VOLAILLES
            'CH-DEM' => ['Poulet Chair — Démarrage', 'chair', 'poulet', 'chair', [
                'Maïs jaune' => 55, 'Tourteau de soja' => 25, 'Son de blé' => 10,
                'Farine de poisson' => 5, 'Coquilles / Calcaire' => 2, 'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],
            'CH-FIN' => ['Poulet Chair — Finition', 'chair_finition', 'poulet', 'chair', [
                'Maïs jaune' => 62, 'Tourteau de soja' => 22, 'Son de blé' => 6,
                'Huile de palme' => 3, 'Coquilles / Calcaire' => 2, 'Phosphate bicalcique' => 2,
                'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],
            'PO-PONTE' => ['Poule Pondeuse — Ponte', 'ponte', 'poulet', 'ponte', [
                'Maïs jaune' => 55, 'Tourteau de soja' => 18, 'Son de blé' => 12,
                'Coquilles / Calcaire' => 9, 'Farine de poisson' => 3, 'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],
            'DIN-CROIS' => ['Dinde — Croissance', 'dinde_chair', 'dinde', 'chair', [
                'Maïs jaune' => 50, 'Tourteau de soja' => 30, 'Farine de poisson' => 8,
                'Son de blé' => 7, 'Huile de palme' => 2, 'Coquilles / Calcaire' => 1.5, 'CMV (compl. minéral vitaminé)' => 1.5,
            ]],
            'CAI-PONTE' => ['Caille — Ponte', 'caille', 'caille', 'ponte', [
                'Maïs jaune' => 50, 'Tourteau de soja' => 25, 'Son de blé' => 10, 'Farine de poisson' => 5,
                'Coquilles / Calcaire' => 8, 'CMV (compl. minéral vitaminé)' => 1.5, 'Sel' => 0.5,
            ]],

            // MAMMIFÈRES
            'BOV-ENG' => ['Bovin — Engraissement', 'bovin_engraissement', 'vache', 'engraissement', [
                'Son de blé' => 35, 'Graines de coton' => 25, 'Maïs jaune' => 20,
                'Mélasse' => 10, 'Fourrage / Foin' => 8, 'CMV (compl. minéral vitaminé)' => 1, 'Sel' => 1,
            ]],
            'OV-ENG' => ['Ovin — Engraissement', 'ovin_engraissement', 'mouton', 'engraissement', [
                'Maïs jaune' => 35, 'Son de blé' => 25, 'Tourteau d\'arachide' => 12,
                'Drêche de brasserie' => 13, 'Fourrage / Foin' => 12, 'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],
            'CAP-LAIT' => ['Chèvre Laitière — Lactation', 'caprin_laitiere', 'chevre', 'laitiere', [
                'Maïs jaune' => 30, 'Son de blé' => 25, 'Tourteau d\'arachide' => 15,
                'Drêche de brasserie' => 15, 'Fourrage / Foin' => 10, 'CMV (compl. minéral vitaminé)' => 3, 'Sel' => 2,
            ]],
            'LAP-ENG' => ['Lapin — Engraissement', 'lapin_engraissement', 'lapin', 'engraissement', [
                'Luzerne déshydratée' => 35, 'Son de blé' => 25, 'Maïs jaune' => 18,
                'Tourteau de soja' => 15, 'Coquilles / Calcaire' => 2, 'CMV (compl. minéral vitaminé)' => 3, 'Sel' => 2,
            ]],
            'POR-ENG' => ['Porc — Engraissement', 'porc_engraissement', 'porc', 'engraissement', [
                'Maïs jaune' => 45, 'Son de blé' => 20, 'Tourteau de palmiste' => 10, 'Tourteau de soja' => 15,
                'Farine de poisson' => 5, 'Coquilles / Calcaire' => 2, 'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],
            'POR-TRU' => ['Truie — Lactation', 'porc_maternite', 'porc', 'reproducteur', [
                'Maïs jaune' => 50, 'Tourteau de soja' => 18, 'Son de blé' => 15, 'Farine de poisson' => 8,
                'Mélasse' => 5, 'Coquilles / Calcaire' => 2, 'CMV (compl. minéral vitaminé)' => 1.5, 'Sel' => 0.5,
            ]],

            // AQUACULTURE
            'TIL-GROSS' => ['Tilapia — Grossissement', 'tilapia_grossissement', 'tilapia', 'grossissement', [
                'Farine de poisson' => 25, 'Tourteau de soja' => 25, 'Son de riz' => 20,
                'Tourteau d\'arachide' => 10, 'Maïs jaune' => 15, 'CMV (compl. minéral vitaminé)' => 3, 'Sel' => 2,
            ]],
            'SIL-GROSS' => ['Silure — Grossissement', 'silure_grossissement', 'silure', 'grossissement', [
                'Farine de poisson' => 35, 'Tourteau de soja' => 20, 'Farine de sang' => 10,
                'Son de blé' => 15, 'Maïs jaune' => 15, 'Huile de palme' => 3, 'CMV (compl. minéral vitaminé)' => 2,
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

    // ─────────────────────────────────────────────
    // 6. FORMULES GÉNÉRIQUES PAR PHASE D'ALIMENT
    //    (1 recette par phase de Batch::FEED_PHASES, cf. target_type
    //    alignés sur les FoodNorm génériques ajoutées dans seedFoodNorms)
    // ─────────────────────────────────────────────
    private function seedFeedPhaseFormulas(): void
    {
        // secteur => [species_slug, production_type_slug] représentatifs
        $sectorPt = [
            'Chair'         => ['poulet', 'chair'],
            'Ponte'         => ['poulet', 'ponte'],
            'Reproducteur'  => ['mouton', 'reproducteur'],
            'Engraissement' => ['mouton', 'engraissement'],
            'Laitière'      => ['vache', 'laitiere'],
            'Grossissement' => ['tilapia', 'grossissement'],
            'Alevinage'     => ['tilapia', 'alevinage'],
        ];

        // code => [secteur, nom de phase (Batch::FEED_PHASES), target_type (FoodNorm.animal_type), [matière => %]]
        $recipes = [
            // ── Chair ──
            'FEED-CH-DEM' => ['Chair', 'Chair Démarrage', 'chair', [
                'Maïs jaune' => 50, 'Tourteau de soja' => 28, 'Son de blé' => 10, 'Farine de poisson' => 5,
                'Huile de palme' => 1, 'Coquilles / Calcaire' => 2, 'Phosphate bicalcique' => 1, 'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],
            'FEED-CH-CRO' => ['Chair', 'Chair Croissance', 'chair_croissance', [
                'Maïs jaune' => 55, 'Tourteau de soja' => 24, 'Son de blé' => 10, 'Farine de poisson' => 4,
                'Huile de palme' => 2, 'Coquilles / Calcaire' => 2, 'Phosphate bicalcique' => 1, 'CMV (compl. minéral vitaminé)' => 1.5, 'Sel' => 0.5,
            ]],
            'FEED-CH-FIN' => ['Chair', 'Chair Finition', 'chair_finition', [
                'Maïs jaune' => 62, 'Tourteau de soja' => 22, 'Son de blé' => 6, 'Huile de palme' => 3,
                'Coquilles / Calcaire' => 2, 'Phosphate bicalcique' => 2, 'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],

            // ── Ponte ──
            'FEED-PO-DEM' => ['Ponte', 'Ponte Démarrage (Poussin)', 'ponte_demarrage', [
                'Maïs jaune' => 52, 'Tourteau de soja' => 26, 'Son de blé' => 12, 'Farine de poisson' => 4,
                'Coquilles / Calcaire' => 3, 'Phosphate bicalcique' => 1, 'CMV (compl. minéral vitaminé)' => 1.5, 'Sel' => 0.5,
            ]],
            'FEED-PO-CRO' => ['Ponte', 'Ponte Croissance (Poulette)', 'ponte_croissance', [
                'Maïs jaune' => 50, 'Son de blé' => 21.5, 'Tourteau de soja' => 16, 'Tourteau de palmiste' => 6,
                'Coquilles / Calcaire' => 4, 'Phosphate bicalcique' => 1, 'CMV (compl. minéral vitaminé)' => 1, 'Sel' => 0.5,
            ]],
            'FEED-PO-PIC' => ['Ponte', 'Ponte 1 (Pic de ponte)', 'ponte', [
                'Maïs jaune' => 55, 'Tourteau de soja' => 18, 'Son de blé' => 12, 'Coquilles / Calcaire' => 9,
                'Farine de poisson' => 3, 'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],
            'FEED-PO-ENT' => ['Ponte', 'Ponte 2 (Entretien)', 'ponte_entretien', [
                'Maïs jaune' => 50, 'Son de blé' => 20, 'Tourteau de soja' => 14, 'Tourteau de palmiste' => 5,
                'Coquilles / Calcaire' => 9, 'Phosphate bicalcique' => 0.5, 'CMV (compl. minéral vitaminé)' => 1, 'Sel' => 0.5,
            ]],

            // ── Reproducteur ──
            'FEED-REP-ENT' => ['Reproducteur', 'Reproducteur Entretien', 'reproducteur_entretien', [
                'Son de blé' => 35, 'Maïs jaune' => 20, 'Tourteau d\'arachide' => 10, 'Fourrage / Foin' => 28,
                'Drêche de brasserie' => 5, 'CMV (compl. minéral vitaminé)' => 1, 'Sel' => 1,
            ]],
            'FEED-REP-GES' => ['Reproducteur', 'Reproducteur Gestation', 'reproducteur_gestation', [
                'Maïs jaune' => 25, 'Son de blé' => 30, 'Tourteau d\'arachide' => 12, 'Fourrage / Foin' => 25,
                'Drêche de brasserie' => 5, 'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],
            'FEED-REP-LAC' => ['Reproducteur', 'Reproducteur Lactation', 'reproducteur_lactation', [
                'Maïs jaune' => 30, 'Son de blé' => 25, 'Tourteau d\'arachide' => 15, 'Tourteau de soja' => 5,
                'Fourrage / Foin' => 18, 'Drêche de brasserie' => 4, 'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],

            // ── Engraissement ──
            'FEED-ENG-DEM' => ['Engraissement', 'Engraissement Démarrage', 'engraissement_demarrage', [
                'Maïs jaune' => 35, 'Son de blé' => 25, 'Tourteau d\'arachide' => 12, 'Drêche de brasserie' => 13,
                'Fourrage / Foin' => 12, 'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],
            'FEED-ENG-CRO' => ['Engraissement', 'Engraissement Croissance', 'engraissement_croissance', [
                'Maïs jaune' => 33, 'Son de blé' => 27, 'Tourteau d\'arachide' => 10, 'Drêche de brasserie' => 14,
                'Fourrage / Foin' => 14, 'CMV (compl. minéral vitaminé)' => 1.5, 'Sel' => 0.5,
            ]],
            'FEED-ENG-FIN' => ['Engraissement', 'Engraissement Finition', 'engraissement_finition', [
                'Maïs jaune' => 30, 'Son de blé' => 28, 'Tourteau d\'arachide' => 7, 'Drêche de brasserie' => 15,
                'Fourrage / Foin' => 17, 'Mélasse' => 2, 'CMV (compl. minéral vitaminé)' => 1,
            ]],

            // ── Laitière ──
            'FEED-LAI-PREP' => ['Laitière', 'Laitière Préparation vêlage', 'laitiere_preparation', [
                'Son de blé' => 30, 'Maïs jaune' => 22, 'Tourteau d\'arachide' => 10, 'Drêche de brasserie' => 15,
                'Fourrage / Foin' => 18, 'Mélasse' => 3, 'CMV (compl. minéral vitaminé)' => 1.5, 'Sel' => 0.5,
            ]],
            'FEED-LAI-LAC' => ['Laitière', 'Laitière Lactation', 'laitiere_lactation', [
                'Maïs jaune' => 28, 'Son de blé' => 22, 'Tourteau d\'arachide' => 15, 'Tourteau de soja' => 8,
                'Drêche de brasserie' => 12, 'Fourrage / Foin' => 10, 'Mélasse' => 3, 'CMV (compl. minéral vitaminé)' => 1.5, 'Sel' => 0.5,
            ]],
            'FEED-LAI-TAR' => ['Laitière', 'Laitière Tarissement', 'laitiere_tarissement', [
                'Son de blé' => 35, 'Maïs jaune' => 15, 'Tourteau d\'arachide' => 8, 'Fourrage / Foin' => 35,
                'Mélasse' => 5, 'CMV (compl. minéral vitaminé)' => 1.5, 'Sel' => 0.5,
            ]],

            // ── Grossissement ──
            'FEED-GRO-PRE' => ['Grossissement', 'Grossissement Pré-grossissement', 'grossissement_pre', [
                'Farine de poisson' => 30, 'Tourteau de soja' => 28, 'Son de riz' => 18, 'Tourteau d\'arachide' => 10,
                'Maïs jaune' => 8, 'Huile de palme' => 1, 'CMV (compl. minéral vitaminé)' => 3, 'Sel' => 2,
            ]],
            'FEED-GRO-GRO' => ['Grossissement', 'Grossissement Grossissement', 'grossissement', [
                'Farine de poisson' => 25, 'Tourteau de soja' => 25, 'Son de riz' => 20, 'Tourteau d\'arachide' => 10,
                'Maïs jaune' => 15, 'CMV (compl. minéral vitaminé)' => 3, 'Sel' => 2,
            ]],
            'FEED-GRO-FIN' => ['Grossissement', 'Grossissement Finition', 'grossissement_finition', [
                'Farine de poisson' => 18, 'Tourteau de soja' => 22, 'Son de riz' => 25, 'Tourteau d\'arachide' => 10,
                'Maïs jaune' => 20, 'Huile de palme' => 2, 'CMV (compl. minéral vitaminé)' => 2, 'Sel' => 1,
            ]],

            // ── Alevinage ──
            'FEED-ALE-1' => ['Alevinage', 'Alevinage 1er âge', 'alevinage_1', [
                'Farine de poisson' => 38, 'Tourteau de soja' => 30, 'Farine de sang' => 8, 'Son de riz' => 15,
                'Maïs jaune' => 5, 'Huile de palme' => 1, 'CMV (compl. minéral vitaminé)' => 3,
            ]],
            'FEED-ALE-2' => ['Alevinage', 'Alevinage 2e âge', 'alevinage_2', [
                'Farine de poisson' => 32, 'Tourteau de soja' => 28, 'Farine de sang' => 6, 'Son de riz' => 18,
                'Maïs jaune' => 10, 'Huile de palme' => 2, 'CMV (compl. minéral vitaminé)' => 2.5, 'Sel' => 1.5,
            ]],
        ];

        $materials = RawMaterial::pluck('id', 'name');

        foreach ($recipes as $code => [$sector, $name, $targetType, $composition]) {
            [$speciesSlug, $ptSlug] = $sectorPt[$sector];

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
