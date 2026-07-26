<?php

namespace App\Services\Import;

use App\Actions\Crop\RecordCropInput;
use App\Actions\Crop\RecordHarvest;
use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\Employee;
use App\Models\Harvest;
use App\Models\Plot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reprise d'historique CULTURES — import en lot depuis le classeur rempli par
 * les techniciens.
 *
 * ── DEUX TEMPS OBLIGATOIRES ──
 * `analyse()` lit, contrôle et rapporte ligne par ligne SANS RIEN ÉCRIRE ;
 * `commit()` rejoue la même analyse et n'enregistre que si elle est intègre, en
 * une seule transaction. Importer des mois de données à l'aveugle, et découvrir
 * à la moitié qu'une colonne était mal remplie, est le pire résultat possible :
 * on ne saurait plus ce qui est entré et ce qui manque.
 *
 * ── LES RÈGLES MÉTIER S'APPLIQUENT ──
 * Les récoltes passent par RecordHarvest et les intrants par RecordCropInput.
 * Un import qui écrirait directement en base contournerait le délai avant
 * récolte, la pesée obligatoire d'une récolte conservée, la valorisation du
 * stock au coût de production… et introduirait précisément les incohérences
 * que ces Actions existent pour empêcher.
 *
 * ── IDEMPOTENCE PARTIELLE, ASSUMÉE ──
 * Une parcelle ou un cycle dont le code existe déjà est RÉUTILISÉ : on peut
 * corriger le fichier et le re-téléverser sans créer de doublons. Les intrants
 * et récoltes, eux, n'ont pas de clé naturelle fiable (deux apports d'engrais
 * identiques le même jour sont possibles) : ils seraient ré-ajoutés. Le rapport
 * le dit explicitement plutôt que de deviner une déduplication qui effacerait
 * des saisies légitimes.
 */
class CropBackfillImporter
{
    /** Correspondance onglet → méthode de lecture. */
    private const SHEETS = [
        'Parcelles' => 'plots',
        'Cycles'    => 'cycles',
        'Intrants'  => 'inputs',
        'Recoltes'  => 'harvests',
    ];

    /**
     * Filet de RÉTRO-COMPATIBILITÉ : les premiers modèles diffusés plaçaient des
     * lignes d'exemple DANS les onglets de saisie, marquées par ce libellé. Le
     * marquage était incomplet — certaines lignes d'exemple passaient pour de
     * vraies données et provoquaient des erreurs sur des codes pourtant présents
     * dans le fichier. Le modèle actuel isole les exemples dans un onglet
     * « Exemples », que cet import ignore par son nom (il n'est pas dans SHEETS).
     * On conserve la détection pour les fichiers déjà téléchargés.
     */
    private const EXAMPLE_MARKER = 'Exemple — remplacez';

