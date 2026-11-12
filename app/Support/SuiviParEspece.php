<?php

namespace App\Support;

/**
 * SUIVI PAR ESPÈCE — ce que les formulaires de suivi proposent selon l'animal.
 *
 * Demande de l'exploitation : les formulaires (santé, incident, pointage)
 * étaient génériques — et pensés pour la volaille. Devant un lot de moutons,
 * l'ouvrier se voyait proposer « Chute de ponte », « Plumage ébouriffé », la
 * goutte oculaire, le picage et la litière.
 *
 * UNE déclaration, par FAMILLE d'espèce (species.family), servie au terrain
 * dans la session (`me.species_forms`, en cache hors ligne) : symptômes et
 * produits PROPOSÉS (la saisie reste possible, comme au bureau), modes
 * d'administration OFFERTS (liste fermée, validée serveur), rubriques du
 * pointage MASQUÉES quand elles n'ont pas de sens pour l'animal.
 */
final class SuiviParEspece
{
    /** Modes d'administration reconnus — liste FERMÉE, bureau et terrain. */
    public const MODES = [
        'Eau de boisson', 'Injection', 'Spray', 'Aliment', 'Oculaire',
        'Voie orale', 'Application cutanée', 'Bain',
    ];

    /** Anciens libellés (terrain : « Pulvérisation » ; écran de modification : « Nébulisation ») du même geste. */
    public const MODES_SYNONYMES = ['Pulvérisation' => 'Spray', 'Nébulisation' => 'Spray'];

    /** Rubriques du pointage qu'une famille peut masquer. */
    public const RUBRIQUES = ['litiere', 'picage', 'boiteux', 'ambiance'];

    private const DEFAUT = [
        'symptomes' => ['Prostration', 'Perte d’appétit', 'Diarrhée', 'Toux / râles', 'Boiterie', 'Amaigrissement'],
        'produits'  => ['Vaccin' => [], 'Traitement' => ['Antibiotique', 'Déparasitant'], 'Vitamine' => ['Complexe vitaminé']],
        'modes'     => ['Eau de boisson', 'Injection', 'Aliment', 'Voie orale'],
        'masquer'   => ['picage'],
    ];

    private const FAMILLES = [
        'volaille' => [
            'symptomes' => ['Fientes vertes', 'Fientes sanguinolentes', 'Prostration', 'Toux / râles', 'Boiterie', 'Chute de ponte', 'Plumage ébouriffé'],
            'produits'  => [
                'Vaccin'     => ['Newcastle (HB1 / LaSota)', 'Gumboro', 'Bronchite infectieuse', 'Variole aviaire', 'Marek'],
                'Traitement' => ['Anticoccidien', 'Antibiotique', 'Déparasitant'],
                'Vitamine'   => ['Complexe vitaminé', 'Anti-stress'],
            ],
            'modes'   => ['Eau de boisson', 'Injection', 'Spray', 'Aliment', 'Oculaire'],
            'masquer' => [],
        ],
        'petit_ruminant' => [
            'symptomes' => ['Diarrhée', 'Toux / jetage nasal', 'Boiterie', 'Amaigrissement', 'Ballonnement', 'Avortement', 'Gale / lésions de peau', 'Prostration'],
            'produits'  => [
                'Vaccin'     => ['PPR (peste des petits ruminants)', 'Pasteurellose', 'Charbon bactéridien', 'Clavelée / variole'],
                'Traitement' => ['Déparasitant interne (vermifuge)', 'Déparasitant externe', 'Antibiotique'],
                'Vitamine'   => ['Complexe vitaminé', 'Pierre à lécher'],
            ],
            'modes'   => ['Injection', 'Voie orale', 'Application cutanée', 'Bain', 'Eau de boisson', 'Aliment'],
            'masquer' => ['picage', 'litiere'],
        ],
        'grand_ruminant' => [
            'symptomes' => ['Diarrhée', 'Toux / jetage nasal', 'Boiterie', 'Amaigrissement', 'Ballonnement', 'Avortement', 'Mammite', 'Tiques', 'Prostration'],
            'produits'  => [
                'Vaccin'     => ['Péripneumonie (PPCB)', 'Charbon symptomatique', 'Charbon bactéridien', 'Pasteurellose', 'Dermatose nodulaire'],
                'Traitement' => ['Déparasitant interne (vermifuge)', 'Acaricide (tiques)', 'Trypanocide', 'Antibiotique'],
                'Vitamine'   => ['Complexe vitaminé', 'Pierre à lécher'],
            ],
            'modes'   => ['Injection', 'Voie orale', 'Application cutanée', 'Bain', 'Aliment'],
            'masquer' => ['picage', 'litiere'],
        ],
        'lagomorphe' => [
            'symptomes' => ['Diarrhée', 'Ballonnement', 'Éternuements / coryza', 'Gale des oreilles', 'Maux de pattes', 'Prostration', 'Mortalité au nid'],
            'produits'  => [
                'Vaccin'     => ['VHD (maladie hémorragique)', 'Myxomatose'],
                'Traitement' => ['Anticoccidien', 'Antigale', 'Antibiotique'],
                'Vitamine'   => ['Complexe vitaminé'],
            ],
            'modes'   => ['Eau de boisson', 'Injection', 'Aliment', 'Voie orale', 'Application cutanée'],
            'masquer' => ['picage', 'boiteux'],
        ],
        'porcin' => [
            'symptomes' => ['Diarrhée', 'Toux', 'Fièvre / rougeurs de peau', 'Boiterie', 'Perte d’appétit', 'Avortement', 'Prostration'],
            'produits'  => [
                'Vaccin'     => ['Rouget', 'Peste porcine classique', 'Parvovirose'],
                'Traitement' => ['Fer injectable (porcelets)', 'Déparasitant', 'Antibiotique'],
                'Vitamine'   => ['Complexe vitaminé'],
            ],
            'modes'   => ['Injection', 'Aliment', 'Eau de boisson', 'Voie orale', 'Application cutanée'],
            'masquer' => ['picage'],
        ],
        'aquaculture' => [
            'symptomes' => ['Poissons en surface (pipage)', 'Nage anormale', 'Lésions / ulcères', 'Nageoires abîmées', 'Perte d’appétit', 'Mortalité groupée'],
            'produits'  => [
                'Vaccin'     => [],
                'Traitement' => ['Sel (bain)', 'Chaux (bassin)', 'Antiparasitaire'],
                'Vitamine'   => ['Prémix vitaminé'],
            ],
            'modes'   => ['Bain', 'Aliment'],
            'masquer' => ['picage', 'litiere', 'boiteux', 'ambiance'],
        ],
    ];

    /** Le suivi d'une famille (celui par défaut pour une famille inconnue). */
    public static function pour(?string $famille): array
    {
        return self::FAMILLES[$famille] ?? self::DEFAUT;
    }

    /** Tout le référentiel, pour la session du terrain. */
    public static function referentiel(): array
    {
        return ['defaut' => self::DEFAUT] + self::FAMILLES;
    }

    /** Ramène un mode saisi à sa valeur de référence (ancien libellé du terrain). */
    public static function modeNormalise(?string $mode): ?string
    {
        $mode = trim((string) $mode);

        return self::MODES_SYNONYMES[$mode] ?? ($mode === '' ? null : $mode);
    }
}
