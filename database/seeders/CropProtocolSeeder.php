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
            // ── Nouvelles cultures (Phase 3) ────────────────────────────────────────
            [
                'name'        => 'Itinéraire Manioc — IRAG/FAO/DNPIA',
                'crop_name'   => 'Manioc',
                'source'      => 'IRAG/FAO/DNPIA (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,   'stage' => 'Bouturage',  'action_name' => 'Bouturage + fumure de fond (NPK 10-10-20)', 'type' => 'semis',         'product_suggested' => 'NPK 10-10-20', 'dose' => '200 kg/ha', 'method' => 'en sillons'],
                    ['day_number' => 30,  'stage' => 'Croissance', 'action_name' => 'Premier sarclage',                          'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
                    ['day_number' => 60,  'stage' => 'Croissance', 'action_name' => 'Apport urée + deuxième sarclage',           'type' => 'fertilisation', 'product_suggested' => 'Urée 46%',     'dose' => '50 kg/ha',  'method' => 'épandage localisé'],
                    ['day_number' => 90,  'stage' => 'Croissance', 'action_name' => 'Traitement acariose/cochenilles si nécessaire', 'type' => 'traitement', 'product_suggested' => 'Lambda-cyhalothrine', 'dose' => 'selon étiquette', 'method' => 'pulvérisation si seuil'],
                    ['day_number' => 180, 'stage' => 'Croissance', 'action_name' => 'Sarclage + binage',                         'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
                    ['day_number' => 270, 'stage' => 'Croissance', 'action_name' => 'Contrôle taux d\'amidon (observation)',     'type' => 'observation',   'product_suggested' => null,           'dose' => null,        'method' => 'visuel'],
                    ['day_number' => 360, 'stage' => 'Maturation', 'action_name' => 'Début récolte possible (manioc doux)',      'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'arrachage manuel'],
                    ['day_number' => 540, 'stage' => 'Maturation', 'action_name' => 'Récolte manioc amer conseillée',            'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'arrachage manuel'],
                ],
            ],
            [
                'name'        => 'Itinéraire Haricot vert — IRAG/FAO/DNPIA',
                'crop_name'   => 'Haricot vert',
                'source'      => 'IRAG/FAO/DNPIA (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,  'stage' => 'Semis',      'action_name' => 'Semis direct + NPK 10-10-20',                'type' => 'semis',         'product_suggested' => 'NPK 10-10-20', 'dose' => '150 kg/ha', 'method' => 'en ligne'],
                    ['day_number' => 15, 'stage' => 'Croissance', 'action_name' => 'Premier sarclage + thinning (éclaircissage)', 'type' => 'sarclage',     'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
                    ['day_number' => 25, 'stage' => 'Croissance', 'action_name' => 'Apport urée 46%',                           'type' => 'fertilisation', 'product_suggested' => 'Urée 46%',     'dose' => '40 kg/ha',  'method' => 'épandage'],
                    ['day_number' => 30, 'stage' => 'Floraison',  'action_name' => 'Traitement préventif oïdium/anthracnose',   'type' => 'traitement',    'product_suggested' => 'Mancozèbe',    'dose' => '2,5 kg/ha', 'method' => 'pulvérisation foliaire'],
                    ['day_number' => 45, 'stage' => 'Floraison',  'action_name' => 'Traitement acariens si attaque',            'type' => 'traitement',    'product_suggested' => 'Abamectine',   'dose' => 'selon étiquette', 'method' => 'pulvérisation si seuil'],
                    ['day_number' => 55, 'stage' => 'Récolte',    'action_name' => 'Début récolte gousses vertes',              'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'manuel, tous les 2-3 jours'],
                    ['day_number' => 60, 'stage' => 'Récolte',    'action_name' => 'Récolte principale',                        'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
                ],
            ],
            [
                'name'        => 'Itinéraire Banane plantain — IRAG/FAO/DNPIA',
                'crop_name'   => 'Banane plantain',
                'source'      => 'IRAG/FAO/DNPIA (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,   'stage' => 'Plantation', 'action_name' => 'Plantation rejets + fumure organique',      'type' => 'semis',         'product_suggested' => 'Fumier',       'dose' => '10 t/ha',   'method' => 'en fosse'],
                    ['day_number' => 30,  'stage' => 'Croissance', 'action_name' => 'Désherbage manuel + paillage',              'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
                    ['day_number' => 60,  'stage' => 'Croissance', 'action_name' => 'Apport engrais complet NPK',                'type' => 'fertilisation', 'product_suggested' => 'NPK complet',  'dose' => '150 kg/ha', 'method' => 'épandage localisé au pied'],
                    ['day_number' => 90,  'stage' => 'Croissance', 'action_name' => 'Élagage feuilles mortes + observations parasites (charançon)', 'type' => 'observation', 'product_suggested' => null, 'dose' => null, 'method' => 'visuel + élagage'],
                    ['day_number' => 120, 'stage' => 'Croissance', 'action_name' => 'Traitement charançon du bananier si nécessaire', 'type' => 'traitement', 'product_suggested' => 'Chlorpyriphos', 'dose' => 'selon étiquette', 'method' => 'application sol si seuil'],
                    ['day_number' => 180, 'stage' => 'Croissance', 'action_name' => 'Deuxième apport NPK',                       'type' => 'fertilisation', 'product_suggested' => 'NPK complet',  'dose' => '100 kg/ha', 'method' => 'épandage localisé'],
                    ['day_number' => 270, 'stage' => 'Floraison',  'action_name' => 'Observation floraison',                     'type' => 'observation',   'product_suggested' => null,           'dose' => null,        'method' => 'visuel'],
                    ['day_number' => 300, 'stage' => 'Fructification', 'action_name' => 'Ensachage régimes (protection qualité)', 'type' => 'autre',        'product_suggested' => null,           'dose' => null,        'method' => 'sac polyéthylène'],
                    ['day_number' => 360, 'stage' => 'Récolte',    'action_name' => 'Récolte régimes (couper à maturité)',        'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'coupe à la machette'],
                ],
            ],
            [
                'name'        => 'Itinéraire Gombo (Okra) — IRAG/FAO/DNPIA',
                'crop_name'   => 'Gombo',
                'source'      => 'IRAG/FAO/DNPIA (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,  'stage' => 'Semis',      'action_name' => 'Semis + fumure de fond (NPK 15-15-15)',      'type' => 'semis',         'product_suggested' => 'NPK 15-15-15', 'dose' => '100 kg/ha', 'method' => 'en poquets'],
                    ['day_number' => 10, 'stage' => 'Levée',      'action_name' => 'Démariage (2 plants/poquet)',                'type' => 'autre',         'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
                    ['day_number' => 20, 'stage' => 'Croissance', 'action_name' => 'Apport urée + sarclage',                    'type' => 'fertilisation', 'product_suggested' => 'Urée 46%',     'dose' => '30 kg/ha',  'method' => 'épandage + sarclage manuel'],
                    ['day_number' => 30, 'stage' => 'Croissance', 'action_name' => 'Traitement pucerons/acariens',              'type' => 'traitement',    'product_suggested' => 'Imidaclopride', 'dose' => 'selon étiquette', 'method' => 'pulvérisation si seuil'],
                    ['day_number' => 45, 'stage' => 'Floraison',  'action_name' => 'Deuxième apport urée',                      'type' => 'fertilisation', 'product_suggested' => 'Urée 46%',     'dose' => '30 kg/ha',  'method' => 'épandage localisé'],
                    ['day_number' => 55, 'stage' => 'Récolte',    'action_name' => 'Début récolte gousses tendres (tous les 2-3 jours)', 'type' => 'recolte', 'product_suggested' => null,      'dose' => null,        'method' => 'manuel, récolte fréquente'],
                    ['day_number' => 75, 'stage' => 'Récolte',    'action_name' => 'Fin cycle récolte',                         'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
                ],
            ],
            [
                'name'        => 'Itinéraire Fonio — IRAG/FAO/DNPIA',
                'crop_name'   => 'Fonio',
                'source'      => 'IRAG/FAO/DNPIA (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,  'stage' => 'Semis',    'action_name' => 'Semis à la volée',                            'type' => 'semis',       'product_suggested' => 'Semence fonio', 'dose' => '20 kg/ha', 'method' => 'à la volée'],
                    ['day_number' => 15, 'stage' => 'Levée',    'action_name' => 'Sarclage manuel (délicat, plante fragile)',   'type' => 'sarclage',    'product_suggested' => null,            'dose' => null,       'method' => 'manuel avec précaution'],
                    ['day_number' => 30, 'stage' => 'Tallage',  'action_name' => 'Observation développement',                   'type' => 'observation', 'product_suggested' => null,            'dose' => null,       'method' => 'visuel'],
                    ['day_number' => 45, 'stage' => 'Tallage',  'action_name' => 'Deuxième sarclage',                           'type' => 'sarclage',    'product_suggested' => null,            'dose' => null,       'method' => 'manuel'],
                    ['day_number' => 60, 'stage' => 'Épiaison', 'action_name' => 'Observation épiaison',                        'type' => 'observation', 'product_suggested' => null,            'dose' => null,       'method' => 'visuel'],
                    ['day_number' => 80, 'stage' => 'Maturation', 'action_name' => 'Observation maturité (grain laiteux → vitreux)', 'type' => 'observation', 'product_suggested' => null,       'dose' => null,       'method' => 'visuel'],
                    ['day_number' => 90, 'stage' => 'Récolte',  'action_name' => 'Récolte manuelle (couper tiges, battre)',     'type' => 'recolte',     'product_suggested' => null,            'dose' => null,       'method' => 'faucille + battage manuel'],
                ],
            ],
            [
                'name'        => 'Itinéraire Aubergine — IRAG/FAO/DNPIA',
                'crop_name'   => 'Aubergine',
                'source'      => 'IRAG/FAO/DNPIA (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,  'stage' => 'Pépinière',  'action_name' => 'Pépinière semis (en serre ou châssis froid)', 'type' => 'semis',       'product_suggested' => null,           'dose' => null,        'method' => 'en caissettes ou planches pépinière'],
                    ['day_number' => 30, 'stage' => 'Repiquage',  'action_name' => 'Repiquage au champ + NPK 15-15-15',          'type' => 'fertilisation','product_suggested' => 'NPK 15-15-15', 'dose' => '150 kg/ha', 'method' => 'localisé au pied'],
                    ['day_number' => 45, 'stage' => 'Croissance', 'action_name' => 'Apport urée + sarclage',                    'type' => 'fertilisation', 'product_suggested' => 'Urée 46%',     'dose' => '50 kg/ha',  'method' => 'épandage + sarclage manuel'],
                    ['day_number' => 55, 'stage' => 'Croissance', 'action_name' => 'Traitement préventif oïdium (soufre) + thrips (spinosad)', 'type' => 'traitement', 'product_suggested' => 'Soufre / Spinosad', 'dose' => 'selon étiquette', 'method' => 'pulvérisation foliaire'],
                    ['day_number' => 60, 'stage' => 'Floraison',  'action_name' => 'Début floraison — observation',             'type' => 'observation',   'product_suggested' => null,           'dose' => null,        'method' => 'visuel'],
                    ['day_number' => 70, 'stage' => 'Floraison',  'action_name' => 'Traitement Botrytis si humidité excessive',  'type' => 'traitement',    'product_suggested' => 'Fongicide anti-Botrytis', 'dose' => 'selon étiquette', 'method' => 'pulvérisation si symptômes'],
                    ['day_number' => 80, 'stage' => 'Récolte',    'action_name' => 'Début récolte fruits',                      'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'manuel, tous les 2-3 jours'],
                    ['day_number' => 90, 'stage' => 'Récolte',    'action_name' => 'Récolte principale',                        'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'manuel'],
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
