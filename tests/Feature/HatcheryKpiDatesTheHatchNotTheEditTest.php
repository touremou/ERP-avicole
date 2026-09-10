<?php

use App\Models\Batch;
use App\Models\Incubation;
use App\Models\Incubator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE KPI DU COUVOIR DATAIT LES CYCLES DE LEUR DERNIÈRE RETOUCHE.
 *
 * Les indicateurs « 30 jours » de l'écran couvoir — total poussins, taux de
 * réussite moyen, fertilité moyenne — se bornaient sur `updated_at` :
 *
 *   Incubation::where('updated_at', '>=', now()->subDays(30))
 *
 * Or `updated_at` n'est pas la date d'un cycle : c'est la date de la dernière
 * écriture sur sa ligne. Et un cycle CLOS continue d'être écrit longtemps après
 * son éclosion — `ChickDispatchController::refreshCounters()` réécrit
 * `chicks_dispatched` et `chicks_remaining` à CHAQUE départ de poussins, et les
 * départs s'étalent sur des semaines (vers les bâtiments, vers la vente, en
 * perte).
 *
 * Mesuré : un cycle éclos il y a 40 jours, dont on sort les derniers poussins
 * aujourd'hui, rentre dans la fenêtre des 30 jours — avec la TOTALITÉ de son
 * éclosion. Le KPI gonfle exactement au moment où l'on finit de vider un vieux
 * cycle, et le mois qui vient de s'écouler se voit créditer des poussins nés le
 * mois d'avant.
 *
 * ─── LA COLONNE JUSTE EXISTE, ET TOUT LE RESTE LA LIT DÉJÀ ───
 *
 * `finished_at` est écrite par `RecordHatching` à la clôture, et c'est elle que
 * l'ERP lit partout ailleurs pour dater la production d'un cycle :
 *
 *   • `ChickDispatchController` en fait la `birth_date` des poussins ;
 *   • `TraceabilityController` l'imprime en « Produit le » ;
 *   • et le tableau de bord de la provenderie, qui pose la MÊME question sur
 *     ses lots de fabrication, borne son mois sur `finished_at`.
 *
 * ─── CE QU'UNE CORRECTION NÉGLIGENTE CASSERAIT ───
 *
 * La fenêtre porte aussi les cycles `mirage_fait`, qui alimentent la fertilité
 * moyenne. Ceux-là ne sont PAS finis : ils n'ont pas de `finished_at`, et basculer
 * toute la requête dessus les aurait fait disparaître en silence — le taux de
 * fertilité serait tombé à zéro sans qu'aucun test ne s'en plaigne. Pour un cycle
 * miré, la dernière écriture EST le mirage : c'est la bonne date, et elle le
 * reste.
 *
 * Les cycles clos d'avant l'existence de la colonne (`finished_at` nulle)
 * retombent sur `updated_at`, comme le fait déjà la traçabilité.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id'     => $this->farm->id,
        'building_id' => $this->building->id,
        'status'      => 'Actif',
    ]);

    $this->machine = Incubator::create([
        'farm_id'  => $this->farm->id,
        'name'     => 'Couveuse A',
        'capacity' => 50_000,
        'status'   => 'Disponible',
    ]);
});

/**
 * Un cycle dont on FIXE séparément la date d'éclosion et la date de dernière
 * écriture — c'est précisément ce que le défaut confondait.
 *
 * `updated_at` s'écrit en SQL direct : Eloquent le remet à `now()` à chaque
 * sauvegarde, et un test qui le poserait par le modèle ne mesurerait rien.
 */
function cycleDuCouvoir(
    int $farmId,
    int $lotId,
    int $machineId,
    string $code,
    string $statut,
    ?string $eclos,
    string $derniereEcriture,
    int $oeufs = 1_000,
    ?int $fertiles = 800,
    ?int $poussins = 700,
): Incubation {
    $cycle = Incubation::create([
        'farm_id'             => $farmId,
        'batch_id'            => $lotId,
        'incubator_id'        => $machineId,
        'code_incubation'     => $code,
        'start_date'          => now()->subDays(60)->toDateString(),
        'hatch_date_expected' => now()->subDays(39)->toDateString(),
        'eggs_count'          => $oeufs,
        'fertile_eggs'        => $fertiles,
        'hatched_chicks'      => $poussins,
        'status'              => $statut,
        'finished_at'         => $eclos,
    ]);

    DB::table('incubations')->where('id', $cycle->id)
        ->update(['updated_at' => $derniereEcriture]);

    return $cycle->fresh();
}

