<?php

namespace App\Services\Import;

use App\Models\CropInput;
use App\Models\CropSpecies;
use App\Models\Employee;
use App\Models\Harvest;
use App\Models\Plot;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Modèle de reprise d'historique CULTURES — le classeur que les techniciens
 * remplissent pour importer en lot les parcelles, cycles et activités
 * antérieurs à l'application.
 *
 * GÉNÉRÉ, jamais figé : les listes de valeurs (types d'intrant, qualités,
 * destinations, unités, employés, cultures du catalogue) sont lues dans la base
 * au moment du téléchargement. Un fichier statique divergerait au premier ajout
 * d'espèce ou d'employé, et le technicien remplirait des valeurs refusées à
 * l'import.
 *
 * Deux partis pris de conception :
 *
 *  1. LES CODES FONT LE LIEN. `code_parcelle` et `code_cycle` sont saisis une
 *     fois puis réutilisés d'un onglet à l'autre. Sans clé lisible par un
 *     humain, il faudrait des identifiants techniques que personne ne connaît
 *     sur le terrain.
 *  2. LISTES DÉROULANTES PARTOUT où un ensemble fermé existe. Un technicien qui
 *     tape « engrai » au lieu de « engrais » produit une ligne en erreur qu'il
 *     faudra corriger et re-téléverser : autant l'empêcher à la source.
 *  3. LES EXEMPLES VIVENT DANS LEUR PROPRE ONGLET, jamais dans les onglets à
 *     remplir. Mélanger les deux obligeait l'import à DEVINER, à partir d'un
 *     marqueur textuel, ce qui était un exemple et ce qui était une vraie
 *     donnée — et il se trompait : téléverser le modèle intact produisait des
 *     erreurs sur des codes qui figuraient pourtant bien dans le fichier
 *     (lignes d'exemple ignorées d'un côté, lues de l'autre). Les onglets de
 *     saisie ne contiennent donc plus que leurs en-têtes.
 */
class CropBackfillTemplate
{
    /** Onglets, dans l'ordre de remplissage (chaque onglet dépend du précédent). */
    public const SHEETS = ['Parcelles', 'Cycles', 'Intrants', 'Recoltes'];

    /**
     * DÉFINITION PARTAGÉE des onglets — colonnes et valeurs acceptées.
     *
     * Source unique consommée par DEUX sorties : le classeur Excel (ci-dessous)
     * et la fiche papier (CropBackfillFieldSheet). Une fiche papier recopiée à
     * la main divergerait au premier ajout de colonne, et le technicien
     * remplirait un formulaire qui ne correspond plus au fichier — c'est
     * exactement l'erreur qu'on a payée sur les formulaires employé.
     *
     * `choices` est indexé par NOM de colonne (pas par lettre) : la lettre est
     * un détail d'Excel, le nom est ce que lit un humain sur le papier.
     *
     * @return array<string, array{
     *   columns: array<int, array{0: string, 1: int, 2: bool}>,
     *   choices: array<string, array{values: array<int, string>, strict: bool}>
     * }>
     */
    public static function definition(): array
    {
        $species = CropSpecies::orderBy('name')->pluck('name')->filter()->take(200)->values()->all();

        $employees = Employee::active()->orderBy('first_name')->get()
            ->map(fn (Employee $e) => trim($e->first_name . ' ' . $e->last_name))
            ->filter()->values()->all();

        // Codes des cycles DÉJÀ enregistrés : ce sont eux qu'on référence quand
        // on n'importe que des activités sur des cultures existantes.
        $cycleCodes = \App\Models\CropCycle::orderByDesc('id')->pluck('code')->filter()->take(60)->values()->all();

        return [
            'Parcelles' => [
                'columns' => [
                    ['code_parcelle', 22, true],
                    ['nom', 26, true],
                    ['surface_ha', 14, true],
                    ['localisation', 24, false],
                    ['type_sol', 18, false],
                    ['irrigation', 18, false],
                    ['statut', 16, false],
                    ['notes', 34, false],
                ],
                'choices' => [
                    'statut' => ['values' => Plot::STATUSES, 'strict' => true],
                ],
            ],
            'Cycles' => [
                'columns' => [
                    ['code_parcelle', 22, true],
                    ['code_cycle', 22, true],
                    ['culture', 22, true],
                    ['variete', 20, false],
                    ['date_semis', 16, true],
                    ['surface_utilisee_ha', 20, false],
                    ['rendement_attendu_kg', 22, false],
                    ['cout_semences_intrants_initial', 30, false],
                    ['couts_additionnels', 22, false],
                    ['responsable', 24, false],
                    ['notes', 30, false],
                ],
                'choices' => [
                    // Listes INDICATIVES : un producteur peut cultiver une espèce
                    // absente du référentiel, et un journalier peut ne pas avoir
                    // de fiche employé. Bloquer fermerait la saisie du réel.
                    'culture'     => ['values' => $species, 'strict' => false],
                    'responsable' => ['values' => $employees, 'strict' => false],
                ],
            ],
            'Intrants' => [
                'columns' => [
                    ['code_cycle', 22, true],
                    ['date', 16, true],
                    ['type', 22, true],
                    ['nom_produit', 28, true],
                    ['quantite', 14, true],
                    ['unite', 12, false],
                    ['cout_unitaire', 16, false],
                    ['cout_total', 16, false],
                    ['delai_avant_recolte_jours', 26, false],
                    ['notes', 30, false],
                ],
                'choices' => [
                    'type'  => ['values' => array_keys(CropInput::TYPES), 'strict' => true],
                    'unite' => ['values' => ['kg', 'g', 'l', 'ml', 'sac', 'unite', 'jour', 'heure'], 'strict' => false],
                    // Codes des cycles DÉJÀ en base, en liste indicative : on
                    // peut aussi référencer un cycle créé dans l'onglet Cycles du
                    // même fichier, qui n'existe pas encore en base.
                    'code_cycle' => ['values' => $cycleCodes, 'strict' => false],
                ],
            ],
            'Recoltes' => [
                'columns' => [
                    ['code_cycle', 22, true],
                    ['date', 16, true],
                    ['quantite', 14, true],
                    ['unite', 12, false],
                    ['poids_net_kg', 16, false],
                    ['pertes', 12, false],
                    ['qualite', 14, false],
                    ['destination', 18, true],
                    ['prix_unitaire_kg', 20, false],
                    ['verser_au_stock', 18, false],
                    ['notes', 30, false],
                ],
                'choices' => [
                    'code_cycle'      => ['values' => $cycleCodes, 'strict' => false],
                    'unite'           => ['values' => ['kg', 'sac', 'panier', 'caisse', 'botte', 'regime', 'unite'], 'strict' => false],
                    'qualite'         => ['values' => Harvest::QUALITIES, 'strict' => true],
                    'destination'     => ['values' => array_keys(Harvest::DESTINATIONS), 'strict' => true],
                    'verser_au_stock' => ['values' => ['oui', 'non'], 'strict' => true],
                ],
            ],
        ];
    }

    private const HEADER_FILL = 'FF1E293B';
    private const KEY_FILL    = 'FFFEF3C7';

    public function build(): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getProperties()
            ->setTitle('Reprise historique cultures')
            ->setDescription('Modèle d\'import en lot : parcelles, cycles de culture, intrants et récoltes.');

        $this->buildInstructions($book->getActiveSheet());
        $this->buildPlots($book->createSheet());
        $this->buildCycles($book->createSheet());
        $this->buildInputs($book->createSheet());
        $this->buildHarvests($book->createSheet());
        $this->buildExamples($book->createSheet());
        $this->buildReferences($book->createSheet());

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** Écrit le classeur et renvoie son contenu binaire. */
    public function contents(): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'crop-backfill-');

        try {
            (new Xlsx($this->build()))->save($temp);

            return (string) file_get_contents($temp);
        } finally {
            @unlink($temp);
        }
    }

    // ─────────────────────────── ONGLETS ───────────────────────────

    private function buildInstructions(Worksheet $sheet): void
    {
        $sheet->setTitle('Mode d\'emploi');

        $lines = [
            ['REPRISE D\'HISTORIQUE — CULTURES', 'title'],
            ['', ''],
            ['Ce classeur sert à importer EN LOT les cultures déjà en place et leurs activités,', ''],
            ['sans les ressaisir une par une.', ''],
            ['', ''],
            ['CE QUI EST DÉJÀ DANS LE FICHIER', 'head'],
            ['Parcelles et Cycles arrivent PRÉ-REMPLIS avec ce qui existe déjà dans', ''],
            ['l\'application (en gris italique). Ne les retapez pas : vos codes sont sous', ''],
            ['vos yeux, à l\'endroit même où vous devez les recopier. Ces lignes sont', ''],
            ['reconnues par leur CODE et réutilisées — jamais dupliquées.', ''],
            ['Intrants et Recoltes, eux, sont VIDES : une activité n\'a pas de code, donc', ''],
            ['elle serait ré-ajoutée à chaque téléversement. À vous de les saisir.', ''],
            ['Les exemples sont dans l\'onglet « Exemples » — à lire, jamais à remplir.', ''],
            ['Vous pouvez téléverser ce fichier partiellement rempli sans risque.', ''],
            ['Supprimer une ligne pré-remplie NE supprime rien dans l\'application.', ''],
            ['', ''],
            ['ORDRE DE REMPLISSAGE', 'head'],
            ['1. Parcelles — une ligne par parcelle. Le « code_parcelle » est votre référence.', ''],
            ['2. Cycles — une ligne par culture en place. Reprenez le code_parcelle, donnez un code_cycle.', ''],
            ['3. Intrants — une ligne par apport (semence, engrais, phyto…). Reprenez le code_cycle.', ''],
            ['4. Recoltes — une ligne par récolte déjà faite. Reprenez le code_cycle.', ''],
            ['', ''],
            ['LES CODES FONT LE LIEN', 'head'],
            ['Un code écrit dans « Parcelles » doit être RECOPIÉ À L\'IDENTIQUE dans « Cycles ».', ''],
            ['Idem pour code_cycle vers « Intrants » et « Recoltes ». C\'est ce qui rattache', ''],
            ['chaque activité à sa culture. Une faute de frappe = une ligne en erreur.', ''],
            ['', ''],
            ['CE QUI EST OBLIGATOIRE', 'head'],
            ['Les colonnes marquées * doivent être remplies. Les autres peuvent rester vides.', ''],
            ['Les dates s\'écrivent JJ/MM/AAAA (ex. 12/08/2026) ou AAAA-MM-JJ.', ''],
            ['Les nombres s\'écrivent avec un point ou une virgule décimale, sans séparateur de milliers.', ''],
            ['', ''],
            ['DEUX POINTS QUI COMPTENT', 'head'],
            ['• DESTINATION de récolte : « vente » si elle a été vendue, « transformation » si elle', ''],
            ['  part au séchage, « stockage » si vous la gardez pour vendre plus tard. Seule « vente »', ''],
            ['  compte comme un revenu. Une récolte conservée DOIT avoir un poids en kg.', ''],
            ['• DELAI_AVANT_RECOLTE d\'un produit phytosanitaire : le nombre de jours de la notice.', ''],
            ['  Il bloquera la récolte tant qu\'il court — renseignez-le, c\'est une sécurité sanitaire.', ''],
            ['', ''],
            ['AVANT D\'IMPORTER', 'head'],
            ['L\'application ANALYSE d\'abord le fichier et vous montre les erreurs ligne par ligne.', ''],
            ['Rien n\'est enregistré à ce stade. Vous corrigez, vous re-téléversez, puis vous validez.', ''],
            ['Un import est tout-ou-rien : jamais à moitié fait.', ''],
            ['', ''],
            ['RÉ-IMPORTER LE MÊME FICHIER', 'head'],
            ['Une parcelle ou un cycle dont le code existe déjà est RÉUTILISÉ, pas dupliqué.', ''],
            ['Vous pouvez donc corriger et re-téléverser sans créer de doublons.', ''],
            ['En revanche les intrants et récoltes seraient ajoutés une seconde fois :', ''],
            ['ne re-téléversez ces onglets que s\'ils n\'ont pas encore été importés.', ''],
            ['', ''],
            ['L\'onglet « References » liste toutes les valeurs acceptées.', ''],
            ['L\'onglet « Exemples » montre une ligne type pour chaque onglet.', ''],
        ];

        foreach ($lines as $index => [$text, $style]) {
            $row = $index + 1;
            $sheet->setCellValue("A{$row}", $text);

            if ($style === 'title') {
                $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16);
            } elseif ($style === 'head') {
                $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
                $sheet->getStyle("A{$row}")->getFont()->getColor()->setARGB('FF1E293B');
            }
        }

        $sheet->getColumnDimension('A')->setWidth(105);
    }

    private function buildPlots(Worksheet $sheet): void
    {
        $this->buildDataSheet($sheet, 'Parcelles');
    }

    private function buildCycles(Worksheet $sheet): void
    {
        $this->buildDataSheet($sheet, 'Cycles');
    }

    private function buildInputs(Worksheet $sheet): void
    {
        $this->buildDataSheet($sheet, 'Intrants');
    }

    private function buildHarvests(Worksheet $sheet): void
    {
        $this->buildDataSheet($sheet, 'Recoltes');
    }

    /**
     * Onglet de saisie, monté depuis la définition partagée : en-têtes (l'astérisque
     * des colonnes obligatoires est ajouté ici, il n'appartient pas au nom de la
     * colonne) puis listes déroulantes, la lettre étant déduite du RANG du nom.
     */
    private function buildDataSheet(Worksheet $sheet, string $name): void
    {
        $sheet->setTitle($name);

        $definition = static::definition()[$name];

        $this->writeHeader($sheet, array_map(
            fn (array $column) => [$column[0] . ($column[2] ? ' *' : ''), $column[1], $column[2]],
            $definition['columns']
        ));

        $letters = [];
        $letter = 'A';
        foreach ($definition['columns'] as $column) {
            $letters[$column[0]] = $letter;
            $letter = chr(ord($letter) + 1);
        }

        foreach ($definition['choices'] as $columnName => $choice) {
            if ($choice['values'] === []) {
                continue;
            }
            $this->dropdown($sheet, $letters[$columnName], $choice['values'], strict: $choice['strict']);
        }

        // Lignes DÉJÀ enregistrées (Parcelles et Cycles seulement) : le
        // producteur retrouve ses codes sous les yeux, à l'endroit même où il
        // doit les recopier. En gris italique pour qu'on voie tout de suite que
        // ce n'est pas de la saisie à faire.
        $existing = static::existingRows()[$name] ?? [];

        foreach (array_values($existing) as $index => $values) {
            $row = $index + 2;
            $letter = 'A';

            foreach ($values as $value) {
                if ($value !== null && $value !== '') {
                    // Texte forcé : « 0,75 » et « 15/05/2026 » ne doivent pas
                    // être réinterprétés par Excel selon sa locale.
                    $sheet->setCellValueExplicit($letter . $row, (string) $value, DataType::TYPE_STRING);
                }
                $letter = chr(ord($letter) + 1);
            }

            $sheet->getStyle('A' . $row . ':' . chr(ord('A') + count($definition['columns']) - 1) . $row)
                ->getFont()->setItalic(true)->getColor()->setARGB('FF64748B');
        }
    }

    /**
     * LIGNES DÉJÀ ENREGISTRÉES, pré-remplies dans les onglets de saisie.
     *
     * Le classeur sortait avec des onglets vides, y compris chez un producteur
     * qui a déjà cinq parcelles dans l'application. Il devait donc les
     * retrouver de mémoire pour rattacher un cycle ou une récolte — et le
     * symptôme final était un « code inconnu » sur un code recopié depuis les
     * exemples.
     *
     * SEULS Parcelles et Cycles sont pré-remplis, et c'est un choix, pas un
     * oubli : l'import les reconnaît par leur CODE et les RÉUTILISE sans rien
     * réécrire (voir CropBackfillImporter::commit). Re-téléverser le fichier
     * intact ne crée donc aucun doublon. Les intrants et les récoltes, eux,
     * n'ont aucune clé naturelle : pré-remplis, ils seraient ré-ajoutés à
     * chaque téléversement. L'asymétrie est expliquée dans le mode d'emploi,
     * sans quoi on croirait à une omission.
     *
     * @return array<string, array<int, array<int, mixed>>>
     */
    public static function existingRows(): array
    {
        $plots = Plot::orderBy('code')->take(300)->get();

        $cycles = \App\Models\CropCycle::with('plot')
            ->whereHas('plot')
            ->orderByDesc('id')->take(300)->get();

        return [
            'Parcelles' => $plots->map(fn (Plot $plot) => [
                $plot->code,
                $plot->name,
                $plot->area_ha,
                $plot->location,
                $plot->soil_type,
                $plot->irrigation_type,
                $plot->status,
                null, // notes : laissées vides, ce sont des observations de saisie
            ])->all(),

            'Cycles' => $cycles->map(fn ($cycle) => [
                $cycle->plot->code,
                $cycle->code,
                $cycle->crop_name,
                $cycle->variety,
                optional($cycle->planting_date)->format('d/m/Y'),
                $cycle->area_used_ha,
                $cycle->expected_yield_kg,
                null, // les coûts ne se re-déclarent pas : ils sont déjà comptés
                null,
                null,
                null,
            ])->all(),
        ];
    }

    private function buildReferences(Worksheet $sheet): void
    {
        $sheet->setTitle('References');

        $blocks = [
            ['Statuts de parcelle', Plot::STATUSES],
            ['Types d\'intrant', array_keys(CropInput::TYPES)],
            ['Qualités de récolte', Harvest::QUALITIES],
            ['Destinations de récolte', array_keys(Harvest::DESTINATIONS)],
            ['Employés actifs', Employee::active()->orderBy('first_name')->get()
                ->map(fn (Employee $e) => trim($e->first_name . ' ' . $e->last_name))->filter()->values()->all()],
            ['Cultures du catalogue', CropSpecies::orderBy('name')->pluck('name')->filter()->take(200)->values()->all()],
            // CODES DÉJÀ EN BASE. Sans eux, on ne pouvait pas n'importer QUE des
            // récoltes : il fallait connaître de mémoire le code d'un cycle
            // enregistré. Le symptôme observé était un « code_cycle inconnu » sur
            // un code recopié depuis les EXEMPLES, faute de savoir où trouver les
            // vrais. Ces deux colonnes se copient-collent dans les onglets.
            ['Parcelles déjà enregistrées', Plot::orderBy('code')->pluck('code')->filter()->take(300)->values()->all()],
            ['Cycles déjà enregistrés', \App\Models\CropCycle::orderByDesc('id')->pluck('code')->filter()->take(300)->values()->all()],
        ];

        $column = 'A';
        foreach ($blocks as [$title, $values]) {
            $sheet->setCellValue("{$column}1", $title);
            $sheet->getStyle("{$column}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle("{$column}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
            $sheet->getColumnDimension($column)->setWidth(28);

            foreach (array_values($values) as $index => $value) {
                $sheet->setCellValue($column . ($index + 2), $value);
            }

            $column = chr(ord($column) + 1);
        }

        // Légende des destinations : c'est la colonne qui change le sens
        // comptable d'une récolte, elle mérite une explication en clair.
        $sheet->setCellValue('A' . (count(Plot::STATUSES) + 4), 'Rappel destinations :');
        $sheet->getStyle('A' . (count(Plot::STATUSES) + 4))->getFont()->setBold(true);
        foreach (Harvest::DESTINATIONS as $key => $label) {
            $row = count(Plot::STATUSES) + 5 + array_search($key, array_keys(Harvest::DESTINATIONS), true);
            $sheet->setCellValue("A{$row}", "{$key} = {$label}");
        }
    }

    // ─────────────────────────── OUTILS ───────────────────────────

    /**
     * En-tête : fond sombre, colonnes obligatoires surlignées, ligne figée.
     *
     * @param  array<int, array{0: string, 1: int, 2: bool}>  $columns  [titre, largeur, obligatoire]
     */
    private function writeHeader(Worksheet $sheet, array $columns): void
    {
        $letter = 'A';
        foreach ($columns as [$title, $width, $required]) {
            $sheet->setCellValue("{$letter}1", $title);
            $sheet->getColumnDimension($letter)->setWidth($width);

            $style = $sheet->getStyle("{$letter}1");
            $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
            $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

            // Colonnes-clés (codes de liaison) surlignées sur 500 lignes : c'est
            // là que se jouent les erreurs de recopie.
            if ($required && str_contains($title, 'code_')) {
                $sheet->getStyle("{$letter}2:{$letter}501")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::KEY_FILL);
            }

            $letter = chr(ord($letter) + 1);
        }

        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->freezePane('A2');
        $sheet->getStyle('A1:' . chr(ord('A') + count($columns) - 1) . '1')
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    /**
     * Onglet EXEMPLES — à lire, jamais à remplir.
     *
     * L'import IGNORE cet onglet par son nom : aucune heuristique, aucun
     * marqueur à repérer. C'est ce qui rend la reprise prévisible.
     */
    private function buildExamples(Worksheet $sheet): void
    {
        $sheet->setTitle('Exemples');

        $blocks = [
            ['Parcelles', ['code_parcelle', 'nom', 'surface_ha', 'localisation', 'type_sol', 'irrigation', 'statut', 'notes'], [
                ['P-001', 'Bas-fond Kolenten', '0,75', 'Kindia — sortie nord', 'argileux', 'gravitaire', 'en_culture', ''],
            ]],
            ['Cycles', ['code_parcelle', 'code_cycle', 'culture', 'variete', 'date_semis', 'surface_utilisee_ha', 'rendement_attendu_kg', 'cout_semences_intrants_initial', 'couts_additionnels', 'responsable', 'notes'], [
                ['P-001', 'GOM-2026-01', 'Gombo', 'Clemson', '15/05/2026', '0,75', '1200', '1000000', '250000', '', ''],
            ]],
            ['Intrants', ['code_cycle', 'date', 'type', 'nom_produit', 'quantite', 'unite', 'cout_unitaire', 'cout_total', 'delai_avant_recolte_jours', 'notes'], [
                ['GOM-2026-01', '20/05/2026', 'engrais', 'NPK 15-15-15', '50', 'kg', '9000', '450000', '', ''],
                ['GOM-2026-01', '10/06/2026', 'phyto', 'Mancozèbe 80 WP', '2', 'kg', '45000', '90000', '14', 'Le délai de 14 j vient de la notice du produit'],
            ]],
            ['Recoltes', ['code_cycle', 'date', 'quantite', 'unite', 'poids_net_kg', 'pertes', 'qualite', 'destination', 'prix_unitaire_kg', 'verser_au_stock', 'notes'], [
                ['GOM-2026-01', '20/07/2026', '180', 'kg', '180', '5', 'bon', 'vente', '3000', 'non', 'Vendue au marché'],
                ['GOM-2026-01', '27/07/2026', '220', 'kg', '220', '0', 'bon', 'transformation', '', 'oui', 'Part au séchage — poids en kg OBLIGATOIRE'],
            ]],
        ];

        $row = 1;
        $sheet->setCellValue('A1', 'EXEMPLES — À LIRE, PAS À REMPLIR');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Recopiez la structure dans les onglets de saisie. Cet onglet n\'est jamais importé.');
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setARGB('FF64748B');
        $row = 4;

        foreach ($blocks as [$title, $headers, $rows]) {
            $sheet->setCellValue("A{$row}", 'Onglet ' . $title);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->getColor()->setARGB('FF1E293B');
            $row++;

            $letter = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($letter . $row, $header);
                $style = $sheet->getStyle($letter . $row);
                $style->getFont()->setBold(true)->setSize(8)->getColor()->setARGB('FFFFFFFF');
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
                $letter = chr(ord($letter) + 1);
            }
            $row++;

            foreach ($rows as $example) {
                $letter = 'A';
                foreach ($example as $value) {
                    // Texte forcé : on montre le FORMAT attendu (« 0,75 »,
                    // « 15/05/2026 »), qu'Excel ne doit pas réinterpréter.
                    $sheet->setCellValueExplicit($letter . $row, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->getStyle($letter . $row)->getFont()->setSize(9);
                    $letter = chr(ord($letter) + 1);
                }
                $row++;
            }

            $row += 2;
        }

        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setWidth(22);
        }
    }

    /**
     * Liste déroulante sur une colonne, de la ligne 2 à 501.
     *
     * `strict` à false = liste indicative (l'utilisateur peut saisir autre
     * chose) : indispensable pour les cultures et les unités, où le référentiel
     * ne peut pas être exhaustif. Sur un ensemble VRAIMENT fermé (destination,
     * qualité, type d'intrant), on bloque : une valeur hors liste serait de
     * toute façon refusée à l'import.
     *
     * @param  array<int, string>  $values
     */
    private function dropdown(Worksheet $sheet, string $column, array $values, bool $strict = true): void
    {
        if ($values === []) {
            return;
        }

        // Excel limite la formule inline d'une validation à 255 caractères.
        $formula = '"' . implode(',', $values) . '"';
        if (strlen($formula) > 255) {
            return;
        }

        for ($row = 2; $row <= 501; $row++) {
            $validation = $sheet->getCell($column . $row)->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle($strict ? DataValidation::STYLE_STOP : DataValidation::STYLE_INFORMATION)
                ->setAllowBlank(true)
                ->setShowInputMessage(true)
                ->setShowErrorMessage(true)
                ->setShowDropDown(true)
                ->setErrorTitle('Valeur non acceptée')
                ->setError('Choisissez une valeur dans la liste (onglet « References »).')
                ->setPromptTitle('Valeurs acceptées')
                ->setPrompt(implode(' · ', $values))
                ->setFormula1($formula);
        }
    }
}