    /**
     * Analyse le fichier sans rien écrire.
     *
     * @return array{
     *   rows: array<string, array<int, array<string, mixed>>>,
     *   errors: array<int, array{sheet: string, line: int, message: string}>,
     *   counts: array<string, int>,
     *   existing: array{plots: array<int, string>, cycles: array<int, string>},
     *   ok: bool
     * }
     */
    public function analyse(string $path): array
    {
        $book = IOFactory::createReaderForFile($path);
        $book->setReadDataOnly(true);
        $spreadsheet = $book->load($path);

        $rows = [];
        $errors = [];

        foreach (self::SHEETS as $title => $key) {
            $sheet = $spreadsheet->getSheetByName($title);

            if (! $sheet) {
                // Un onglet absent n'est pas une erreur : on peut n'importer que
                // les parcelles, ou n'ajouter que des récoltes plus tard.
                $rows[$key] = [];
                continue;
            }

            $rows[$key] = $this->readSheet($sheet->toArray(null, true, false, false), $title, $key, $errors);
        }

        // ─── Cohérence entre onglets : les codes de liaison ───
        $plotCodes = array_map(fn ($r) => $r['code'], $rows['plots']);
        $existingPlots = Plot::whereIn('code', $plotCodes ?: ['~none~'])->pluck('code')->all();
        $knownPlots = array_unique(array_merge($plotCodes, Plot::pluck('code')->all()));

        foreach ($rows['cycles'] as $row) {
            if (! in_array($row['plot_code'], $knownPlots, true)) {
                $errors[] = [
                    'sheet' => 'Cycles', 'line' => $row['_line'],
                    'message' => "code_parcelle « {$row['plot_code']} » inconnu. "
                        . $this->howToFix('parcelle', 'Parcelles', $knownPlots),
                ];
            }
        }

        $cycleCodes = array_map(fn ($r) => $r['code'], $rows['cycles']);
        $existingCycles = CropCycle::whereIn('code', $cycleCodes ?: ['~none~'])->pluck('code')->all();
        $knownCycles = array_unique(array_merge($cycleCodes, CropCycle::pluck('code')->all()));

        foreach (['inputs' => 'Intrants', 'harvests' => 'Recoltes'] as $key => $title) {
            foreach ($rows[$key] as $row) {
                if (! in_array($row['cycle_code'], $knownCycles, true)) {
                    $errors[] = [
                        'sheet' => $title, 'line' => $row['_line'],
                        'message' => "code_cycle « {$row['cycle_code']} » inconnu. "
                            . $this->howToFix('cycle', 'Cycles', $knownCycles),
                    ];
                }
            }
        }

        // Doublons de codes DANS le fichier : deux parcelles au même code
        // rendraient le rattachement ambigu.
        foreach ([['plots', 'Parcelles', 'code_parcelle'], ['cycles', 'Cycles', 'code_cycle']] as [$key, $title, $label]) {
            $seen = [];
            foreach ($rows[$key] as $row) {
                if (isset($seen[$row['code']])) {
                    $errors[] = [
                        'sheet' => $title, 'line' => $row['_line'],
                        'message' => "{$label} « {$row['code']} » apparaît déjà à la ligne {$seen[$row['code']]} : un code doit être unique.",
                    ];
                }
                $seen[$row['code']] ??= $row['_line'];
            }
        }

        usort($errors, fn ($a, $b) => [$a['sheet'], $a['line']] <=> [$b['sheet'], $b['line']]);

        return [
            'rows'   => $rows,
            'errors' => $errors,
            'counts' => [
                'plots'    => count($rows['plots']),
                'cycles'   => count($rows['cycles']),
                'inputs'   => count($rows['inputs']),
                'harvests' => count($rows['harvests']),
            ],
            'existing' => ['plots' => $existingPlots, 'cycles' => $existingCycles],
            'ok'       => $errors === [],
        ];
    }

