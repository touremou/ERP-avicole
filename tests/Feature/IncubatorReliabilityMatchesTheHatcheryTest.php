<?php

use App\Actions\Incubation\RecordHatching;
use App\Actions\Incubation\RecordMirage;
use App\Actions\Incubation\StartIncubation;
use App\Models\Batch;
use App\Models\Incubation;
use App\Models\Incubator;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DEUX ÉCRANS, UNE MACHINE, DEUX TAUX DE RÉUSSITE.
 *
 * `RecordHatching` et `RecordMirage` écrivaient `hatchability_rate` et
 * `fertility_rate` par `update()`. Ces deux colonnes sont ABSENTES du
 * `$fillable` d'`Incubation` : `update()` passe par `fill()`, qui jette sans un
 * mot toute clé non listée. Les deux taux sortaient NULL en base — à chaque
 * mirage et à chaque clôture, depuis toujours.
 *
 * C'est exactement ce qui était arrivé à `finished_at` — « une écriture qui
 * n'écrivait pas », dit le commentaire du `$fillable`, corrigé en ajoutant cette
 * SEULE colonne à la liste. Les deux taux étaient dans le même cas, à trois
 * lignes de là.
 *
 * ─── UN SEUL LECTEUR LISAIT LA COLONNE, ET IL SE TROMPAIT ───
 *
 * Tous les autres moyennent une COLLECTION, donc passent par les accesseurs :
 * `Incubator::global_success_rate`, les trois agrégats du tableau Couvoir, les
 * vues, les journaux de synchro. Seul `IncubatorController` faisait un
 * `avg('hatchability_rate')` de constructeur de requête — une moyenne SQL sur la
 * colonne vide.
 *
 * Mesuré, sur un cycle miré à 800 fertiles sur 900 et éclos à 700 poussins :
 *
 *   • écran Couvoir      : « Taux Éclosion 87,5 % » ;
 *   • écran Parc machines : « Fiabilité 0 % ».
 *
 * Même machine, même cycle, au même instant.
 *
 * ─── LA RÈGLE RETENUE ───
 *
 * Le taux est DÉRIVÉ (éclos ÷ fertiles). Sa déclaration vivante est l'accesseur,
 * exposé par `$appends`. On ne le stocke donc pas : une valeur dérivée gardée en
 * double finit par diverger — le défaut même qu'on répare ici. Les écritures
 * mortes sont retirées, et le dernier lecteur SQL passe par l'accesseur.
 *
 * Conséquence pratique : tout l'historique redevient juste immédiatement, sans
 * reprise de données. Remplir la colonne aurait laissé chaque cycle passé à 0.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
    ]);

    $this->couveuse = Incubator::create([
        'farm_id' => $this->farm->id, 'name' => 'Couveuse A',
        'capacity' => 10_000, 'status' => 'Disponible',
    ]);

    Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'L', 'category' => Stock::CAT_OEUFS,
        'unit' => 'Alvéole', 'current_quantity' => 500, 'alert_threshold' => 0,
    ]);
});

/** Un cycle mené jusqu'à l'éclosion : $oeufs mis à couver, $fertiles mirés, $poussins éclos. */
function cycleMeneAuBout(int $lotId, int $couveuseId, int $oeufs, int $fertiles, int $poussins): Incubation
{
    $cycle = (new StartIncubation())->execute([
        'incubator_id' => $couveuseId,
        'batch_id'     => $lotId,
        'provider_id'  => null,
        'start_date'   => today()->toDateString(),
        'eggs_count'   => $oeufs,
        'source_type'  => 'internal',
        'egg_grade'    => 'L',
        'duration'     => 21,
    ]);

    app(RecordMirage::class)->execute($cycle->fresh(), ['fertile_eggs' => $fertiles]);
    app(RecordHatching::class)->execute($cycle->fresh(), ['hatched_chicks' => $poussins]);

    return $cycle->fresh();
}

