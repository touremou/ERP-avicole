<?php

namespace Database\Seeders;

use App\Models\CropSpecies;
use Illuminate\Database\Seeder;

/**
 * CATALOGUE AGRONOMIQUE DE RÉFÉRENCE — GUINÉE.
 *
 * Référentiel partagé (non multi-ferme) des espèces cultivées en Guinée et de
 * leurs principales variétés, avec durée de cycle et rendement de référence
 * (conditions paysannes/améliorées guinéennes). Sert à pré-remplir un cycle
 * de culture (date de récolte estimée, rendement attendu) et à benchmarker
 * le rendement réel.
 *
 * Idempotent : updateOrCreate par nom d'espèce et par nom de variété.
 * Les durées (jours) et rendements (t/ha) sont des ordres de grandeur de
 * référence, à affiner localement par exploitation.
 */
class CropCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalogue() as $row) {
            $varieties = $row['varieties'] ?? [];
            unset($row['varieties']);

            $species = CropSpecies::updateOrCreate(
                ['name' => $row['name']],
                array_merge($row, ['is_active' => true]),
            );

            foreach ($varieties as $v) {
                $species->varieties()->updateOrCreate(['name' => $v['name']], $this->variety($v));
            }
        }

        // Variétés complémentaires rattachées à des espèces existantes.
        foreach ($this->complements() as $speciesName => $varieties) {
            $species = CropSpecies::where('name', $speciesName)->first();
            if (! $species) {
                continue;
            }
            foreach ($varieties as $v) {
                $species->varieties()->updateOrCreate(['name' => $v['name']], $this->variety($v));
            }
        }

        $this->command?->info('✅ Catalogue cultures de référence (Guinée) : ' . CropSpecies::count() . ' espèces.');
    }

    /**
     * Normalise une variété : la table crop_varieties n'a pas de colonne
     * `description` — on replie l'info descriptive et le `cycle_type` éventuel
     * dans `notes`.
     */
    private function variety(array $v): array
    {
        $notes = $v['description'] ?? null;
        if (! empty($v['cycle_type'])) {
            $prefix = ucfirst($v['cycle_type']) . '.';
            $notes = $notes ? $prefix . ' ' . $notes : $prefix;
        }

        return [
            'name'          => $v['name'],
            'cycle_days'    => $v['cycle_days'] ?? null,
            'avg_yield_tha' => $v['avg_yield_tha'] ?? null,
            'cycle_type'    => $v['cycle_type'] ?? null,
            'notes'         => $notes,
        ];
    }

    /**
     * Variétés additionnelles à rattacher à des espèces DÉJÀ présentes dans le
     * catalogue (nom d'espèce existant => variétés). Idempotent.
     */
    private function complements(): array
    {
        return [
            'Mangue' => [
                ['name' => 'Brooks', 'cycle_days' => null, 'avg_yield_tha' => 11.0, 'description' => 'Variété tardive, gros fruits, moins fibreuse.'],
                ['name' => 'Zill', 'cycle_days' => null, 'avg_yield_tha' => 10.0, 'cycle_type' => 'précoce'],
                ['name' => 'Mangue locale (Greffée)', 'cycle_days' => null, 'avg_yield_tha' => 9.0, 'description' => 'Variétés locales sélectionnées et greffées.'],
            ],
            'Ananas' => [
                ['name' => 'MD2 (Extra Sweet)', 'cycle_days' => 420, 'avg_yield_tha' => 65.0, 'description' => 'Variété hybride très sucrée, forte demande export.'],
            ],
            'Avocat' => [
                ['name' => 'Hass', 'cycle_days' => null, 'avg_yield_tha' => 12.0, 'description' => 'Petits fruits à peau rugueuse, standard export.'],
                ['name' => 'Fuerte', 'cycle_days' => null, 'avg_yield_tha' => 10.0, 'description' => 'Fruits en forme de poire, peau lisse.'],
                ['name' => 'Pollock', 'cycle_days' => null, 'avg_yield_tha' => 14.0, 'cycle_type' => 'précoce', 'description' => 'Très gros fruits, adaptés au marché local.'],
            ],
            'Banane plantain' => [
                ['name' => 'Big Ebanga', 'cycle_days' => 350, 'avg_yield_tha' => 22.0],
                ['name' => 'Corne', 'cycle_days' => 380, 'avg_yield_tha' => 18.0],
            ],
            'Riz' => [
                ['name' => 'ROK 5', 'cycle_days' => 130, 'avg_yield_tha' => 3.5, 'description' => 'Adapté à la riziculture de mangrove.'],
                ['name' => 'BG 90-2', 'cycle_days' => 135, 'avg_yield_tha' => 4.0, 'description' => 'Bon potentiel en bas-fonds irrigués.'],
            ],
            'Maïs' => [
                ['name' => 'QPM (Kassa)', 'cycle_days' => 105, 'avg_yield_tha' => 3.5, 'description' => 'Maïs à haute qualité protéique.'],
            ],
            'Pomme de terre' => [
                ['name' => 'Nicola', 'cycle_days' => 100, 'avg_yield_tha' => 20.0],
                ['name' => 'Kondor', 'cycle_days' => 110, 'avg_yield_tha' => 22.0],
            ],
            'Tomate' => [
                ['name' => 'Padma', 'cycle_days' => 90, 'avg_yield_tha' => 25.0, 'description' => 'Tolérante à la chaleur.'],
            ],
        ];
    }

    /**
     * Le référentiel. cycle_days = du semis/plantation à la récolte.
     * Pour les pérennes (manguier, ananas, agrumes…), cycle_days reflète le
     * délai d'entrée en production / la durée d'un cycle de fructification.
     */
    private function catalogue(): array
    {
        return [
            // ── CÉRÉALES ───────────────────────────────────────────────────
            ['type' => 'cereale', 'name' => 'Riz', 'local_name' => 'Malo', 'family' => 'Poaceae',
                'cycle_days_min' => 110, 'cycle_days_max' => 150, 'avg_yield_tha' => 3.5,
                'description' => 'Céréale de base en Guinée (riz pluvial, bas-fonds et mangrove).',
                'varieties' => [
                    ['name' => 'NERICA', 'cycle_days' => 120, 'avg_yield_tha' => 4.0, 'cycle_type' => 'précoce'],
                    ['name' => 'CK4', 'cycle_days' => 135, 'avg_yield_tha' => 4.5],
                    ['name' => 'Kogoni', 'cycle_days' => 145, 'avg_yield_tha' => 5.0, 'cycle_type' => 'tardive'],
                ]],
            ['type' => 'cereale', 'name' => 'Maïs', 'local_name' => 'Mangban', 'family' => 'Poaceae',
                'cycle_days_min' => 90, 'cycle_days_max' => 120, 'avg_yield_tha' => 4.0,
                'varieties' => [
                    ['name' => 'DK 818', 'cycle_days' => 100, 'avg_yield_tha' => 4.5],
                    ['name' => 'Obatampa', 'cycle_days' => 110, 'avg_yield_tha' => 3.8],
                    ['name' => 'Across', 'cycle_days' => 95, 'avg_yield_tha' => 4.2, 'cycle_type' => 'précoce'],
                ]],
            ['type' => 'cereale', 'name' => 'Fonio', 'local_name' => 'Foundé', 'family' => 'Poaceae',
                'cycle_days_min' => 70, 'cycle_days_max' => 120, 'avg_yield_tha' => 0.8,
                'description' => 'Céréale traditionnelle du Fouta-Djalon, très rustique.',
                'varieties' => [
                    ['name' => 'Fonio blanc', 'cycle_days' => 90, 'avg_yield_tha' => 0.9],
                    ['name' => 'Fonio précoce', 'cycle_days' => 75, 'avg_yield_tha' => 0.7, 'cycle_type' => 'précoce'],
                ]],
            ['type' => 'cereale', 'name' => 'Mil', 'local_name' => 'Souna', 'family' => 'Poaceae',
                'cycle_days_min' => 90, 'cycle_days_max' => 130, 'avg_yield_tha' => 1.2, 'varieties' => []],
            ['type' => 'cereale', 'name' => 'Sorgho', 'local_name' => 'Kènikèni', 'family' => 'Poaceae',
                'cycle_days_min' => 100, 'cycle_days_max' => 140, 'avg_yield_tha' => 1.5, 'varieties' => []],

            // ── TUBERCULES ─────────────────────────────────────────────────
            ['type' => 'tubercule', 'name' => 'Manioc', 'local_name' => 'Bantara', 'family' => 'Euphorbiaceae',
                'cycle_days_min' => 270, 'cycle_days_max' => 365, 'avg_yield_tha' => 15.0,
                'varieties' => [
                    ['name' => 'Locale améliorée', 'cycle_days' => 300, 'avg_yield_tha' => 16.0],
                    ['name' => 'TMS', 'cycle_days' => 330, 'avg_yield_tha' => 20.0],
                ]],
            ['type' => 'tubercule', 'name' => 'Igname', 'local_name' => 'Khabi', 'family' => 'Dioscoreaceae',
                'cycle_days_min' => 210, 'cycle_days_max' => 300, 'avg_yield_tha' => 12.0, 'varieties' => []],
            ['type' => 'tubercule', 'name' => 'Patate douce', 'local_name' => null, 'family' => 'Convolvulaceae',
                'cycle_days_min' => 90, 'cycle_days_max' => 150, 'avg_yield_tha' => 10.0, 'varieties' => []],
            ['type' => 'tubercule', 'name' => 'Taro', 'local_name' => 'Macabo', 'family' => 'Araceae',
                'cycle_days_min' => 180, 'cycle_days_max' => 270, 'avg_yield_tha' => 8.0, 'varieties' => []],
            ['type' => 'tubercule', 'name' => 'Pomme de terre', 'local_name' => null, 'family' => 'Solanaceae',
                'cycle_days_min' => 90, 'cycle_days_max' => 120, 'avg_yield_tha' => 18.0,
                'description' => 'Cultivée surtout au Fouta-Djalon (Mamou, Timbi).',
                'varieties' => []],

            // ── LÉGUMINEUSES ───────────────────────────────────────────────
            ['type' => 'legumineuse', 'name' => 'Arachide', 'local_name' => 'Tiga', 'family' => 'Fabaceae',
                'cycle_days_min' => 90, 'cycle_days_max' => 120, 'avg_yield_tha' => 1.5,
                'varieties' => [
                    ['name' => '55-437', 'cycle_days' => 90, 'avg_yield_tha' => 1.8, 'cycle_type' => 'précoce'],
                    ['name' => 'Locale', 'cycle_days' => 110, 'avg_yield_tha' => 1.4],
                ]],
            ['type' => 'legumineuse', 'name' => 'Niébé', 'local_name' => 'Sosso', 'family' => 'Fabaceae',
                'cycle_days_min' => 60, 'cycle_days_max' => 90, 'avg_yield_tha' => 1.2, 'varieties' => []],
            ['type' => 'legumineuse', 'name' => 'Soja', 'local_name' => null, 'family' => 'Fabaceae',
                'cycle_days_min' => 90, 'cycle_days_max' => 120, 'avg_yield_tha' => 1.8, 'varieties' => []],

            // ── MARAÎCHERS ─────────────────────────────────────────────────
            ['type' => 'maraicher', 'name' => 'Tomate', 'local_name' => null, 'family' => 'Solanaceae',
                'cycle_days_min' => 90, 'cycle_days_max' => 120, 'avg_yield_tha' => 25.0,
                'varieties' => [
                    ['name' => 'Mongal F1', 'cycle_days' => 95, 'avg_yield_tha' => 30.0],
                    ['name' => 'Cobra F1', 'cycle_days' => 100, 'avg_yield_tha' => 35.0],
                    ['name' => 'Roma', 'cycle_days' => 110, 'avg_yield_tha' => 22.0],
                ]],
            ['type' => 'maraicher', 'name' => 'Oignon', 'local_name' => 'Djaba', 'family' => 'Amaryllidaceae',
                'cycle_days_min' => 90, 'cycle_days_max' => 130, 'avg_yield_tha' => 20.0,
                'varieties' => [
                    ['name' => 'Violet de Galmi', 'cycle_days' => 120, 'avg_yield_tha' => 22.0],
                ]],
            ['type' => 'maraicher', 'name' => 'Piment', 'local_name' => 'Foronto', 'family' => 'Solanaceae',
                'cycle_days_min' => 90, 'cycle_days_max' => 150, 'avg_yield_tha' => 8.0, 'varieties' => []],
            ['type' => 'maraicher', 'name' => 'Aubergine', 'local_name' => 'Diakhatou', 'family' => 'Solanaceae',
                'cycle_days_min' => 90, 'cycle_days_max' => 130, 'avg_yield_tha' => 18.0, 'varieties' => []],
            ['type' => 'maraicher', 'name' => 'Gombo', 'local_name' => 'Kanto', 'family' => 'Malvaceae',
                'cycle_days_min' => 55, 'cycle_days_max' => 90, 'avg_yield_tha' => 8.0, 'varieties' => []],
            ['type' => 'maraicher', 'name' => 'Chou', 'local_name' => null, 'family' => 'Brassicaceae',
                'cycle_days_min' => 70, 'cycle_days_max' => 100, 'avg_yield_tha' => 30.0, 'varieties' => []],
            ['type' => 'maraicher', 'name' => 'Carotte', 'local_name' => null, 'family' => 'Apiaceae',
                'cycle_days_min' => 80, 'cycle_days_max' => 110, 'avg_yield_tha' => 25.0, 'varieties' => []],
            ['type' => 'maraicher', 'name' => 'Concombre', 'local_name' => null, 'family' => 'Cucurbitaceae',
                'cycle_days_min' => 50, 'cycle_days_max' => 70, 'avg_yield_tha' => 20.0, 'varieties' => []],
            ['type' => 'maraicher', 'name' => 'Pastèque', 'local_name' => null, 'family' => 'Cucurbitaceae',
                'cycle_days_min' => 75, 'cycle_days_max' => 100, 'avg_yield_tha' => 25.0, 'varieties' => []],
            ['type' => 'maraicher', 'name' => 'Laitue', 'local_name' => 'Salade', 'family' => 'Asteraceae',
                'cycle_days_min' => 45, 'cycle_days_max' => 70, 'avg_yield_tha' => 15.0, 'varieties' => []],

            // ── FRUITIERS ──────────────────────────────────────────────────
            ['type' => 'fruitier', 'name' => 'Mangue', 'local_name' => 'Mango', 'family' => 'Anacardiaceae',
                'cycle_days_min' => null, 'cycle_days_max' => null, 'avg_yield_tha' => 10.0,
                'description' => 'Pérenne : production saisonnière (mars – juin).',
                'varieties' => [
                    ['name' => 'Kent', 'cycle_days' => null, 'avg_yield_tha' => 12.0],
                    ['name' => 'Keitt', 'cycle_days' => null, 'avg_yield_tha' => 13.0],
                    ['name' => 'Amélie', 'cycle_days' => null, 'avg_yield_tha' => 10.0],
                ]],
            ['type' => 'fruitier', 'name' => 'Ananas', 'local_name' => null, 'family' => 'Bromeliaceae',
                'cycle_days_min' => 365, 'cycle_days_max' => 540, 'avg_yield_tha' => 50.0,
                'description' => 'Culture phare de la Basse-Guinée (région de Kindia).',
                'varieties' => [
                    ['name' => 'Cayenne lisse', 'cycle_days' => 450, 'avg_yield_tha' => 55.0],
                    ['name' => 'Baronne de Rothschild', 'cycle_days' => 480, 'avg_yield_tha' => 50.0],
                ]],
            ['type' => 'fruitier', 'name' => 'Banane plantain', 'local_name' => 'Loki', 'family' => 'Musaceae',
                'cycle_days_min' => 300, 'cycle_days_max' => 400, 'avg_yield_tha' => 20.0, 'varieties' => []],
            ['type' => 'fruitier', 'name' => 'Banane douce', 'local_name' => null, 'family' => 'Musaceae',
                'cycle_days_min' => 300, 'cycle_days_max' => 365, 'avg_yield_tha' => 25.0, 'varieties' => []],
            ['type' => 'fruitier', 'name' => 'Orange', 'local_name' => 'Lemourou', 'family' => 'Rutaceae',
                'cycle_days_min' => null, 'cycle_days_max' => null, 'avg_yield_tha' => 15.0, 'varieties' => []],
            ['type' => 'fruitier', 'name' => 'Papaye', 'local_name' => null, 'family' => 'Caricaceae',
                'cycle_days_min' => 240, 'cycle_days_max' => 330, 'avg_yield_tha' => 35.0, 'varieties' => []],
            ['type' => 'fruitier', 'name' => 'Avocat', 'local_name' => null, 'family' => 'Lauraceae',
                'cycle_days_min' => null, 'cycle_days_max' => null, 'avg_yield_tha' => 10.0, 'varieties' => []],

            // ── OLÉAGINEUX ─────────────────────────────────────────────────
            ['type' => 'oleagineux', 'name' => 'Palmier à huile', 'local_name' => 'Tê', 'family' => 'Arecaceae',
                'cycle_days_min' => null, 'cycle_days_max' => null, 'avg_yield_tha' => 12.0,
                'description' => 'Pérenne : régimes récoltés toute l\'année (Guinée forestière, Basse-Guinée).',
                'varieties' => [
                    ['name' => 'Tenera', 'cycle_days' => null, 'avg_yield_tha' => 15.0],
                ]],
            ['type' => 'oleagineux', 'name' => 'Sésame', 'local_name' => 'Bènè', 'family' => 'Pedaliaceae',
                'cycle_days_min' => 90, 'cycle_days_max' => 120, 'avg_yield_tha' => 0.8, 'varieties' => []],
            ['type' => 'oleagineux', 'name' => 'Anacarde', 'local_name' => null, 'family' => 'Anacardiaceae',
                'cycle_days_min' => null, 'cycle_days_max' => null, 'avg_yield_tha' => 0.7,
                'description' => 'Noix de cajou, culture de rente en expansion.',
                'varieties' => []],

            // Légumes feuillus & maraîchers complémentaires
            [
                'type'           => 'legume',
                'name'           => 'Épinard africain',
                'local_name'     => 'Gboma dessi',
                'family'         => 'Amaranthaceae',
                'cycle_days_min' => 30,
                'cycle_days_max' => 45,
                'avg_yield_tha'  => 8.0,
                'varieties'      => [],
            ],
            [
                'type'           => 'legume',
                'name'           => 'Amarante',
                'local_name'     => 'Létu',
                'family'         => 'Amaranthaceae',
                'cycle_days_min' => 25,
                'cycle_days_max' => 40,
                'avg_yield_tha'  => 10.0,
                'varieties'      => [],
            ],
            [
                'type'           => 'legume',
                'name'           => 'Aubergine africaine',
                'local_name'     => 'Gboma',
                'family'         => 'Solanaceae',
                'cycle_days_min' => 60,
                'cycle_days_max' => 90,
                'avg_yield_tha'  => 12.0,
                'varieties'      => [
                    ['name' => 'Locale ronde', 'cycle_days' => 75, 'avg_yield_tha' => 11.0],
                    ['name' => 'Locale longue', 'cycle_days' => 80, 'avg_yield_tha' => 12.5],
                ],
            ],
            [
                'type'           => 'legume',
                'name'           => 'Moringa',
                'local_name'     => 'Névédé',
                'family'         => 'Moringaceae',
                'cycle_days_min' => 60,
                'cycle_days_max' => 90,
                'avg_yield_tha'  => 6.0,
                'varieties'      => [],
            ],
            [
                'type'           => 'legume',
                'name'           => 'Corète potagère',
                'local_name'     => 'Bisap feuilles / Dah',
                'family'         => 'Malvaceae',
                'cycle_days_min' => 45,
                'cycle_days_max' => 60,
                'avg_yield_tha'  => 7.0,
                'varieties'      => [],
            ],

            // ── CULTURES DE RENTE / AUTRES ─────────────────────────────────
            ['type' => 'autre', 'name' => 'Café', 'local_name' => null, 'family' => 'Rubiaceae',
                'cycle_days_min' => null, 'cycle_days_max' => null, 'avg_yield_tha' => 0.8,
                'description' => 'Robusta de la Guinée forestière (N\'Zérékoré, Macenta).',
                'varieties' => [
                    ['name' => 'Robusta', 'cycle_days' => null, 'avg_yield_tha' => 0.9],
                ]],
            ['type' => 'autre', 'name' => 'Cacao', 'local_name' => null, 'family' => 'Malvaceae',
                'cycle_days_min' => null, 'cycle_days_max' => null, 'avg_yield_tha' => 0.5, 'varieties' => []],
            ['type' => 'autre', 'name' => 'Coton', 'local_name' => null, 'family' => 'Malvaceae',
                'cycle_days_min' => 150, 'cycle_days_max' => 180, 'avg_yield_tha' => 1.2,
                'description' => 'Culture de rente en Haute-Guinée.',
                'varieties' => []],
            ['type' => 'autre', 'name' => 'Canne à sucre', 'local_name' => null, 'family' => 'Poaceae',
                'cycle_days_min' => 300, 'cycle_days_max' => 365, 'avg_yield_tha' => 60.0, 'varieties' => []],
            ['type' => 'autre', 'name' => 'Hévéa', 'local_name' => null, 'family' => 'Euphorbiaceae',
                'cycle_days_min' => null, 'cycle_days_max' => null, 'avg_yield_tha' => 1.5,
                'description' => 'Caoutchouc naturel, Guinée forestière.',
                'varieties' => []],

            // ── AGRUMES & FRUITIERS COMPLÉMENTAIRES ────────────────────────
            ['type' => 'fruitier', 'name' => 'Citron Vert (Lime)', 'local_name' => 'Lemourou koumoun', 'family' => 'Rutaceae',
                'cycle_days_min' => null, 'cycle_days_max' => null, 'avg_yield_tha' => 18.0,
                'description' => 'Petits agrumes acides, très utilisés localement.',
                'varieties' => [
                    ['name' => 'Mexicaine', 'cycle_days' => null, 'avg_yield_tha' => 16.0],
                    ['name' => 'Tahiti', 'cycle_days' => null, 'avg_yield_tha' => 20.0],
                ]],
            ['type' => 'fruitier', 'name' => 'Citron', 'local_name' => 'Lemourou koumoun', 'family' => 'Rutaceae',
                'cycle_days_min' => null, 'cycle_days_max' => null, 'avg_yield_tha' => 20.0,
                'varieties' => [
                    ['name' => 'Eureka', 'avg_yield_tha' => 22.0],
                    ['name' => 'Lime de Perse', 'avg_yield_tha' => 18.0],
                ]],
            ['type' => 'fruitier', 'name' => 'Mandarine / Tangerine', 'local_name' => null, 'family' => 'Rutaceae',
                'cycle_days_min' => null, 'cycle_days_max' => null, 'avg_yield_tha' => 14.0,
                'varieties' => [
                    ['name' => 'Clementine', 'avg_yield_tha' => 15.0],
                    ['name' => 'Dancy', 'avg_yield_tha' => 13.0],
                ]],
            ['type' => 'fruitier', 'name' => 'Pamplemousse / Pomelo', 'local_name' => null, 'family' => 'Rutaceae',
                'cycle_days_min' => null, 'cycle_days_max' => null, 'avg_yield_tha' => 25.0,
                'varieties' => [
                    ['name' => 'Marsh Seedless', 'avg_yield_tha' => 25.0],
                    ['name' => 'Star Ruby', 'avg_yield_tha' => 28.0, 'description' => 'Chair rouge.'],
                ]],
            ['type' => 'fruitier', 'name' => 'Corossol', 'local_name' => null, 'family' => 'Annonaceae',
                'cycle_days_min' => null, 'cycle_days_max' => null, 'avg_yield_tha' => 10.0,
                'description' => 'Fruit à chair blanche, pulpeuse et acide.',
                'varieties' => []],
            ['type' => 'fruitier', 'name' => 'Goyave', 'local_name' => null, 'family' => 'Myrtaceae',
                'cycle_days_min' => null, 'cycle_days_max' => null, 'avg_yield_tha' => 15.0,
                'varieties' => [
                    ['name' => 'Chair rose', 'avg_yield_tha' => 15.0],
                    ['name' => 'Chair blanche', 'avg_yield_tha' => 14.0],
                ]],
            ['type' => 'fruitier', 'name' => 'Cocotier', 'local_name' => 'Koko', 'family' => 'Arecaceae',
                'cycle_days_min' => null, 'cycle_days_max' => null, 'avg_yield_tha' => 10.0,
                'description' => 'Principalement en zone côtière (Basse-Guinée).',
                'varieties' => [
                    ['name' => 'Grand Ouest Africain (GOA)', 'avg_yield_tha' => 8.0, 'description' => 'Rustique, lent à produire.'],
                    ['name' => 'Nain Jaune/Vert', 'avg_yield_tha' => 12.0, 'cycle_type' => 'précoce', 'description' => 'Production rapide, eau de coco.'],
                ]],

            // ── MARAÎCHERS COMPLÉMENTAIRES ─────────────────────────────────
            ['type' => 'maraicher', 'name' => 'Poivron', 'local_name' => null, 'family' => 'Solanaceae',
                'cycle_days_min' => 70, 'cycle_days_max' => 120, 'avg_yield_tha' => 15.0,
                'varieties' => [
                    ['name' => 'Yolo Wonder', 'cycle_days' => 100, 'avg_yield_tha' => 15.0],
                    ['name' => 'California Wonder', 'cycle_days' => 110, 'avg_yield_tha' => 16.0],
                ]],
            ['type' => 'maraicher', 'name' => 'Haricot vert', 'local_name' => null, 'family' => 'Fabaceae',
                'cycle_days_min' => 60, 'cycle_days_max' => 80, 'avg_yield_tha' => 8.0,
                'varieties' => [
                    ['name' => 'Contender', 'cycle_days' => 65, 'avg_yield_tha' => 8.0, 'cycle_type' => 'précoce'],
                ]],
            ['type' => 'maraicher', 'name' => 'Ail', 'local_name' => null, 'family' => 'Amaryllidaceae',
                'cycle_days_min' => 120, 'cycle_days_max' => 150, 'avg_yield_tha' => 8.0,
                'description' => 'Cultivé en saison sèche au Fouta-Djalon.',
                'varieties' => []],

            // ── ÉPICES & AROMATES ──────────────────────────────────────────
            ['type' => 'epice', 'name' => 'Gingembre', 'local_name' => null, 'family' => 'Zingiberaceae',
                'cycle_days_min' => 210, 'cycle_days_max' => 270, 'avg_yield_tha' => 15.0,
                'description' => 'Culture en extension en Basse-Guinée.',
                'varieties' => []],
        ];
    }
}