    /**
     * Enregistre le contenu du fichier. Tout-ou-rien.
     *
     * @return array{created: array<string, int>, reused: array<string, int>}
     */
    public function commit(string $path, ?int $userId = null): array
    {
        $analysis = $this->analyse($path);

        if (! $analysis['ok']) {
            throw new \RuntimeException('Le fichier comporte des erreurs : import refusé.');
        }

        return DB::transaction(function () use ($analysis, $userId) {
            $created = ['plots' => 0, 'cycles' => 0, 'inputs' => 0, 'harvests' => 0];
            $reused  = ['plots' => 0, 'cycles' => 0];

            // ─── Parcelles ───
            foreach ($analysis['rows']['plots'] as $row) {
                $plot = Plot::where('code', $row['code'])->first();

                if ($plot) {
                    $reused['plots']++;
                    continue;
                }

                Plot::create([
                    'code'            => $row['code'],
                    'name'            => $row['name'],
                    'area_ha'         => $row['area_ha'],
                    'location'        => $row['location'],
                    'soil_type'       => $row['soil_type'],
                    'irrigation_type' => $row['irrigation_type'],
                    'status'          => $row['status'] ?? Plot::STATUS_EN_CULTURE,
                    'notes'           => $row['notes'],
                ]);
                $created['plots']++;
            }

            // ─── Cycles ───
            foreach ($analysis['rows']['cycles'] as $row) {
                if (CropCycle::where('code', $row['code'])->exists()) {
                    $reused['cycles']++;
                    continue;
                }

                $plot = Plot::where('code', $row['plot_code'])->firstOrFail();

                CropCycle::create([
                    'plot_id'                => $plot->id,
                    'code'                   => $row['code'],
                    'crop_name'              => $row['crop_name'],
                    'variety'                => $row['variety'],
                    'planting_date'          => $row['planting_date'],
                    'area_used_ha'           => $row['area_used_ha'] ?? $plot->area_ha,
                    'expected_yield_kg'      => $row['expected_yield_kg'],
                    'total_acquisition_cost' => $row['acquisition_cost'] ?? 0,
                    'additional_costs'       => $row['additional_costs'] ?? 0,
                    'employee_id'            => $row['employee_id'],
                    'status'                 => CropCycle::STATUS_EN_COURS,
                    'notes'                  => $row['notes'],
                ]);
                $created['cycles']++;
            }

            // ─── Intrants AVANT les récoltes ───
            // L'ordre compte : le coût des intrants alimente le coût de production
            // du cycle, qui sert ensuite à valoriser au coût les récoltes versées
            // au stock. Inversé, les récoltes entreraient à un coût sous-évalué.
            $inputAction = app(RecordCropInput::class);
            foreach ($analysis['rows']['inputs'] as $row) {
                $cycle = CropCycle::where('code', $row['cycle_code'])->firstOrFail();

                $inputAction->execute($cycle, [
                    'type'             => $row['type'],
                    'name'             => $row['name'],
                    'quantity'         => $row['quantity'],
                    'unit'             => $row['unit'],
                    'unit_cost'        => $row['unit_cost'],
                    'total_cost'       => $row['total_cost'],
                    'input_date'       => $row['input_date'],
                    'preharvest_days'  => $row['preharvest_days'],
                    'notes'            => $row['notes'],
                ]);
                $created['inputs']++;
            }

            // ─── Récoltes ───
            $harvestAction = app(RecordHarvest::class);
            foreach ($analysis['rows']['harvests'] as $row) {
                $cycle = CropCycle::where('code', $row['cycle_code'])->firstOrFail();

                $harvestAction->execute($cycle->fresh(), [
                    'harvest_date'    => $row['harvest_date'],
                    'quantity'        => $row['quantity'],
                    'unit'            => $row['unit'],
                    'net_weight_kg'   => $row['net_weight_kg'],
                    'loss_quantity'   => $row['loss_quantity'],
                    'quality'         => $row['quality'],
                    'destination'     => $row['destination'],
                    'unit_price'      => $row['unit_price'],
                    'sync_to_stock'   => $row['sync_to_stock'],
                    'notes'           => $row['notes'],
                ]);
                $created['harvests']++;
            }

            return ['created' => $created, 'reused' => $reused];
        });
    }

    // ─────────────────────────── LECTURE ───────────────────────────

    /**
     * @param  array<int, array<int, mixed>>  $raw
     * @param  array<int, array{sheet: string, line: int, message: string}>  $errors
     * @return array<int, array<string, mixed>>
     */
    /**
     * Message d'erreur ACTIONNABLE pour un code de liaison introuvable.
     *
     * « code_cycle inconnu » ne dit pas quoi faire. Le cas observé sur le
     * terrain : le technicien recopie un code depuis l'onglet EXEMPLES
     * (GOM-2026-01), croit donc qu'il « existe bien dans le fichier », et ne
     * voit pas qu'aucune ligne ne le déclare. On énonce donc les deux issues
     * réelles, et on CITE les codes valides — c'est ce qui permet de corriger
     * sans nous appeler.
     *
     * @param  array<int, string>  $known
     */
    private function howToFix(string $kind, string $sheet, array $known): string
    {
        $entity = $kind === 'cycle' ? 'ce cycle' : 'cette parcelle';

        $message = "Deux façons de corriger : ajoutez une ligne dans l'onglet « {$sheet} » "
            . "pour déclarer {$entity}, ou reprenez un code existant.";

        $valid = array_values(array_filter($known));
        sort($valid);

        if ($valid === []) {
            return $message . " Aucun code n'est encore enregistré : commencez par remplir l'onglet « {$sheet} ».";
        }

        $shown = array_slice($valid, 0, 8);
        $message .= ' Codes disponibles : ' . implode(', ', $shown);
        $message .= count($valid) > count($shown)
            ? ' … (liste complète dans l\'onglet « References »).'
            : '.';

        // Le piège précis : les codes d'EXEMPLE ressemblent à de vrais codes.
        return $message . " Attention : les codes de l'onglet « Exemples » ne sont pas des données, ils ne comptent pas.";
    }

