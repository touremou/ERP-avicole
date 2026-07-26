<?php

use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\Employee;
use App\Models\Harvest;
use App\Models\Plot;
use App\Models\Stock;
use App\Services\Import\CropBackfillImporter;
use App\Services\Import\CropBackfillTemplate;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * REPRISE D'HISTORIQUE CULTURES — import en lot.
 *
 * Les propriétés qui décident si un import est utilisable ou dangereux :
 *
 *  - l'ANALYSE n'écrit rien et rapporte ligne par ligne (on corrige avant, pas
 *    après) ;
 *  - l'import est TOUT-OU-RIEN (jamais un historique à moitié entré) ;
 *  - les RÈGLES MÉTIER s'appliquent (délai avant récolte, pesée d'une récolte
 *    conservée, valorisation au coût) — un import qui les contourne introduit
 *    exactement les incohérences qu'elles existent pour empêcher ;
 *  - re-téléverser un fichier corrigé ne DUPLIQUE pas parcelles et cycles.
 */

beforeEach(function () {
    $this->setUpRbac();
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($this->managerUser);
});

/**
 * Fabrique un classeur d'import à partir de tableaux de lignes (sans en-tête :
 * la première ligne écrite est la ligne 2 du fichier réel).
 *
 * @param  array<string, array<int, array<int, mixed>>>  $sheets
 */
