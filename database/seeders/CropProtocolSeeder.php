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
                    ['day_number' => 0,   'stage' => 'Semis',        'action_name' => 'Semis en ligne + NPK de fond',     'type' => 'semis',         'product_suggested' => 'NPK 15-15-15', 'dose' => '150 kg/ha', 'method' => 'épandage au semis', 'notes' => 'Semer en lignes espacées de 20 cm à 2-3 cm de profondeur sur sol bien préparé. Utiliser des semences saines et triées pour une levée homogène.'],
                    ['day_number' => 12,  'stage' => 'Levée',        'action_name' => 'Contrôle de la levée & resemis',   'type' => 'observation',   'product_suggested' => null,           'dose' => null,        'method' => 'visuel', 'notes' => 'Vérifier la régularité de la levée et combler les manques rapidement. Resemer les poquets vides avant 15 jours pour garder un peuplement homogène.'],
                    ['day_number' => 20,  'stage' => 'Tallage',      'action_name' => '1er désherbage / sarclage',        'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel ou herbicide sélectif', 'notes' => 'Désherber tôt : les adventices concurrencent fortement le riz au tallage. Un sol propre avant 25 jours protège le rendement.'],
                    ['day_number' => 30,  'stage' => 'Tallage',      'action_name' => 'Apport d\'urée (couverture 1)',    'type' => 'fertilisation', 'product_suggested' => 'Urée 46%',     'dose' => '75 kg/ha',  'method' => 'épandage', 'notes' => 'Apporter sur sol humide et enfouir légèrement pour limiter les pertes par volatilisation. Éviter l\'épandage juste avant une forte pluie (lessivage).'],
                    ['day_number' => 45,  'stage' => 'Montaison',    'action_name' => '2e sarclage',                      'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel', 'notes' => 'Maintenir la parcelle propre jusqu\'à la couverture du sol. Éviter de blesser les talles lors du sarclage.'],
                    ['day_number' => 55,  'stage' => 'Initiation paniculaire', 'action_name' => 'Apport d\'urée (couverture 2)', 'type' => 'fertilisation', 'product_suggested' => 'Urée 46%', 'dose' => '50 kg/ha', 'method' => 'épandage', 'notes' => 'Fractionner l\'azote améliore l\'efficacité. Apporter sur sol humide et enfouir pour réduire la volatilisation.'],
                    ['day_number' => 70,  'stage' => 'Épiaison',     'action_name' => 'Surveillance foreurs & cécidomyie', 'type' => 'observation',  'product_suggested' => null,           'dose' => null,        'method' => 'piégeage / visuel', 'notes' => 'Observer cœurs morts et galles (cécidomyie). N\'intervenir qu\'au-delà du seuil de dégâts pour préserver les auxiliaires.'],
                    ['day_number' => 120, 'stage' => 'Maturation',   'action_name' => 'Récolte (grains à maturité)',       'type' => 'recolte',      'product_suggested' => null,           'dose' => null,        'method' => 'faucille / moissonneuse', 'notes' => 'Récolter quand 80-85 % des grains sont jaune paille. Battre et sécher rapidement à 13-14 % d\'humidité pour limiter pertes et brisures.'],
                ],
            ],
            [
                'name'        => 'Itinéraire Maïs — IRAG/FAO',
                'crop_name'   => 'Maïs',
                'source'      => 'IRAG/FAO (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,   'stage' => 'Semis',      'action_name' => 'Semis en poquets + NPK de fond',  'type' => 'fertilisation', 'product_suggested' => 'NPK 15-15-15', 'dose' => '150–200 kg/ha', 'method' => 'poquets', 'notes' => 'Semer 2-3 graines par poquet à 3-4 cm de profondeur, écartement 80 x 40 cm. Placer l\'engrais de fond légèrement décalé de la graine pour éviter les brûlures.'],
                    ['day_number' => 10,  'stage' => 'Levée',      'action_name' => 'Contrôle levée & démariage',      'type' => 'observation',   'product_suggested' => null,           'dose' => null,            'method' => 'visuel', 'notes' => 'Démarier à 1-2 plants vigoureux par poquet. Combler les manques par repiquage des plants en surnombre.'],
                    ['day_number' => 20,  'stage' => 'Croissance', 'action_name' => '1er sarclage',                    'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,            'method' => 'manuel', 'notes' => 'Désherber tôt : la concurrence des adventices est forte au jeune stade. Garder la parcelle propre les 30 premiers jours.'],
                    ['day_number' => 30,  'stage' => 'Croissance', 'action_name' => 'Apport d\'urée (couverture)',     'type' => 'fertilisation', 'product_suggested' => 'Urée 46%',     'dose' => '100 kg/ha',     'method' => 'épandage localisé', 'notes' => 'Enfouir l\'urée au pied sur sol humide pour limiter la volatilisation. Éviter le contact direct avec les feuilles et ne pas épandre avant forte pluie.'],
                    ['day_number' => 35,  'stage' => 'Croissance', 'action_name' => 'Surveillance chenille légionnaire', 'type' => 'traitement',  'product_suggested' => 'Émamectine benzoate', 'dose' => 'selon étiquette', 'method' => 'pulvérisation foliaire si seuil atteint', 'notes' => 'Intervenir dès l\'apparition des premières larves dans les cornets. Traiter en fin de journée par temps sec, porter gants et masque. Alterner les matières actives et respecter le délai avant récolte.'],
                    ['day_number' => 45,  'stage' => 'Montaison',  'action_name' => '2e sarclage / buttage',           'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,            'method' => 'manuel', 'notes' => 'Le buttage renforce l\'ancrage racinaire et limite la verse. En profiter pour éliminer les dernières adventices.'],
                    ['day_number' => 60,  'stage' => 'Floraison',  'action_name' => 'Observation floraison & stress hydrique', 'type' => 'observation', 'product_suggested' => null,    'dose' => null,            'method' => 'visuel', 'notes' => 'La floraison est le stade le plus sensible au manque d\'eau. Feuilles enroulées le matin = stress hydrique : irriguer si possible.'],
                    ['day_number' => 100, 'stage' => 'Maturation', 'action_name' => 'Récolte (grains secs)',           'type' => 'recolte',       'product_suggested' => null,           'dose' => null,            'method' => 'manuel', 'notes' => 'Récolter quand les spathes jaunissent et le grain est dur (point noir au point d\'attache). Bien sécher les épis avant stockage pour éviter les moisissures.'],
                ],
            ],
            [
                'name'        => 'Itinéraire Tomate (repiquée) — IRAG/FAO',
                'crop_name'   => 'Tomate',
                'source'      => 'IRAG/FAO (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,  'stage' => 'Repiquage',  'action_name' => 'Repiquage + fumure de fond',      'type' => 'semis',         'product_suggested' => 'NPK 10-10-20 + fumier', 'dose' => '200 kg/ha + 20 t/ha', 'method' => 'localisé au pied', 'notes' => 'Repiquer des plants trapus de 4-5 feuilles en fin de journée, écartement 60 x 40 cm. Arroser aussitôt pour favoriser la reprise.'],
                    ['day_number' => 7,  'stage' => 'Reprise',    'action_name' => 'Contrôle reprise & remplacement',  'type' => 'observation',   'product_suggested' => null,           'dose' => null,        'method' => 'visuel', 'notes' => 'Remplacer les plants morts dans la semaine pour garder un peuplement homogène. Surveiller fonte des semis au collet.'],
                    ['day_number' => 15, 'stage' => 'Croissance', 'action_name' => '1er sarclage + buttage',           'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel', 'notes' => 'Désherber tôt et butter au pied pour soutenir la plante et favoriser l\'enracinement. Ne pas blesser les racines superficielles.'],
                    ['day_number' => 20, 'stage' => 'Croissance', 'action_name' => 'Traitement préventif mildiou',     'type' => 'traitement',    'product_suggested' => 'Mancozèbe',    'dose' => '2,5 kg/ha', 'method' => 'pulvérisation foliaire', 'notes' => 'Porter gants et masque. Pulvériser tôt le matin ou en soirée par temps sec, bien mouiller le dessous des feuilles. Respecter un délai avant récolte de 7 jours.'],
                    ['day_number' => 30, 'stage' => 'Floraison',  'action_name' => 'Apport NPK (couverture)',          'type' => 'fertilisation', 'product_suggested' => 'NPK 12-12-17', 'dose' => '150 kg/ha', 'method' => 'épandage localisé', 'notes' => 'Apporter à 10-15 cm du pied sur sol humide puis arroser. Le potassium favorise la qualité et la fermeté des fruits.'],
                    ['day_number' => 35, 'stage' => 'Floraison',  'action_name' => 'Tuteurage & taille des gourmands', 'type' => 'autre',         'product_suggested' => null,           'dose' => null,        'method' => 'manuel', 'notes' => 'Tuteurer pour aérer le feuillage et limiter les maladies. Tailler les gourmands le matin par temps sec pour une cicatrisation rapide.'],
                    ['day_number' => 45, 'stage' => 'Nouaison',   'action_name' => 'Surveillance Tuta absoluta',       'type' => 'traitement',    'product_suggested' => 'Bacillus thuringiensis', 'dose' => 'selon étiquette', 'method' => 'pulvérisation si seuil', 'notes' => 'Installer des pièges à phéromones pour suivre le vol. Traiter en soirée dès les premières mines sur feuilles. Le Bt préserve les auxiliaires ; alterner les modes d\'action et porter des EPI.'],
                    ['day_number' => 75, 'stage' => 'Récolte',    'action_name' => 'Récolte échelonnée',               'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'manuel, tous les 2–3 jours', 'notes' => 'Récolter au stade tournant à mûr, tôt le matin. Manipuler avec soin pour éviter les chocs. Récolte échelonnée tous les 2 à 3 jours.'],
                ],
            ],
            [
                'name'        => 'Itinéraire Pomme de terre — IRAG/FAO',
                'crop_name'   => 'Pomme de terre',
                'source'      => 'IRAG/FAO (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,  'stage' => 'Plantation', 'action_name' => 'Plantation tubercules + fumure de fond', 'type' => 'semis',     'product_suggested' => 'NPK 10-10-20', 'dose' => '300 kg/ha', 'method' => 'en sillons', 'notes' => 'Planter des tubercules germés sains à 8-10 cm de profondeur, écartement 70 x 30 cm. Éviter les plants malades pour limiter la propagation des viroses.'],
                    ['day_number' => 15, 'stage' => 'Levée',      'action_name' => 'Contrôle levée',                   'type' => 'observation',   'product_suggested' => null,           'dose' => null,        'method' => 'visuel', 'notes' => 'Vérifier l\'homogénéité de la levée et l\'absence de pourriture sur les tubercules non levés.'],
                    ['day_number' => 25, 'stage' => 'Croissance', 'action_name' => '1er buttage + sarclage',           'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel', 'notes' => 'Butter pour couvrir les tubercules et éviter le verdissement. Désherber tôt avant la fermeture du rang.'],
                    ['day_number' => 30, 'stage' => 'Croissance', 'action_name' => 'Traitement préventif mildiou',     'type' => 'traitement',    'product_suggested' => 'Mancozèbe',    'dose' => '2,5 kg/ha', 'method' => 'pulvérisation foliaire', 'notes' => 'Porter gants et masque. Traiter préventivement par temps humide, tôt le matin ou en soirée. Bien mouiller le feuillage et respecter un délai avant récolte de 7 jours.'],
                    ['day_number' => 40, 'stage' => 'Tubérisation', 'action_name' => 'Apport d\'urée + 2e buttage',    'type' => 'fertilisation', 'product_suggested' => 'Urée 46%',     'dose' => '100 kg/ha', 'method' => 'épandage + buttage', 'notes' => 'Apporter sur sol humide puis butter pour enfouir et limiter la volatilisation. Le buttage protège les tubercules de la lumière.'],
                    ['day_number' => 60, 'stage' => 'Tubérisation', 'action_name' => 'Surveillance mildiou & teigne',  'type' => 'observation',   'product_suggested' => null,           'dose' => null,        'method' => 'visuel', 'notes' => 'Inspecter le feuillage par temps humide (taches huileuses = mildiou). Renouveler la protection si la pression augmente.'],
                    ['day_number' => 90, 'stage' => 'Maturation',  'action_name' => 'Récolte (défanage préalable)',     'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'arrachage', 'notes' => 'Défaner 10-15 jours avant pour raffermir la peau. Récolter par temps sec et laisser ressuyer les tubercules à l\'ombre avant stockage.'],
                ],
            ],
            [
                'name'        => 'Itinéraire Oignon — IRAG/FAO',
                'crop_name'   => 'Oignon',
                'source'      => 'IRAG/FAO (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,  'stage' => 'Repiquage',  'action_name' => 'Repiquage + fumure de fond',       'type' => 'semis',         'product_suggested' => 'NPK 10-10-20 + fumier', 'dose' => '200 kg/ha', 'method' => 'planches', 'notes' => 'Repiquer des plants au stade crayon, écartement 10 x 15 cm, collet juste sous la surface. Habiller racines et feuilles pour favoriser la reprise.'],
                    ['day_number' => 10, 'stage' => 'Reprise',    'action_name' => 'Contrôle reprise & irrigation régulière', 'type' => 'irrigation', 'product_suggested' => null,        'dose' => null,        'method' => 'arrosage', 'notes' => 'Arroser régulièrement et en quantité modérée, de préférence le matin. L\'oignon a un enracinement superficiel et craint le stress hydrique.'],
                    ['day_number' => 20, 'stage' => 'Croissance', 'action_name' => '1er désherbage',                   'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel', 'notes' => 'Désherber souvent et superficiellement : l\'oignon supporte mal la concurrence des adventices. Ne pas blesser les bulbes.'],
                    ['day_number' => 30, 'stage' => 'Croissance', 'action_name' => 'Apport d\'urée (couverture)',      'type' => 'fertilisation', 'product_suggested' => 'Urée 46%',     'dose' => '100 kg/ha', 'method' => 'épandage', 'notes' => 'Apporter sur sol humide en fin de croissance foliaire et arroser. Arrêter l\'azote en début de bulbaison pour favoriser la conservation.'],
                    ['day_number' => 40, 'stage' => 'Bulbaison',  'action_name' => 'Surveillance thrips & mildiou',    'type' => 'traitement',    'product_suggested' => 'Insecticide homologué', 'dose' => 'selon étiquette', 'method' => 'pulvérisation si seuil', 'notes' => 'Traiter seulement au-delà du seuil de thrips (présence dans le cœur des feuilles). Pulvériser en soirée par temps sec, ajouter un mouillant, porter des EPI et respecter le délai avant récolte.'],
                    ['day_number' => 60, 'stage' => 'Bulbaison',  'action_name' => '2e sarclage léger',                'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel', 'notes' => 'Sarclage superficiel pour ne pas dégager ni blesser les bulbes en formation.'],
                    ['day_number' => 110,'stage' => 'Maturation', 'action_name' => 'Récolte (chute des feuilles)',     'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'arrachage + séchage', 'notes' => 'Arracher quand 50-70 % des feuilles sont tombées, par temps sec. Ressuyer les bulbes au champ puis sécher à l\'ombre et aéré avant stockage.'],
                ],
            ],
            [
                'name'        => 'Itinéraire Arachide — IRAG/FAO',
                'crop_name'   => 'Arachide',
                'source'      => 'IRAG/FAO (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,  'stage' => 'Semis',      'action_name' => 'Semis en ligne + fumure phospho-potassique', 'type' => 'semis', 'product_suggested' => 'Engrais P-K', 'dose' => '100 kg/ha', 'method' => 'au semis', 'notes' => 'Semer des graines décortiquées et triées à 3-4 cm, écartement 50 x 15 cm. L\'arachide fixe l\'azote : privilégier phosphore et potassium, pas d\'azote.'],
                    ['day_number' => 12, 'stage' => 'Levée',      'action_name' => 'Contrôle levée & démariage',       'type' => 'observation',   'product_suggested' => null,           'dose' => null,        'method' => 'visuel', 'notes' => 'Vérifier la régularité de la levée et resemer les manques rapidement pour garder une bonne densité.'],
                    ['day_number' => 20, 'stage' => 'Croissance', 'action_name' => '1er sarclage',                     'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel', 'notes' => 'Désherber tôt pour limiter la concurrence. Garder la parcelle propre jusqu\'à la floraison.'],
                    ['day_number' => 40, 'stage' => 'Floraison',  'action_name' => '2e sarclage + léger buttage',      'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel', 'notes' => 'Butter légèrement pour faciliter la pénétration des gynophores dans le sol. Éviter de sarcler après l\'enfoncement des gynophores.'],
                    ['day_number' => 50, 'stage' => 'Fructification', 'action_name' => 'Surveillance cercosporiose',   'type' => 'traitement',    'product_suggested' => 'Mancozèbe',    'dose' => '2 kg/ha',   'method' => 'pulvérisation si symptômes', 'notes' => 'Traiter dès l\'apparition des taches foliaires brunes. Porter gants et masque, pulvériser par temps sec en soirée et respecter le délai avant récolte.'],
                    ['day_number' => 100,'stage' => 'Maturation', 'action_name' => 'Récolte (arrachage des gousses)',  'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'arrachage + séchage', 'notes' => 'Arracher quand l\'intérieur des coques se colore et que le feuillage jaunit. Bien sécher les gousses pour éviter l\'aflatoxine ; stocker au sec.'],
                ],
            ],
            // ── Nouvelles cultures (Phase 3) ────────────────────────────────────────
            [
                'name'        => 'Itinéraire Manioc — IRAG/FAO/DNPIA',
                'crop_name'   => 'Manioc',
                'source'      => 'IRAG/FAO/DNPIA (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,   'stage' => 'Bouturage',  'action_name' => 'Bouturage + fumure de fond (NPK 10-10-20)', 'type' => 'semis',         'product_suggested' => 'NPK 10-10-20', 'dose' => '200 kg/ha', 'method' => 'en sillons', 'notes' => 'Planter des boutures saines de 20-25 cm (5-6 nœuds), inclinées, écartement 1 x 1 m. Choisir des tiges de variétés tolérantes à la mosaïque.'],
                    ['day_number' => 30,  'stage' => 'Croissance', 'action_name' => 'Premier sarclage',                          'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel', 'notes' => 'Désherber tôt : le manioc pousse lentement au départ et craint la concurrence les 3 premiers mois.'],
                    ['day_number' => 60,  'stage' => 'Croissance', 'action_name' => 'Apport urée + deuxième sarclage',           'type' => 'fertilisation', 'product_suggested' => 'Urée 46%',     'dose' => '50 kg/ha',  'method' => 'épandage localisé', 'notes' => 'Apporter au pied sur sol humide puis enfouir par sarclage pour limiter la volatilisation. Éviter l\'épandage avant forte pluie.'],
                    ['day_number' => 90,  'stage' => 'Croissance', 'action_name' => 'Traitement acariose/cochenilles si nécessaire', 'type' => 'traitement', 'product_suggested' => 'Lambda-cyhalothrine', 'dose' => 'selon étiquette', 'method' => 'pulvérisation si seuil', 'notes' => 'Traiter seulement si l\'attaque dépasse le seuil. Pulvériser en soirée par temps sec, porter gants et masque, et préserver les pollinisateurs. Alterner les matières actives.'],
                    ['day_number' => 180, 'stage' => 'Croissance', 'action_name' => 'Sarclage + binage',                         'type' => 'sarclage',      'product_suggested' => null,           'dose' => null,        'method' => 'manuel', 'notes' => 'Maintenir la parcelle propre et ameublir le sol pour favoriser la tubérisation.'],
                    ['day_number' => 270, 'stage' => 'Croissance', 'action_name' => 'Contrôle taux d\'amidon (observation)',     'type' => 'observation',   'product_suggested' => null,           'dose' => null,        'method' => 'visuel', 'notes' => 'Sonder quelques tubercules : grosseur et teneur en amidon augmentent avec l\'âge. Décider la date de récolte selon le débouché.'],
                    ['day_number' => 360, 'stage' => 'Maturation', 'action_name' => 'Début récolte possible (manioc doux)',      'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'arrachage manuel', 'notes' => 'Arracher par temps sec en évitant de casser les tubercules. Le manioc doux se transforme dans les 48 h car il se conserve mal après arrachage.'],
                    ['day_number' => 540, 'stage' => 'Maturation', 'action_name' => 'Récolte manioc amer conseillée',            'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'arrachage manuel', 'notes' => 'Le manioc amer doit être transformé (rouissage, cuisson) pour éliminer l\'acide cyanhydrique. Récolter au fur et à mesure des besoins.'],
                ],
            ],
            [
                'name'        => 'Itinéraire Haricot vert — IRAG/FAO/DNPIA',
                'crop_name'   => 'Haricot vert',
                'source'      => 'IRAG/FAO/DNPIA (indicatif)',
                'description' => $note,
                'items' => [
                    ['day_number' => 0,  'stage' => 'Semis',      'action_name' => 'Semis direct + NPK 10-10-20',                'type' => 'semis',         'product_suggested' => 'NPK 10-10-20', 'dose' => '150 kg/ha', 'method' => 'en ligne', 'notes' => 'Semer à 3-4 cm de profondeur, écartement 40 x 10 cm, 2-3 graines par poquet. Le haricot fixe l\'azote : limiter les apports azotés.'],
                    ['day_number' => 15, 'stage' => 'Croissance', 'action_name' => 'Premier sarclage + thinning (éclaircissage)', 'type' => 'sarclage',     'product_suggested' => null,           'dose' => null,        'method' => 'manuel', 'notes' => 'Éclaircir à 2 plants vigoureux par poquet et désherber tôt. Sarclage superficiel pour ne pas blesser les racines.'],
                    ['day_number' => 25, 'stage' => 'Croissance', 'action_name' => 'Apport urée 46%',                           'type' => 'fertilisation', 'product_suggested' => 'Urée 46%',     'dose' => '40 kg/ha',  'method' => 'épandage', 'notes' => 'Apport modéré sur sol humide, enfoui légèrement. Excès d\'azote = trop de feuilles au détriment des gousses.'],
                    ['day_number' => 30, 'stage' => 'Floraison',  'action_name' => 'Traitement préventif oïdium/anthracnose',   'type' => 'traitement',    'product_suggested' => 'Mancozèbe',    'dose' => '2,5 kg/ha', 'method' => 'pulvérisation foliaire', 'notes' => 'Porter gants et masque. Pulvériser tôt le matin ou en soirée par temps sec. Éviter de traiter en pleine floraison pour protéger les pollinisateurs et respecter le délai avant récolte.'],
                    ['day_number' => 45, 'stage' => 'Floraison',  'action_name' => 'Traitement acariens si attaque',            'type' => 'traitement',    'product_suggested' => 'Abamectine',   'dose' => 'selon étiquette', 'method' => 'pulvérisation si seuil', 'notes' => 'Intervenir seulement au-delà du seuil (toiles et décoloration sous les feuilles). Traiter en soirée, porter des EPI, alterner les matières actives et respecter le délai avant récolte.'],
                    ['day_number' => 55, 'stage' => 'Récolte',    'action_name' => 'Début récolte gousses vertes',              'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'manuel, tous les 2-3 jours', 'notes' => 'Récolter les gousses tendres avant grossissement des grains, tôt le matin. Cueillir tous les 2-3 jours pour stimuler la production.'],
                    ['day_number' => 60, 'stage' => 'Récolte',    'action_name' => 'Récolte principale',                        'type' => 'recolte',       'product_suggested' => null,           'dose' => null,        'method' => 'manuel', 'notes' => 'Manipuler avec soin pour éviter de casser les gousses. Tenir au frais et à l\'ombre après récolte pour préserver la qualité.'],
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