    private function readSheet(array $raw, string $title, string $key, array &$errors): array
    {
        $rows = [];

        foreach ($raw as $index => $cells) {
            $line = $index + 1;

            if ($line === 1) {
                continue; // en-tête
            }

            // Ligne vide (l'utilisateur a supprimé un exemple) ou ligne d'exemple
            // laissée en place : on passe sans rien dire.
            if ($this->isBlank($cells) || $this->isExample($cells)) {
                continue;
            }

            $parsed = match ($key) {
                'plots'    => $this->parsePlot($cells),
                'cycles'   => $this->parseCycle($cells),
                'inputs'   => $this->parseInput($cells),
                'harvests' => $this->parseHarvest($cells),
            };

            foreach ($parsed['errors'] as $message) {
                $errors[] = ['sheet' => $title, 'line' => $line, 'message' => $message];
            }

            if ($parsed['errors'] === []) {
                $rows[] = $parsed['data'] + ['_line' => $line];
            }
        }

        return $rows;
    }

    /** @return array{data: array<string, mixed>, errors: array<int, string>} */
    private function parsePlot(array $c): array
    {
        $e = [];
        $code = $this->text($c[0] ?? null);
        $name = $this->text($c[1] ?? null);
        $area = $this->number($c[2] ?? null);

        if ($code === null) $e[] = 'code_parcelle est obligatoire.';
        if ($name === null) $e[] = 'nom est obligatoire.';
        if ($area === null || $area <= 0) $e[] = 'surface_ha est obligatoire et doit être supérieure à 0.';

        $status = $this->text($c[6] ?? null);
        if ($status !== null && ! in_array($status, Plot::STATUSES, true)) {
            $e[] = "statut « {$status} » inconnu (valeurs : " . implode(', ', Plot::STATUSES) . ').';
            $status = null;
        }

        return ['data' => [
            'code' => $code, 'name' => $name, 'area_ha' => $area,
            'location' => $this->text($c[3] ?? null), 'soil_type' => $this->text($c[4] ?? null),
            'irrigation_type' => $this->text($c[5] ?? null), 'status' => $status,
            'notes' => $this->text($c[7] ?? null),
        ], 'errors' => $e];
    }

    /** @return array{data: array<string, mixed>, errors: array<int, string>} */
    private function parseCycle(array $c): array
    {
        $e = [];
        $plotCode = $this->text($c[0] ?? null);
        $code = $this->text($c[1] ?? null);
        $crop = $this->text($c[2] ?? null);
        $planting = $this->date($c[4] ?? null, 'date_semis', $e);

        if ($plotCode === null) $e[] = 'code_parcelle est obligatoire.';
        if ($code === null) $e[] = 'code_cycle est obligatoire.';
        if ($crop === null) $e[] = 'culture est obligatoire.';

        if ($planting !== null && $planting->isFuture()) {
            $e[] = 'date_semis est dans le futur : une reprise d\'historique porte sur des cultures déjà en place.';
        }

        // Responsable : identifié par nom complet OU code employé. Introuvable =
        // avertissement bloquant, pour ne pas rattacher au hasard.
        $employeeId = null;
        $who = $this->text($c[9] ?? null);
        if ($who !== null) {
            $employeeId = $this->findEmployee($who);
            if ($employeeId === null) {
                $e[] = "responsable « {$who} » introuvable parmi les employés actifs (onglet References).";
            }
        }

        return ['data' => [
            'plot_code' => $plotCode, 'code' => $code, 'crop_name' => $crop,
            'variety' => $this->text($c[3] ?? null),
            'planting_date' => $planting?->toDateString(),
            'area_used_ha' => $this->number($c[5] ?? null),
            'expected_yield_kg' => $this->number($c[6] ?? null),
            'acquisition_cost' => $this->number($c[7] ?? null),
            'additional_costs' => $this->number($c[8] ?? null),
            'employee_id' => $employeeId,
            'notes' => $this->text($c[10] ?? null),
        ], 'errors' => $e];
    }