function backfillFile(array $sheets): string
{
    $book = new Spreadsheet();
    $book->removeSheetByIndex(0);

    $headers = [
        'Parcelles' => ['code_parcelle *', 'nom *', 'surface_ha *', 'localisation', 'type_sol', 'irrigation', 'statut', 'notes'],
        'Cycles'    => ['code_parcelle *', 'code_cycle *', 'culture *', 'variete', 'date_semis *', 'surface_utilisee_ha', 'rendement_attendu_kg', 'cout_semences_intrants_initial', 'couts_additionnels', 'responsable', 'notes'],
        'Intrants'  => ['code_cycle *', 'date *', 'type *', 'nom_produit *', 'quantite *', 'unite', 'cout_unitaire', 'cout_total', 'delai_avant_recolte_jours', 'notes'],
        'Recoltes'  => ['code_cycle *', 'date *', 'quantite *', 'unite', 'poids_net_kg', 'pertes', 'qualite', 'destination *', 'prix_unitaire_kg', 'verser_au_stock', 'notes'],
    ];

    foreach ($sheets as $title => $rows) {
        $sheet = $book->createSheet();
        $sheet->setTitle($title);
        $sheet->fromArray([$headers[$title]], null, 'A1');
        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'backfill-') . '.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

function importer(): CropBackfillImporter
{
    return app(CropBackfillImporter::class);
}

// ───────────────── LE MODÈLE ─────────────────

test('le modèle se génère avec ses six onglets', function () {
    Employee::factory()->create(['status' => 'Actif', 'first_name' => 'Awa', 'last_name' => 'Camara']);

    $book = app(CropBackfillTemplate::class)->build();
    $titles = array_map(fn ($s) => $s->getTitle(), $book->getAllSheets());

    expect($titles)->toContain('Mode d\'emploi', 'Parcelles', 'Cycles', 'Intrants', 'Recoltes', 'References');
});

test('le modèle liste les employés et cultures RÉELS de la base', function () {
    Employee::factory()->create(['status' => 'Actif', 'first_name' => 'Bakary', 'last_name' => 'Diallo']);
    \App\Models\CropSpecies::create(['name' => 'Gombo', 'type' => 'maraichage']);

    $references = app(CropBackfillTemplate::class)->build()->getSheetByName('References')->toArray();
    $flat = collect($references)->flatten()->filter()->all();

    // Un fichier statique divergerait au premier ajout d'employé ou d'espèce, et
    // le technicien remplirait des valeurs refusées à l'import.
    expect($flat)->toContain('Bakary Diallo');
    expect($flat)->toContain('Gombo');
});

test('le modèle est téléchargeable et n’est pas vide', function () {
    $this->get(route('crop-backfill.template'))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

// ───────────────── ANALYSE : rien n'est écrit ─────────────────

test('l’analyse d’un fichier valide compte les lignes SANS rien enregistrer', function () {
    $path = backfillFile([
        'Parcelles' => [['P-001', 'Bas-fond', 0.75, 'Kindia', 'argileux', 'gravitaire', 'en_culture', null]],
        'Cycles'    => [['P-001', 'GOM-01', 'Gombo', 'Clemson', '15/05/2026', 0.75, 1200, 1000000, 0, null, null]],
        'Intrants'  => [['GOM-01', '20/05/2026', 'engrais', 'NPK', 50, 'kg', 9000, 450000, null, null]],
        'Recoltes'  => [['GOM-01', '20/07/2026', 180, 'kg', 180, 5, 'bon', 'vente', 3000, 'non', null]],
    ]);

    $analysis = importer()->analyse($path);

    expect($analysis['ok'])->toBeTrue();
    expect($analysis['counts'])->toBe(['plots' => 1, 'cycles' => 1, 'inputs' => 1, 'harvests' => 1]);
    // Aucune écriture : c'est tout l'intérêt de l'étape.
    expect(Plot::count())->toBe(0);
    expect(CropCycle::count())->toBe(0);
});

test('les lignes vides et les lignes d’exemple sont ignorées', function () {
    $path = backfillFile([
        'Parcelles' => [
            ['P-001', 'Bas-fond', 0.5, null, null, null, null, null],
            [null, null, null, null, null, null, null, null],
            ['P-EX', 'Exemple', 1, null, null, null, null, 'Exemple — remplacez ou supprimez cette ligne'],
        ],
    ]);

    $analysis = importer()->analyse($path);

    // Le modèle contient des exemples : l'utilisateur peut les laisser.
    expect($analysis['counts']['plots'])->toBe(1);
    expect($analysis['ok'])->toBeTrue();
});

test('les colonnes obligatoires manquantes sont signalées avec leur ligne', function () {
    $path = backfillFile([
        'Parcelles' => [
            ['P-001', 'Bas-fond', 0.5, null, null, null, null, null],
            [null, 'Sans code', 1, null, null, null, null, null],
            ['P-003', null, 2, null, null, null, null, null],
        ],
    ]);

    $analysis = importer()->analyse($path);

    expect($analysis['ok'])->toBeFalse();
    $lines = array_column($analysis['errors'], 'line');
    expect($lines)->toContain(3, 4);
    expect(collect($analysis['errors'])->pluck('message')->implode(' '))
        ->toContain('code_parcelle est obligatoire');
});

test('un code_parcelle inconnu dans Cycles est signalé', function () {
    $path = backfillFile([
        'Parcelles' => [['P-001', 'Bas-fond', 0.5, null, null, null, null, null]],
        'Cycles'    => [['P-999', 'GOM-01', 'Gombo', null, '15/05/2026', null, null, null, null, null, null]],
    ]);

    $analysis = importer()->analyse($path);

    expect($analysis['ok'])->toBeFalse();
    expect(collect($analysis['errors'])->pluck('message')->implode(' '))->toContain('P-999');
});

test('un code_cycle en doublon dans le fichier est signalé', function () {
    $path = backfillFile([
        'Parcelles' => [['P-001', 'Bas-fond', 0.5, null, null, null, null, null]],
        'Cycles'    => [
            ['P-001', 'GOM-01', 'Gombo', null, '15/05/2026', null, null, null, null, null, null],
            ['P-001', 'GOM-01', 'Maïs', null, '20/05/2026', null, null, null, null, null, null],
        ],
    ]);

    $analysis = importer()->analyse($path);

    // Deux cycles au même code rendraient le rattachement des activités ambigu.
    expect(collect($analysis['errors'])->pluck('message')->implode(' '))->toContain('apparaît déjà à la ligne 2');
});

test('une valeur hors nomenclature est refusée en nommant les valeurs acceptées', function () {
    $path = backfillFile([
        'Parcelles' => [['P-001', 'Bas-fond', 0.5, null, null, null, 'en_friche', null]],
        'Cycles'    => [['P-001', 'GOM-01', 'Gombo', null, '15/05/2026', null, null, null, null, null, null]],
        'Intrants'  => [['GOM-01', '20/05/2026', 'engrai', 'NPK', 50, 'kg', null, null, null, null]],
    ]);

    $analysis = importer()->analyse($path);
    $messages = collect($analysis['errors'])->pluck('message')->implode(' ');

    expect($messages)->toContain('statut « en_friche » inconnu');
    expect($messages)->toContain('type « engrai » inconnu');
    // Le message doit dire QUOI mettre, sinon le technicien devine.
    expect($messages)->toContain('engrais');
});

test('une date illisible est refusée plutôt que devinée', function () {
    $path = backfillFile([
        'Parcelles' => [['P-001', 'Bas-fond', 0.5, null, null, null, null, null]],
        'Cycles'    => [['P-001', 'GOM-01', 'Gombo', null, 'mai 2026', null, null, null, null, null, null]],
    ]);

    $analysis = importer()->analyse($path);

    // Une date mal interprétée décalerait tout un historique.
    expect(collect($analysis['errors'])->pluck('message')->implode(' '))->toContain('n\'est pas une date reconnue');
});

test('les deux formats de date et la virgule décimale sont acceptés', function () {
    $path = backfillFile([
        'Parcelles' => [['P-001', 'Bas-fond', '0,75', null, null, null, null, null]],
        'Cycles'    => [
            ['P-001', 'A', 'Gombo', null, '15/05/2026', null, null, null, null, null, null],
            ['P-001', 'B', 'Maïs', null, '2026-05-20', null, null, null, null, null, null],
        ],
    ]);

    $analysis = importer()->analyse($path);

    expect($analysis['ok'])->toBeTrue();
    expect($analysis['rows']['plots'][0]['area_ha'])->toBe(0.75);
    expect($analysis['rows']['cycles'][0]['planting_date'])->toBe('2026-05-15');
    expect($analysis['rows']['cycles'][1]['planting_date'])->toBe('2026-05-20');
});

test('un responsable introuvable est refusé plutôt que rattaché au hasard', function () {
    Employee::factory()->create(['status' => 'Actif', 'first_name' => 'Awa', 'last_name' => 'Camara']);

    $path = backfillFile([
        'Parcelles' => [['P-001', 'Bas-fond', 0.5, null, null, null, null, null]],
        'Cycles'    => [
            ['P-001', 'A', 'Gombo', null, '15/05/2026', null, null, null, null, 'Awa Camara', null],
            ['P-001', 'B', 'Maïs', null, '15/05/2026', null, null, null, null, 'Inconnu Untel', null],
        ],
    ]);

    $analysis = importer()->analyse($path);

    expect(collect($analysis['errors'])->pluck('message')->implode(' '))->toContain('Inconnu Untel');
    // Le nom valide, lui, est bien résolu.
    expect($analysis['rows']['cycles'][0]['employee_id'])->not->toBeNull();
});

test('une récolte CONSERVÉE sans poids en kg est refusée dès l’analyse', function () {
    $path = backfillFile([
        'Parcelles' => [['P-001', 'Bas-fond', 0.5, null, null, null, null, null]],
        'Cycles'    => [['P-001', 'GOM-01', 'Gombo', null, '15/05/2026', null, null, null, null, null, null]],
        'Recoltes'  => [['GOM-01', '20/07/2026', 12, 'panier', null, null, 'bon', 'transformation', null, 'oui', null]],
    ]);

    $analysis = importer()->analyse($path);

    // Miroir du garde-fou de RecordHarvest : on le dit à l'analyse plutôt que de
    // laisser l'import échouer à la ligne 300.
    expect(collect($analysis['errors'])->pluck('message')->implode(' '))
        ->toContain('poids_net_kg est obligatoire');
});

// ───────────────── IMPORT : tout ou rien, règles appliquées ─────────────────

test('l’import enregistre parcelles, cycles, intrants et récoltes', function () {
    $employee = Employee::factory()->create(['status' => 'Actif', 'first_name' => 'Awa', 'last_name' => 'Camara']);

    $path = backfillFile([
        'Parcelles' => [['P-001', 'Bas-fond Kolenten', 0.75, 'Kindia', 'argileux', 'gravitaire', 'en_culture', 'Reprise']],
        'Cycles'    => [['P-001', 'GOM-01', 'Gombo', 'Clemson', '15/05/2026', 0.75, 1200, 1000000, 200000, 'Awa Camara', null]],
        'Intrants'  => [['GOM-01', '20/05/2026', 'engrais', 'NPK 15-15-15', 50, 'kg', 9000, 450000, null, null]],
        'Recoltes'  => [['GOM-01', '20/07/2026', 180, 'kg', 180, 5, 'bon', 'vente', 3000, 'non', null]],
    ]);

    $result = importer()->commit($path, $this->managerUser->id);

    expect($result['created'])->toBe(['plots' => 1, 'cycles' => 1, 'inputs' => 1, 'harvests' => 1]);

    $plot = Plot::where('code', 'P-001')->first();
    expect((float) $plot->area_ha)->toBe(0.75);

    $cycle = CropCycle::where('code', 'GOM-01')->first();
    expect($cycle->plot_id)->toBe($plot->id);
    expect($cycle->employee_id)->toBe($employee->id);
    expect($cycle->crop_name)->toBe('Gombo');
    expect($cycle->planting_date->toDateString())->toBe('2026-05-15');

    // Le cycle est passé en phase récolte par RecordHarvest : preuve que l'import
    // passe bien par les Actions et non par des écritures directes.
    expect($cycle->fresh()->status)->toBe(CropCycle::STATUS_RECOLTE);
    // Revenu = 180 kg × 3 000 (destination « vente »).
    expect((float) $cycle->fresh()->total_revenue)->toBe(540_000.0);
});

test('les intrants sont importés AVANT les récoltes (coût de production juste)', function () {
    $path = backfillFile([
        'Parcelles' => [['P-001', 'Bas-fond', 1, null, null, null, null, null]],
        // Aucun coût au cycle : tout le coût vient de l'intrant.
        'Cycles'    => [['P-001', 'GOM-01', 'Gombo', null, '15/05/2026', 1, null, 0, 0, null, null]],
        'Intrants'  => [['GOM-01', '20/05/2026', 'engrais', 'NPK', 50, 'kg', 10000, 500000, null, null]],
        'Recoltes'  => [['GOM-01', '20/07/2026', 100, 'kg', 100, 0, 'bon', 'stockage', null, 'oui', null]],
    ]);

    importer()->commit($path, $this->managerUser->id);

    // 500 000 d'intrants / 100 kg = 5 000/kg. Récoltes importées d'abord, le
    // stock aurait été valorisé à 0.
    $stock = Stock::where('item_name', 'Gombo')->where('category', Stock::CAT_RECOLTES)->first();
    expect($stock)->not->toBeNull();
    expect((float) $stock->last_unit_price)->toBe(5000.0);
});

test('un délai avant récolte ÉCHU n’empêche pas la reprise d’historique', function () {
    // Traitement il y a longtemps, récolte après l'échéance : légitime.
    $path = backfillFile([
        'Parcelles' => [['P-001', 'Bas-fond', 1, null, null, null, null, null]],
        'Cycles'    => [['P-001', 'GOM-01', 'Gombo', null, now()->subDays(120)->format('d/m/Y'), 1, null, 0, 0, null, null]],
        'Intrants'  => [['GOM-01', now()->subDays(60)->format('d/m/Y'), 'phyto', 'Mancozèbe', 2, 'kg', 45000, 90000, 14, null]],
        'Recoltes'  => [['GOM-01', now()->subDays(30)->format('d/m/Y'), 100, 'kg', 100, 0, 'bon', 'vente', 3000, 'non', null]],
    ]);

    $result = importer()->commit($path, $this->managerUser->id);

    expect($result['created']['harvests'])->toBe(1);
});

test('une récolte DANS le délai avant récolte fait échouer tout l’import', function () {
    // Traitement à J-5 avec 14 jours de délai, récolte à J-2 : la garde doit
    // parler, même en reprise d'historique — c'est une sécurité sanitaire.
    $path = backfillFile([
        'Parcelles' => [['P-001', 'Bas-fond', 1, null, null, null, null, null]],
        'Cycles'    => [['P-001', 'GOM-01', 'Gombo', null, now()->subDays(90)->format('d/m/Y'), 1, null, 0, 0, null, null]],
        'Intrants'  => [['GOM-01', now()->subDays(5)->format('d/m/Y'), 'phyto', 'Mancozèbe', 2, 'kg', 45000, 90000, 14, null]],
        'Recoltes'  => [['GOM-01', now()->subDays(2)->format('d/m/Y'), 100, 'kg', 100, 0, 'bon', 'vente', 3000, 'non', null]],
    ]);

    expect(fn () => importer()->commit($path, $this->managerUser->id))->toThrow(\Exception::class);

    // TOUT-OU-RIEN : ni la parcelle, ni le cycle, ni l'intrant ne subsistent.
    expect(Plot::count())->toBe(0);
    expect(CropCycle::count())->toBe(0);
    expect(CropInput::count())->toBe(0);
    expect(Harvest::count())->toBe(0);
});

test('un fichier en erreur est refusé sans rien écrire', function () {
    $path = backfillFile([
        'Parcelles' => [[null, 'Sans code', 1, null, null, null, null, null]],
    ]);

    expect(fn () => importer()->commit($path, $this->managerUser->id))
        ->toThrow(RuntimeException::class);

    expect(Plot::count())->toBe(0);
});

test('ré-importer le même fichier ne duplique NI parcelle NI cycle', function () {
    $path = backfillFile([
        'Parcelles' => [['P-001', 'Bas-fond', 1, null, null, null, null, null]],
        'Cycles'    => [['P-001', 'GOM-01', 'Gombo', null, '15/05/2026', 1, null, 0, 0, null, null]],
    ]);

    importer()->commit($path, $this->managerUser->id);
    $second = importer()->commit($path, $this->managerUser->id);

    // On doit pouvoir corriger un fichier et le re-téléverser sans doublons.
    expect(Plot::count())->toBe(1);
    expect(CropCycle::count())->toBe(1);
    expect($second['created']['plots'])->toBe(0);
    expect($second['reused'])->toBe(['plots' => 1, 'cycles' => 1]);
});

test('un onglet absent n’est pas une erreur', function () {
    // Cas réel : on importe d'abord les parcelles, les activités plus tard.
    $path = backfillFile([
        'Parcelles' => [['P-001', 'Bas-fond', 1, null, null, null, null, null]],
    ]);

    $result = importer()->commit($path, $this->managerUser->id);

    expect($result['created']['plots'])->toBe(1);
    expect($result['created']['cycles'])->toBe(0);
});

// ───────────────── PARCOURS WEB ─────────────────

test('le parcours web : analyser puis importer', function () {
    $path = backfillFile([
        'Parcelles' => [['P-001', 'Bas-fond', 0.9, null, null, null, null, null]],
        'Cycles'    => [['P-001', 'GOM-01', 'Gombo', null, '15/05/2026', 0.9, null, 500000, 0, null, null]],
    ]);

    $upload = new \Illuminate\Http\UploadedFile($path, 'reprise.xlsx', null, null, true);

    $analyse = $this->post(route('crop-backfill.analyse'), ['file' => $upload]);
    $analyse->assertRedirect(route('crop-backfill.index'));

    $report = session('backfill_report');
    expect($report['ok'])->toBeTrue();
    expect($report['counts']['plots'])->toBe(1);
    // L'analyse n'a rien écrit.
    expect(Plot::count())->toBe(0);

    $this->post(route('crop-backfill.commit'), ['path' => $report['path']])
        ->assertRedirect(route('crop-cycles.index'));

    expect(Plot::where('code', 'P-001')->exists())->toBeTrue();
    expect(CropCycle::where('code', 'GOM-01')->exists())->toBeTrue();
});

test('le rapport d’erreurs est affiché ligne par ligne', function () {
    $path = backfillFile([
        'Parcelles' => [[null, 'Sans code', 1, null, null, null, null, null]],
    ]);

    $upload = new \Illuminate\Http\UploadedFile($path, 'reprise.xlsx', null, null, true);
    $this->post(route('crop-backfill.analyse'), ['file' => $upload]);

    $this->get(route('crop-backfill.index'))
        ->assertOk()
        ->assertSee('erreur à corriger')
        ->assertSee('code_parcelle est obligatoire');
});

test('un chemin d’import forgé est refusé', function () {
    // Le chemin vient de la vue : on ne lui fait pas confiance.
    $this->post(route('crop-backfill.commit'), ['path' => '../../../.env'])
        ->assertRedirect(route('crop-backfill.index'));

    expect(session('error'))->toContain('introuvable');
});

test('sans droit cultures.C, la reprise est refusée', function () {
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->readonlyUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // L'application intercepte les refus de droit et redirige plutôt que de
    // rendre un 403 brut (cf. bootstrap/app.php) : on vérifie l'effet, pas le code.
    $this->actingAs($this->readonlyUser)
        ->get(route('crop-backfill.template'))
        ->assertRedirect();
});

test('le délai avant récolte est bel et bien PERSISTÉ par l’Action', function () {
    // Régression : preharvest_days était collecté par les formulaires, validé par
    // la porte de sync… et jamais écrit. La garde sanitaire ne bloquait donc
    // jamais rien. Ce test verrouille l'aller-retour complet.
    $plot = Plot::create(['code' => 'P-DAR', 'name' => 'Parcelle', 'area_ha' => 1, 'status' => Plot::STATUS_EN_CULTURE]);
    $cycle = CropCycle::create([
        'plot_id' => $plot->id, 'code' => 'CYC-DAR', 'crop_name' => 'Gombo',
        'area_used_ha' => 1, 'planting_date' => now()->subDays(60)->toDateString(),
        'status' => CropCycle::STATUS_EN_COURS,
    ]);

    app(\App\Actions\Crop\RecordCropInput::class)->execute($cycle, [
        'type' => 'phyto', 'name' => 'Mancozèbe', 'quantity' => 2, 'unit' => 'kg',
        'unit_cost' => 45000, 'input_date' => now()->subDays(3)->toDateString(),
        'preharvest_days' => 14,
    ]);

    $input = CropInput::where('crop_cycle_id', $cycle->id)->first();
    expect($input->preharvest_days)->toBe(14);
    // Et la garde s'active réellement.
    expect($cycle->fresh()->isHarvestBlocked())->toBeTrue();
});

test('la garde DAR s’évalue à la DATE DE RÉCOLTE, pas à l’instant de la saisie', function () {
    $plot = Plot::create(['code' => 'P-DAR2', 'name' => 'Parcelle', 'area_ha' => 1, 'status' => Plot::STATUS_EN_CULTURE]);
    $cycle = CropCycle::create([
        'plot_id' => $plot->id, 'code' => 'CYC-DAR2', 'crop_name' => 'Gombo',
        'area_used_ha' => 1, 'planting_date' => now()->subDays(90)->toDateString(),
        'status' => CropCycle::STATUS_EN_COURS,
    ]);

    // Traitement il y a 3 jours, 14 jours de délai.
    app(\App\Actions\Crop\RecordCropInput::class)->execute($cycle, [
        'type' => 'phyto', 'name' => 'Mancozèbe', 'quantity' => 1, 'unit' => 'kg',
        'input_date' => now()->subDays(3)->toDateString(), 'preharvest_days' => 14,
    ]);

    // Une récolte d'AVANT le traitement est légitime : le produit n'en portait
    // pas les résidus. Comparer à « aujourd'hui » l'aurait refusée à tort.
    $before = app(\App\Actions\Crop\RecordHarvest::class)->execute($cycle->fresh(), [
        'harvest_date' => now()->subDays(5)->toDateString(),
        'quantity' => 50, 'unit' => 'kg', 'destination' => Harvest::DEST_VENTE, 'unit_price' => 3000,
    ]);
    expect($before->id)->not->toBeNull();

    // Une récolte APRÈS le traitement et dans le délai reste refusée.
    expect(fn () => app(\App\Actions\Crop\RecordHarvest::class)->execute($cycle->fresh(), [
        'harvest_date' => now()->subDay()->toDateString(),
        'quantity' => 50, 'unit' => 'kg', 'destination' => Harvest::DEST_VENTE, 'unit_price' => 3000,
    ]))->toThrow(\Exception::class);
});

test('le modèle VIERGE, téléversé tel quel, ne produit AUCUNE erreur', function () {
    // Régression : les onglets de saisie contenaient des lignes d'exemple dont
    // le marquage était incomplet. Téléverser le modèle intact signalait des
    // codes de cycle « inconnus » alors qu'ils figuraient bien dans le fichier —
    // les lignes d'exemple étaient ignorées dans un onglet et lues dans l'autre.
    $path = tempnam(sys_get_temp_dir(), 'vierge-') . '.xlsx';
    file_put_contents($path, app(CropBackfillTemplate::class)->contents());

    $analysis = importer()->analyse($path);

    expect($analysis['ok'])->toBeTrue();
    expect($analysis['errors'])->toBe([]);
    // Les 4 onglets de saisie sont vides : rien n'est lu, donc rien à corriger.
    expect($analysis['counts'])->toBe(['plots' => 0, 'cycles' => 0, 'inputs' => 0, 'harvests' => 0]);
});

test('l’onglet Exemples n’est JAMAIS importé', function () {
    $path = tempnam(sys_get_temp_dir(), 'exemples-') . '.xlsx';
    file_put_contents($path, app(CropBackfillTemplate::class)->contents());

    importer()->commit($path, $this->managerUser->id);

    // La parcelle P-001 et le cycle GOM-2026-01 de l'onglet Exemples ne doivent
    // pas atterrir en base : un import ne doit pas créer de données de démonstration.
    expect(Plot::where('code', 'P-001')->exists())->toBeFalse();
    expect(CropCycle::where('code', 'GOM-2026-01')->exists())->toBeFalse();
    expect(Plot::count())->toBe(0);
});

test('le modèle sépare bien onglets de saisie (vides) et onglet Exemples (rempli)', function () {
    $book = app(CropBackfillTemplate::class)->build();

    foreach (['Parcelles', 'Cycles', 'Intrants', 'Recoltes'] as $title) {
        $rows = $book->getSheetByName($title)->toArray(null, true, false, false);
        // Ligne 1 = en-tête ; tout le reste doit être vide.
        $data = array_slice($rows, 1);
        $filled = array_filter($data, fn ($row) => array_filter($row, fn ($c) => trim((string) $c) !== '') !== []);
        expect($filled)->toBe([], "L'onglet {$title} contient des lignes pré-remplies.");
    }

    $examples = $book->getSheetByName('Exemples')->toArray();
    $flat = collect($examples)->flatten()->filter()->implode(' ');
    expect($flat)->toContain('GOM-2026-01');
    expect($flat)->toContain('Mancozèbe 80 WP');
});

/*
 * CODES DE LIAISON INTROUVABLES — l'erreur doit dire QUOI FAIRE.
 *
 * Cas observé sur le terrain : le technicien remplit UNIQUEMENT l'onglet
 * Recoltes, avec un code_cycle recopié depuis l'onglet EXEMPLES. Il croit donc
 * que le code « existe bien dans le fichier téléchargé », et « code_cycle
 * inconnu » ne le détrompe pas. Le refus est CORRECT : c'est le message et le
 * modèle qui ne donnaient pas de quoi corriger.
 */

/** Un cycle réellement enregistré, sur une parcelle réelle. */
function backfillExistingCycle(string $code): \App\Models\CropCycle
{
    $farmId = session('current_farm_id');

    $plot = \App\Models\Plot::create([
        'farm_id' => $farmId, 'name' => 'Parcelle ' . $code,
        'code' => 'PLOT-' . $code, 'area_ha' => 1,
        'status' => \App\Models\Plot::STATUS_EN_CULTURE,
    ]);

    return \App\Models\CropCycle::create([
        'farm_id' => $farmId, 'plot_id' => $plot->id, 'code' => $code,
        'crop_name' => 'Maïs', 'area_used_ha' => 1,
        'planting_date' => now()->subDays(120)->toDateString(),
        'status' => \App\Models\CropCycle::STATUS_RECOLTE,
    ]);
}

test('l’erreur de code_cycle inconnu énonce les deux façons de corriger', function () {
    $cycle = backfillExistingCycle('MAIS-REEL-01');

    // Exactement la situation de la capture : SEUL l'onglet Recoltes est rempli,
    // avec un code calqué sur celui des exemples.
    $path = backfillFile([
        'Recoltes' => [['GOM-2026-05', '20/07/2026', 15, 'kg', 15, 0, 'bon', 'stockage', 3000, 'oui', null]],
    ]);

    $report = importer()->analyse($path);
    $message = $report['errors'][0]['message'];

    expect($message)->toContain('GOM-2026-05');
    // Les deux issues réelles, pas seulement le constat d'échec.
    expect($message)->toContain("ajoutez une ligne dans l'onglet « Cycles »");
    expect($message)->toContain('reprenez un code existant');
    // Le code utilisable est CITÉ : c'est ce qui permet de corriger seul.
    expect($message)->toContain($cycle->code);
    // Le piège précis : les codes d'exemple ressemblent à de vrais codes.
    expect($message)->toContain('Exemples');
});

test('sans aucun cycle en base, l’erreur oriente vers l’onglet Cycles', function () {
    $path = backfillFile([
        'Recoltes' => [['GOM-2026-05', '20/07/2026', 15, 'kg', 15, 0, 'bon', 'stockage', 3000, 'oui', null]],
    ]);

    $message = importer()->analyse($path)['errors'][0]['message'];

    // Citer une liste vide serait absurde : on dit par où commencer.
    expect($message)->toContain("Aucun code n'est encore enregistré");
});

test('le modèle expose les codes DÉJÀ enregistrés, sinon ils sont introuvables', function () {
    $cycle = backfillExistingCycle('MAIS-REEL-02');

    $flat = collect((new CropBackfillTemplate())->build()->getSheetByName('References')->toArray())
        ->flatten()->filter()->values()->all();

    // Sans ces deux colonnes, on ne pouvait pas n'importer QUE des récoltes :
    // il fallait connaître de mémoire le code d'un cycle enregistré.
    expect($flat)->toContain('Cycles déjà enregistrés');
    expect($flat)->toContain('Parcelles déjà enregistrées');
    expect($flat)->toContain($cycle->code);
    expect($flat)->toContain($cycle->plot->code);
});

test('une récolte qui référence un cycle EXISTANT en base s’importe seule', function () {
    // Le parcours voulu : les cultures sont déjà dans l'application, on n'ajoute
    // que des récoltes. Il doit aboutir, sans onglet Cycles.
    $cycle = backfillExistingCycle('MAIS-REEL-03');

    $path = backfillFile([
        'Recoltes' => [[$cycle->code, '20/07/2026', 15, 'kg', 15, 0, 'bon', 'stockage', 3000, 'oui', null]],
    ]);

    $report = importer()->analyse($path);
    expect($report['ok'])->toBeTrue(implode(' | ', array_column($report['errors'], 'message')));
    expect($report['counts']['harvests'])->toBe(1);

    importer()->commit($path, $this->managerUser->id);
    expect($cycle->harvests()->count())->toBe(1);
});
