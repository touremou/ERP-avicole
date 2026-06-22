<?php

namespace Database\Seeders;

use App\Models\CropProtocol;
use Illuminate\Database\Seeder;

/**
 * Itinéraires techniques de référence (PHASE 2).
 *
 * Calendriers culturaux indicatifs (jours après semis) pour les principales
 * cultures guinéennes, inspirés des fiches IRAG / FAO. Idempotent : updateOrCreate
 * sur le nom, étapes remplacées à chaque passage.
 *
 * AVERTISSEMENT : ces itinéraires sont INDICATIFS et doivent être adaptés aux
 * conditions locales (sol, variété, pluviométrie, pression parasitaire).
 */
class CropProtocolSeeder extends Seeder
{
    public function run(): void
    {
        $note = 'Itinéraire indicatif (IRAG/FAO) — à adapter aux conditions locales : sol, variété, météo et pression des ravageurs.';

        $protocols = [
            [
                'name'        => 'Itinéraire Riz pluvial — IRAG/FAO',
                'crop_name'   => 'Riz',
                'source'      => 'IRAG/FAO (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,   'stage' => 'Semis',        'action_name' => 'Semis en ligne + NPK de fond',     'type' => 'semis',         'product_suggested' => 'NPK 15-15-15', 'dose' => '150 kg/ha', 'method' => 'épandage au semis'],
                    ['day_number' => 12,  'stage' => 'Levée',        'action_name' => 'Contrôle de la levée & resemis',   'type' => 'observation',   'product_suggested' => null,           'dose' => null,        'method' => 'visuel'],
                    ['day_number' => 20,  'stage' => 'Tallage',      'action_name' => '1er désherbage / sarclage',        'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel ou herbicide sélectif'],
                    ['day_number' => 30,  'stage' => 'Tallage',      'action_name' => 'Apport d\'urée (couverture 1)',    'type' => 'fertilisation', 'product_suggested' => 'Urée 46%',     'dose' => '75 kg/ha',  'method' => 'épandage'],
                    ['day_number' => 45,  'stage' => 'Montaison',    'action_name' => '2e sarclage',                      'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
                    ['day_number' => 55,  'stage' => 'Initiation paniculaire', 'action_name' => 'Apport d\'urée (couverture 2)', 'type' => 'fertilisation', 'product_suggested' => 'Urée 46%', 'dose' => '50 kg/ha', 'method' => 'épandage'],
                    ['day_number' => 70,  'stage' => 'Épiaison',     'action_name' => 'Surveillance foreurs & cécidomyie', 'type' => 'observation',  'product_suggested' => null,           'dose' => null,        'method' => 'piégeage / visuel'],
                    ['day_number' => 120, 'stage' => 'Maturation',   'action_name' => 'Récolte (grains à maturité)',       'type' => 'recolte',      'product_suggested' => null,           'dose' => null,        'method' => 'faucille / moissonneuse'],
                ],
            ],
            [
                'name'        => 'Itinéraire Maïs — IRAG/FAO',
                'crop_name'   => 'Maïs',
                'source'      => 'IRAG/FAO (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,   'stage' => 'Semis',      'action_name' => 'Semis en poquets + NPK de fond',  'type' => 'fertilisation', 'product_suggested' => 'NPK 15-15-15', 'dose' => '150–200 kg/ha', 'method' => 'poquets'],
                    ['day_number' => 10,  'stage' => 'Levée',      'action_name' => 'Contrôle levée & démariage',      'type' => 'observation',   'product_suggested' => null,           'dose' => null,            'method' => 'visuel'],
                    ['day_number' => 20,  'stage' => 'Croissance', 'action_name' => '1er sarclage',                    'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,            'method' => 'manuel'],
                    ['day_number' => 30,  'stage' => 'Croissance', 'action_name' => 'Apport d\'urée (couverture)',     'type' => 'fertilisation', 'product_suggested' => 'Urée 46%',     'dose' => '100 kg/ha',     'method' => 'épandage localisé'],
                    ['day_number' => 35,  'stage' => 'Croissance', 'action_name' => 'Surveillance chenille légionnaire', 'type' => 'traitement',  'product_suggested' => 'Émamectine benzoate', 'dose' => 'selon étiquette', 'method' => 'pulvérisation foliaire si seuil atteint'],
                    ['day_number' => 45,  'stage' => 'Montaison',  'action_name' => '2e sarclage / buttage',           'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,            'method' => 'manuel'],
                    ['day_number' => 60,  'stage' => 'Floraison',  'action_name' => 'Observation floraison & stress hydrique', 'type' => 'observation', 'product_suggested' => null,    'dose' => null,            'method' => 'visuel'],
                    ['day_number' => 100, 'stage' => 'Maturation', 'action_name' => 'Récolte (grains secs)',           'type' => 'recolte',       'product_suggested' => null,           'dose' => null,            'method' => 'manuel'],
                ],
            ],
            [
                'name'        => 'Itinéraire Tomate (repiquée) — IRAG/FAO',
                'crop_name'   => 'Tomate',
                'source'      => 'IRAG/FAO (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,  'stage' => 'Repiquage',  'action_name' => 'Repiquage + fumure de fond',      'type' => 'semis',         'product_suggested' => 'NPK 10-10-20 + fumier', 'dose' => '200 kg/ha + 20 t/ha', 'method' => 'localisé au pied'],
                    ['day_number' => 7,  'stage' => 'Reprise',    'action_name' => 'Contrôle reprise & remplacement',  'type' => 'observation',   'product_suggested' => null,           'dose' => null,        'method' => 'visuel'],
                    ['day_number' => 15, 'stage' => 'Croissance', 'action_name' => '1er sarclage + buttage',           'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
                    ['day_number' => 20, 'stage' => 'Croissance', 'action_name' => 'Traitement préventif mildiou',     'type' => 'traitement',    'product_suggested' => 'Mancozèbe',    'dose' => '2,5 kg/ha', 'method' => 'pulvérisation foliaire'],
                    ['day_number' => 30, 'stage' => 'Floraison',  'action_name' => 'Apport NPK (couverture)',          'type' => 'fertilisation', 'product_suggested' => 'NPK 12-12-17', 'dose' => '150 kg/ha', 'method' => 'épandage localisé'],
                    ['day_number' => 35, 'stage' => 'Floraison',  'action_name' => 'Tuteurage & taille des gourmands', 'type' => 'autre',         'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
                    ['day_number' => 45, 'stage' => 'Nouaison',   'action_name' => 'Surveillance Tuta absoluta',       'type' => 'traitement',    'product_suggested' => 'Bacillus thuringiensis', 'dose' => 'selon étiquette', 'method' => 'pulvérisation si seuil'],
                    ['day_number' => 75, 'stage' => 'Récolte',    'action_name' => 'Récolte échelonnée',               'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'manuel, tous les 2–3 jours'],
                ],
            ],
            [
                'name'        => 'Itinéraire Pomme de terre — IRAG/FAO',
                'crop_name'   => 'Pomme de terre',
                'source'      => 'IRAG/FAO (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,  'stage' => 'Plantation', 'action_name' => 'Plantation tubercules + fumure de fond', 'type' => 'semis',     'product_suggested' => 'NPK 10-10-20', 'dose' => '300 kg/ha', 'method' => 'en sillons'],
                    ['day_number' => 15, 'stage' => 'Levée',      'action_name' => 'Contrôle levée',                   'type' => 'observation',   'product_suggested' => null,           'dose' => null,        'method' => 'visuel'],
                    ['day_number' => 25, 'stage' => 'Croissance', 'action_name' => '1er buttage + sarclage',           'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
                    ['day_number' => 30, 'stage' => 'Croissance', 'action_name' => 'Traitement préventif mildiou',     'type' => 'traitement',    'product_suggested' => 'Mancozèbe',    'dose' => '2,5 kg/ha', 'method' => 'pulvérisation foliaire'],
                    ['day_number' => 40, 'stage' => 'Tubérisation', 'action_name' => 'Apport d\'urée + 2e buttage',    'type' => 'fertilisation', 'product_suggested' => 'Urée 46%',     'dose' => '100 kg/ha', 'method' => 'épandage + buttage'],
                    ['day_number' => 60, 'stage' => 'Tubérisation', 'action_name' => 'Surveillance mildiou & teigne',  'type' => 'observation',   'product_suggested' => null,           'dose' => null,        'method' => 'visuel'],
                    ['day_number' => 90, 'stage' => 'Maturation',  'action_name' => 'Récolte (défanage préalable)',     'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'arrachage'],
                ],
            ],
            [
                'name'        => 'Itinéraire Oignon — IRAG/FAO',
                'crop_name'   => 'Oignon',
                'source'      => 'IRAG/FAO (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,  'stage' => 'Repiquage',  'action_name' => 'Repiquage + fumure de fond',       'type' => 'semis',         'product_suggested' => 'NPK 10-10-20 + fumier', 'dose' => '200 kg/ha', 'method' => 'planches'],
                    ['day_number' => 10, 'stage' => 'Reprise',    'action_name' => 'Contrôle reprise & irrigation régulière', 'type' => 'irrigation', 'product_suggested' => null,        'dose' => null,        'method' => 'arrosage'],
                    ['day_number' => 20, 'stage' => 'Croissance', 'action_name' => '1er désherbage',                   'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
                    ['day_number' => 30, 'stage' => 'Croissance', 'action_name' => 'Apport d\'urée (couverture)',      'type' => 'fertilisation', 'product_suggested' => 'Urée 46%',     'dose' => '100 kg/ha', 'method' => 'épandage'],
                    ['day_number' => 40, 'stage' => 'Bulbaison',  'action_name' => 'Surveillance thrips & mildiou',    'type' => 'traitement',    'product_suggested' => 'Insecticide homologué', 'dose' => 'selon étiquette', 'method' => 'pulvérisation si seuil'],
                    ['day_number' => 60, 'stage' => 'Bulbaison',  'action_name' => '2e sarclage léger',                'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
                    ['day_number' => 110,'stage' => 'Maturation', 'action_name' => 'Récolte (chute des feuilles)',     'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'arrachage + séchage'],
                ],
            ],
            [
                'name'        => 'Itinéraire Arachide — IRAG/FAO',
                'crop_name'   => 'Arachide',
                'source'      => 'IRAG/FAO (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,  'stage' => 'Semis',      'action_name' => 'Semis en ligne + fumure phospho-potassique', 'type' => 'semis', 'product_suggested' => 'Engrais P-K', 'dose' => '100 kg/ha', 'method' => 'au semis'],
                    ['day_number' => 12, 'stage' => 'Levée',      'action_name' => 'Contrôle levée & démariage',       'type' => 'observation',   'product_suggested' => null,           'dose' => null,        'method' => 'visuel'],
                    ['day_number' => 20, 'stage' => 'Croissance', 'action_name' => '1er sarclage',                     'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
                    ['day_number' => 40, 'stage' => 'Floraison',  'action_name' => '2e sarclage + léger buttage',      'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
                    ['day_number' => 50, 'stage' => 'Fructification', 'action_name' => 'Surveillance cercosporiose',   'type' => 'traitement',    'product_suggested' => 'Mancozèbe',    'dose' => '2 kg/ha',   'method' => 'pulvérisation si symptômes'],
                    ['day_number' => 100,'stage' => 'Maturation', 'action_name' => 'Récolte (arrachage des gousses)',  'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'arrachage + séchage'],
                ],
            ],
        ];

        foreach ($protocols as $data) {
            $items = $data['items'];
            unset($data['items']);
            $data['is_active'] = true;
            $data['agro_zone'] = null;

            $protocol = CropProtocol::updateOrCreate(['name' => $data['name']], $data);

            // Remplacement intégral des étapes (idempotence).
            $protocol->items()->delete();
            foreach ($items as $item) {
                $protocol->items()->create($item);
            }
        }
    }
}