test('le PARC MACHINES annonce le même taux que le COUVOIR', function () {
    /*
     * LE défaut : 87,5 % d'un côté, 0 % de l'autre, sur la même machine.
     */
    $cycle = cycleMeneAuBout($this->lot->id, $this->couveuse->id, 900, 800, 700);

    expect($cycle->hatchability_rate)->toBe(87.5);

    $page = $this->get(route('incubators.index'));

    $page->assertOk()->assertSee('88%');          // round(87.5)
    expect($page->getContent())->not->toContain('>0%<');
});

test('la fiabilité de la machine EST la moyenne des cycles clos', function () {
    // Deux cycles : 87,5 % et 50 % → 68,75, arrondi à 68,8 par l'accesseur.
    cycleMeneAuBout($this->lot->id, $this->couveuse->id, 900, 800, 700);
    cycleMeneAuBout($this->lot->id, $this->couveuse->id, 200, 100, 50);

    expect($this->couveuse->fresh()->global_success_rate)->toBe(68.8);
});

test('un cycle EN COURS ne compte pas dans la fiabilité', function () {
    /*
     * La fiabilité est une moyenne des cycles TERMINÉS. Un cycle encore en
     * couveuse n'a pas de poussins : son taux vaut 0 par construction, et le
     * compter ferait tomber la performance de la machine de moitié à chaque
     * nouvelle mise à couver — la machine paraîtrait mauvaise précisément
     * pendant qu'elle travaille.
     */
    cycleMeneAuBout($this->lot->id, $this->couveuse->id, 900, 800, 700);   // 87,5 %

    (new StartIncubation())->execute([
        'incubator_id' => $this->couveuse->id, 'batch_id' => $this->lot->id,
        'provider_id' => null, 'start_date' => today()->toDateString(),
        'eggs_count' => 300, 'source_type' => 'internal', 'egg_grade' => 'L', 'duration' => 21,
    ]);

    expect($this->couveuse->fresh()->global_success_rate)->toBe(87.5);
});

test('une machine SANS cycle clos affiche zéro — non-régression', function () {
    // Le zéro doit rester possible : c'est l'état d'une machine neuve.
    expect($this->couveuse->fresh()->global_success_rate)->toBe(0.0);

    $this->get(route('incubators.index'))->assertOk();
});

test('un cycle SUPPRIMÉ ne tire plus la fiabilité — non-régression', function () {
    /*
     * La borne que le correctif précédent avait posée sur ces lignes : la
     * suppression douce doit écarter le cycle de la moyenne, comme elle
     * l'écarte du total produit affiché juste à côté.
     */
    cycleMeneAuBout($this->lot->id, $this->couveuse->id, 900, 800, 700);
    $rate = cycleMeneAuBout($this->lot->id, $this->couveuse->id, 200, 100, 50);

    expect($this->couveuse->fresh()->global_success_rate)->toBe(68.8);

    $rate->delete();   // SoftDeletes

    expect($this->couveuse->fresh()->global_success_rate)->toBe(87.5);
});

test('le taux de FERTILITÉ reste juste après le mirage — non-régression', function () {
    // L'autre écriture morte retirée : l'accesseur porte le taux, comme avant.
    $cycle = (new StartIncubation())->execute([
        'incubator_id' => $this->couveuse->id, 'batch_id' => $this->lot->id,
        'provider_id' => null, 'start_date' => today()->toDateString(),
        'eggs_count' => 900, 'source_type' => 'internal', 'egg_grade' => 'L', 'duration' => 21,
    ]);

    app(RecordMirage::class)->execute($cycle->fresh(), ['fertile_eggs' => 800]);

    $apres = $cycle->fresh();

    expect($apres->fertility_rate)->toBe(88.9)          // 800 / 900
        ->and((int) $apres->fertile_eggs)->toBe(800)
        ->and($apres->status)->toBe('mirage_fait');
});

test('l’éclosion écrit toujours poussins, statut et date — non-régression', function () {
    // On a retiré une clé de cet `update()` : les autres doivent tenir.
    $cycle = cycleMeneAuBout($this->lot->id, $this->couveuse->id, 900, 800, 700);

    expect((int) $cycle->hatched_chicks)->toBe(700)
        ->and($cycle->status)->toBe('clos')
        ->and($cycle->finished_at)->not->toBeNull();
});