    /** @return array{data: array<string, mixed>, errors: array<int, string>} */
    private function parseInput(array $c): array
    {
        $e = [];
        $cycleCode = $this->text($c[0] ?? null);
        $date = $this->date($c[1] ?? null, 'date', $e);
        $type = $this->text($c[2] ?? null);
        $name = $this->text($c[3] ?? null);
        $quantity = $this->number($c[4] ?? null);

        if ($cycleCode === null) $e[] = 'code_cycle est obligatoire.';
        if ($name === null) $e[] = 'nom_produit est obligatoire.';
        if ($quantity === null || $quantity <= 0) $e[] = 'quantite est obligatoire et doit être supérieure à 0.';

        if ($type === null) {
            $e[] = 'type est obligatoire.';
        } elseif (! array_key_exists($type, CropInput::TYPES)) {
            $e[] = "type « {$type} » inconnu (valeurs : " . implode(', ', array_keys(CropInput::TYPES)) . ').';
        }

        $preharvest = $this->number($c[8] ?? null);
        if ($preharvest !== null && ($preharvest < 0 || $preharvest > 365)) {
            $e[] = 'delai_avant_recolte_jours doit être compris entre 0 et 365.';
        }

        return ['data' => [
            'cycle_code' => $cycleCode, 'input_date' => $date?->toDateString(),
            'type' => $type, 'name' => $name, 'quantity' => $quantity,
            'unit' => $this->text($c[5] ?? null) ?? 'kg',
            'unit_cost' => $this->number($c[6] ?? null),
            'total_cost' => $this->number($c[7] ?? null),
            'preharvest_days' => $preharvest !== null ? (int) $preharvest : null,
            'notes' => $this->text($c[9] ?? null),
        ], 'errors' => $e];
    }

    /** @return array{data: array<string, mixed>, errors: array<int, string>} */
    private function parseHarvest(array $c): array
    {
        $e = [];
        $cycleCode = $this->text($c[0] ?? null);
        $date = $this->date($c[1] ?? null, 'date', $e);
        $quantity = $this->number($c[2] ?? null);
        $unit = $this->text($c[3] ?? null) ?? 'kg';
        $netWeight = $this->number($c[4] ?? null);

        if ($cycleCode === null) $e[] = 'code_cycle est obligatoire.';
        if ($quantity === null || $quantity <= 0) $e[] = 'quantite est obligatoire et doit être supérieure à 0.';

        $quality = $this->text($c[6] ?? null);
        if ($quality !== null && ! in_array($quality, Harvest::QUALITIES, true)) {
            $e[] = "qualite « {$quality} » inconnue (valeurs : " . implode(', ', Harvest::QUALITIES) . ').';
            $quality = null;
        }

        $destination = $this->text($c[7] ?? null) ?? Harvest::DEST_VENTE;
        if (! array_key_exists($destination, Harvest::DESTINATIONS)) {
            $e[] = "destination « {$destination} » inconnue (valeurs : " . implode(', ', array_keys(Harvest::DESTINATIONS)) . ').';
        } elseif (in_array($destination, Harvest::DEST_HELD, true)) {
            // Miroir du garde-fou de RecordHarvest : une récolte conservée sans
            // poids en kg ne peut être ni valorisée ni transformée. On le dit à
            // l'analyse plutôt que de laisser l'import échouer à la ligne 300.
            $effective = Harvest::effectiveWeightKgFrom($netWeight, $unit, $quantity);
            if ($effective <= 0) {
                $e[] = "destination « {$destination} » : le poids_net_kg est obligatoire "
                     . '(une récolte conservée doit être pesée pour être valorisée en stock).';
            }
        }

        return ['data' => [
            'cycle_code' => $cycleCode, 'harvest_date' => $date?->toDateString(),
            'quantity' => $quantity, 'unit' => $unit, 'net_weight_kg' => $netWeight,
            'loss_quantity' => $this->number($c[5] ?? null) ?? 0,
            'quality' => $quality, 'destination' => $destination,
            'unit_price' => $this->number($c[8] ?? null),
            'sync_to_stock' => $this->boolean($c[9] ?? null),
            'notes' => $this->text($c[10] ?? null),
        ], 'errors' => $e];
    }