/** Les indicateurs « 30 jours » tels que l'écran couvoir les calcule. */
function kpiTrenteJours(object $test): array
{
    return $test->get(route('incubations.index'))->assertOk()->viewData('stats');
}

test('un cycle éclos il y a 40 jours ne rentre pas par la porte du dispatch', function () {
    /*
     * LE défaut : on sort aujourd'hui les derniers poussins d'un vieux cycle, et
     * ses 700 poussins sont recomptés dans le mois écoulé.
     */
    cycleDuCouvoir(
        $this->farm->id, $this->lot->id, $this->machine->id,
        'INC-VIEUX', 'clos',
        eclos: now()->subDays(40)->toDateTimeString(),
        derniereEcriture: now()->toDateTimeString(),   // un dispatch de ce matin
    );

    expect(kpiTrenteJours($this)['total_poussins'])->toBe(0);
});

test('un cycle éclos il y a 10 jours est bien compté — non-régression', function () {
    // La borne : on resserre la fenêtre sur la bonne date, on ne la ferme pas.
    cycleDuCouvoir(
        $this->farm->id, $this->lot->id, $this->machine->id,
        'INC-RECENT', 'clos',
        eclos: now()->subDays(10)->toDateTimeString(),
        derniereEcriture: now()->subDays(10)->toDateTimeString(),
        poussins: 500,
    );

    expect(kpiTrenteJours($this)['total_poussins'])->toBe(500);
});

test('les cycles MIRÉS alimentent toujours la fertilité — la borne', function () {
    /*
     * Ce qu'une correction négligente aurait cassé en silence : un cycle miré
     * n'a pas de `finished_at`. Sa dernière écriture EST son mirage.
     */
    cycleDuCouvoir(
        $this->farm->id, $this->lot->id, $this->machine->id,
        'INC-MIRE', 'mirage_fait',
        eclos: null,
        derniereEcriture: now()->subDays(5)->toDateTimeString(),
        oeufs: 1_000, fertiles: 900, poussins: null,
    );

    expect(kpiTrenteJours($this)['avg_fertility'])->toBe(90.0);
});

test('un mirage vieux de 40 jours reste hors fenêtre — non-régression', function () {
    // Le mirage se date comme avant : la règle ne change que pour les cycles clos.
    cycleDuCouvoir(
        $this->farm->id, $this->lot->id, $this->machine->id,
        'INC-MIRE-VIEUX', 'mirage_fait',
        eclos: null,
        derniereEcriture: now()->subDays(40)->toDateTimeString(),
        oeufs: 1_000, fertiles: 900, poussins: null,
    );

    expect(kpiTrenteJours($this)['avg_fertility'])->toBe(0.0);
});

test('un cycle clos SANS date d’éclosion retombe sur sa dernière écriture', function () {
    /*
     * Les lignes d'avant l'existence de la colonne ne doivent pas disparaître
     * en silence — c'est déjà ainsi que la traçabilité les traite.
     */
    cycleDuCouvoir(
        $this->farm->id, $this->lot->id, $this->machine->id,
        'INC-LEGACY', 'clos',
        eclos: null,
        derniereEcriture: now()->subDays(5)->toDateTimeString(),
        poussins: 300,
    );

    expect(kpiTrenteJours($this)['total_poussins'])->toBe(300);
});

test('le taux de réussite moyen suit la même fenêtre', function () {
    // Le vieux cycle ne doit pas non plus peser sur la moyenne du mois.
    cycleDuCouvoir(
        $this->farm->id, $this->lot->id, $this->machine->id,
        'INC-A', 'clos',
        eclos: now()->subDays(40)->toDateTimeString(),
        derniereEcriture: now()->toDateTimeString(),
        oeufs: 1_000, fertiles: 1_000, poussins: 100,        // 10 % — désastreux
    );

    cycleDuCouvoir(
        $this->farm->id, $this->lot->id, $this->machine->id,
        'INC-B', 'clos',
        eclos: now()->subDays(3)->toDateTimeString(),
        derniereEcriture: now()->subDays(3)->toDateTimeString(),
        oeufs: 1_000, fertiles: 1_000, poussins: 900,        // 90 %
    );

    expect(kpiTrenteJours($this)['avg_reussite'])->toBe(90.0);
});
