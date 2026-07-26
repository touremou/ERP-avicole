<?php

namespace App\Services\Import;

/**
 * FICHE PAPIER de reprise d'historique — le maillon qui manquait entre la
 * parcelle et le classeur.
 *
 * Le technicien qui connaît l'historique est dans le champ, sans ordinateur et
 * souvent sans réseau. Lui demander de remplir un classeur Excel revenait à
 * demander à quelqu'un d'autre de le faire à sa place, de mémoire — donc à
 * fabriquer des données fausses. Cette fiche s'imprime, se remplit au stylo, se
 * photographie et s'envoie par WhatsApp ; le bureau recopie ensuite dans le
 * classeur, qui reste l'unique porte d'entrée de l'import.
 *
 * Trois contraintes de conception, toutes dictées par le support :
 *
 *  1. MÊMES COLONNES QUE LE CLASSEUR, lues dans CropBackfillTemplate::definition().
 *     Une fiche recopiée à la main divergerait au premier ajout de colonne, et
 *     le technicien remplirait un formulaire qui ne correspond plus au fichier.
 *     La transcription doit être MÉCANIQUE : colonne 3 de la fiche = colonne 3
 *     du classeur, dans le même ordre.
 *  2. LES VALEURS ACCEPTÉES SONT IMPRIMÉES. Sur le papier il n'y a pas de liste
 *     déroulante : si les valeurs ne sont pas sous les yeux, le technicien écrit
 *     « engrai » ou « vendu », et la ligne sera refusée à l'import.
 *  3. LISIBLE EN PHOTO. Bordures franches, lignes hautes, en-têtes sombres,
 *     aucun gris clair — un gris à 10 % disparaît sur une photo prise à
 *     l'ombre d'un hangar. C'est le mode de retour prévu, pas un accident.
 */
class CropBackfillFieldSheet
{
    /**
     * Lignes vierges par section, calibrées pour REMPLIR la page.
     *
     * Une page à moitié vide se paie deux fois : en papier, et en allers-retours
     * quand le technicien n'a plus de lignes et griffonne dans la marge — ce qui
     * est illisible en photo. « Parcelles » en a moins parce que sa page porte
     * aussi le bloc d'identification et le mode d'emploi.
     */
    private const ROWS = [
        'Parcelles' => 13,
        'Cycles'    => 19,
        'Intrants'  => 19,
        'Recoltes'  => 19,
    ];

    /**
     * Intitulé humain et rôle de chaque section — le nom d'onglet seul
     * (« Recoltes ») ne dit pas quoi mettre dedans.
     */
    private const SECTIONS = [
        'Parcelles' => [
            'title' => 'A. PARCELLES',
            'hint'  => 'Une ligne par parcelle. Le code que vous inventez ici (ex. P1, KIN-A) sera recopié dans la section B.',
        ],
        'Cycles' => [
            'title' => 'B. CULTURES EN PLACE (cycles)',
            'hint'  => 'Une ligne par culture semée. Reprenez le code de la parcelle (section A) et inventez un code de cycle (ex. GOM-1) à reporter en C et D.',
        ],
        'Intrants' => [
            'title' => 'C. INTRANTS APPORTÉS',
            'hint'  => 'Une ligne par apport : semence, engrais, traitement, irrigation, main d\'œuvre. Reprenez le code de cycle de la section B.',
        ],
        'Recoltes' => [
            'title' => 'D. RÉCOLTES DÉJÀ FAITES',
            'hint'  => 'Une ligne par récolte. Reprenez le code de cycle de la section B.',
        ],
    ];

    /**
     * Données de rendu de la fiche.
     *
     * @return array{sections: array<int, array<string, mixed>>, generated_at: \Carbon\Carbon}
     */
    public function data(): array
    {
        $definition = CropBackfillTemplate::definition();
        $sections = [];

        foreach (self::SECTIONS as $key => $meta) {
            $columns = $definition[$key]['columns'];
            $choices = $definition[$key]['choices'];

            $sections[] = [
                'key'     => $key,
                'title'   => $meta['title'],
                'hint'    => $meta['hint'],
                'rows'    => self::ROWS[$key],
                'columns' => array_map(fn (array $column) => [
                    'name'     => $column[0],
                    'label'    => $this->humanise($column[0]),
                    'required' => $column[2],
                    // La largeur du classeur donne une proportion utilisable
                    // telle quelle : une colonne large dans Excel est une
                    // colonne où l'on écrit beaucoup, donc large sur le papier.
                    'weight'   => $column[1],
                ], $columns),
                // Seules les listes VRAIMENT fermées sont présentées comme
                // impératives. Une liste indicative (cultures, unités) est
                // annoncée comme un exemple, sinon le technicien s'interdirait
                // d'écrire une espèce absente du référentiel.
                'choices' => array_map(fn (string $name) => [
                    'label'  => $this->humanise($name),
                    'values' => $choices[$name]['values'],
                    'strict' => $choices[$name]['strict'],
                ], array_keys(array_filter($choices, fn ($choice) => $choice['values'] !== []))),
            ];
        }

        return [
            'sections'     => $sections,
            'generated_at' => now(),
        ];
    }

    /** « delai_avant_recolte_jours » → « Délai avant récolte (jours) ». */
    private function humanise(string $name): string
    {
        return [
            'code_parcelle'                  => 'Code parcelle',
            'nom'                            => 'Nom',
            'surface_ha'                     => 'Surface (ha)',
            'localisation'                   => 'Localisation',
            'type_sol'                       => 'Type de sol',
            'irrigation'                     => 'Irrigation',
            'statut'                         => 'Statut',
            'notes'                          => 'Observations',
            'code_cycle'                     => 'Code cycle',
            'culture'                        => 'Culture',
            'variete'                        => 'Variété',
            'date_semis'                     => 'Date de semis',
            'surface_utilisee_ha'            => 'Surface utilisée (ha)',
            'rendement_attendu_kg'           => 'Rendement attendu (kg)',
            'cout_semences_intrants_initial' => 'Coût semences (initial)',
            'couts_additionnels'             => 'Autres coûts',
            'responsable'                    => 'Responsable',
            'date'                           => 'Date',
            'type'                           => 'Type',
            'nom_produit'                    => 'Produit',
            'quantite'                       => 'Quantité',
            'unite'                          => 'Unité',
            'cout_unitaire'                  => 'Coût unitaire',
            'cout_total'                     => 'Coût total',
            'delai_avant_recolte_jours'      => 'Délai avant récolte (jours)',
            'poids_net_kg'                   => 'Poids net (kg)',
            'pertes'                         => 'Pertes',
            'qualite'                        => 'Qualité',
            'destination'                    => 'Destination',
            'prix_unitaire_kg'               => 'Prix unitaire (kg)',
            'verser_au_stock'                => 'Mettre en stock',
        ][$name] ?? ucfirst(str_replace('_', ' ', $name));
    }
}