    // ─────────────────────────── CONVERSIONS ───────────────────────────

    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($this->text($cell) !== null) {
                return false;
            }
        }

        return true;
    }

    private function isExample(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (is_string($cell) && str_contains($cell, self::EXAMPLE_MARKER)) {
                return true;
            }
        }

        return false;
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** Nombre tolérant : virgule décimale et espaces de milliers acceptés. */
    private function number(mixed $value): ?float
    {
        $text = $this->text($value);
        if ($text === null) {
            return null;
        }

        $normalised = str_replace([' ', "\u{202f}", "\u{a0}", ','], ['', '', '', '.'], $text);

        return is_numeric($normalised) ? (float) $normalised : null;
    }

    /**
     * Date tolérante : sérial Excel, AAAA-MM-JJ, JJ/MM/AAAA. On refuse plutôt que
     * de deviner : une date mal interprétée décalerait tout un historique.
     *
     * @param  array<int, string>  $errors
     */
    private function date(mixed $value, string $column, array &$errors): ?Carbon
    {
        if ($value === null || $this->text($value) === null) {
            $errors[] = "{$column} est obligatoire.";

            return null;
        }

        // Cellule formatée en date par Excel : valeur numérique (sérial).
        if (is_numeric($value) && (float) $value > 1000) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
            } catch (\Throwable) {
                // On retombe sur l'analyse textuelle.
            }
        }

        $text = $this->text($value);

        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd.m.Y'] as $format) {
            try {
                // createFromFormat LÈVE (elle ne renvoie pas false) sur un format
                // qui ne correspond pas : on essaie chaque format et on retient
                // celui dont le re-formatage redonne EXACTEMENT la saisie — sinon
                // « 15/13/2026 » passerait en glissant sur janvier 2027.
                $parsed = Carbon::createFromFormat($format . '|', $text);
            } catch (\Throwable) {
                continue;
            }

            if ($parsed->format($format) === $text) {
                return $parsed->startOfDay();
            }
        }

        $errors[] = "{$column} « {$text} » n'est pas une date reconnue (attendu JJ/MM/AAAA ou AAAA-MM-JJ).";

        return null;
    }

    private function boolean(mixed $value): bool
    {
        $text = mb_strtolower($this->text($value) ?? '');

        return in_array($text, ['oui', 'o', 'yes', 'y', '1', 'true', 'vrai'], true);
    }

    /** Employé par nom complet, prénom + nom inversés, ou code employé. */
    private function findEmployee(string $needle): ?int
    {
        $normalise = fn (string $s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));
        $target = $normalise($needle);

        foreach (Employee::assignableInCurrentFarm()->get(['id', 'first_name', 'last_name', 'employee_id']) as $employee) {
            $candidates = [
                $normalise($employee->first_name . ' ' . $employee->last_name),
                $normalise($employee->last_name . ' ' . $employee->first_name),
                $normalise((string) $employee->employee_id),
            ];

            if (in_array($target, $candidates, true)) {
                return $employee->id;
            }
        }

        return null;
    }
}
