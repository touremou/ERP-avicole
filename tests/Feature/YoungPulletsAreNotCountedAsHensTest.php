<?php

use App\Models\Batch;
use App\Models\ProductionType;
use App\Models\EggProduction;
use App\Services\EggAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE RÉSUMÉ DU MATIN REPROCHAIT DE N'AVOIR PAS FAIT CE QUE LE FORMULAIRE INTERDIT.
 *
 * `EggAnalysisService` retenait TOUT lot de type « ponte », sans regarder son
 * âge. Or une poulette n'entre en ponte qu'à 18 semaines environ
 * (`Batch::minLayingAgeDays()`, déduit de la courbe de la souche). Un lot de six
 * semaines est bien un lot de ponte ; il ne pond pas pour autant.
 *
 * Le garde-fou existait pourtant, et il est appliqué à la SAISIE :
 * `RecordEggCollection` et `StoreEggProductionRequest` refusent tous deux une
 * collecte sur un lot trop jeune, via `canCollectEggs()` — dont le commentaire
 * dit « garde-fou partagé par la validation de collecte et la vue ». Le résumé
 * quotidien était le seul écran à ne pas le lire.
 *
 * ─── CE QUE ÇA PRODUISAIT, CHAQUE MATIN ───
 *
 * Sur un élevage qui démarre son cheptel (deux lots, 1 491 sujets, aucun en âge
 * de pondre) :
 *
 *   • « PAS DE COLLECTE » pour chaque lot — un reproche pour une saisie que
 *     l'application refuse d'enregistrer ;
 *   • « HDP global : 0 % (1 491 poules) » — annoncer 0 % dit « vos poules ont
 *     cessé de pondre », quand la vérité est « vous n'avez pas de pondeuses » ;
 *   • « Production totale (0 œufs) < 70 % de la moyenne 7j », en sévérité
 *     CRITIQUE — un effondrement impossible.
 *
 * Une alerte critique quotidienne et fausse ne coûte pas que de l'attention :
 * elle apprend à ne plus lire les autres.
 *
 * ─── CE QU'ON NE FAIT PAS DISPARAÎTRE ───
 *
 * Le lot qui a ATTEINT l'âge de pondre et ne rend rien. Là, l'absence de
 * collecte est une vraie anomalie, et elle doit sonner.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
});

/** Un lot de ponte d'un âge donné, logé et vivant. */
function lotDePonte(int $farmId, int $buildingId, int $effectif, int $ageJours): Batch
{
    // Le type LEGACY dérive du type de production : sans lui, la fabrique
    // retombe sur « reproducteur », que `byType('ponte')` n'attrape pas.
    $typePonte = ProductionType::where('slug', 'ponte')->firstOrFail();

    return Batch::factory()->create([
        'farm_id'            => $farmId,
        'building_id'        => $buildingId,
        'production_type_id' => $typePonte->id,
        'arrival_date'     => today()->subDays($ageJours)->toDateString(),
        'birth_date'       => today()->subDays($ageJours)->toDateString(),
        'initial_quantity' => $effectif,
        'current_quantity' => $effectif,
        'status'           => 'Actif',
    ]);
}

test('des POULETTES trop jeunes ne déclenchent aucune alerte', function () {
    /*
     * LE défaut, sur les données du terrain : deux lots de 6 et 1 semaines,
     * 1 491 sujets. Aucun ne peut pondre — le bloc œufs n'a rien à dire.
     */
    lotDePonte($this->farm->id, $this->building->id, 1_000, 41);   // ~6 semaines
    lotDePonte($this->farm->id, $this->building->id, 491, 6);      // ~1 semaine

    $rapport = (new EggAnalysisService())->getDailyReport();

    expect($rapport['has_layers'])->toBeFalse();

    expect((new EggAnalysisService())->buildWhatsAppBlock())->toBe('');
});

test('une poulette ne compte pas dans le DÉNOMINATEUR du HDP', function () {
    /*
     * Le second défaut : la poulette diluait le taux de ponte des lots qui
     * produisent. 500 pondeuses, 400 œufs → 80 %, et non 40 % parce qu'un lot
     * de poulettes de même taille traîne dans le calcul.
     */
    $pondeuses = lotDePonte($this->farm->id, $this->building->id, 500, 200);
    lotDePonte($this->farm->id, $this->building->id, 500, 30);   // poulettes

    EggProduction::create([
        'farm_id'              => $this->farm->id,
        'batch_id'             => $pondeuses->id,
        'production_date'      => today()->subDay()->toDateString(),
        'total_eggs_collected' => 400,
        'user_id'              => $this->adminUser->id,
    ]);

    $rapport = (new EggAnalysisService())->getDailyReport();

    expect($rapport['total_hens'])->toBe(500)
        ->and($rapport['global_hdp'])->toBe(80.0);
});

test('un lot EN ÂGE de pondre qui ne rend rien est TOUJOURS signalé', function () {
    /*
     * La borne qui compte autant que le défaut. Ne plus rien dire serait pire
     * que de trop dire : une pondeuse en production qui ne donne aucun œuf est
     * exactement ce que ce résumé doit remonter.
     */
    $pondeuses = lotDePonte($this->farm->id, $this->building->id, 500, 200);

    $rapport = (new EggAnalysisService())->getDailyReport();

    expect($rapport['has_layers'])->toBeTrue();

    $manquantes = collect($rapport['irregularities'])->where('type', 'missing_collection');

    expect($manquantes)->toHaveCount(1)
        ->and($manquantes->first()['batch'])->toBe($pondeuses->code);
});

test('le résumé ne mélange pas les deux : la poulette se tait, la pondeuse parle', function () {
    // Un lot de chaque : une seule anomalie, celle du lot en production.
    $pondeuses = lotDePonte($this->farm->id, $this->building->id, 500, 200);
    $poulettes = lotDePonte($this->farm->id, $this->building->id, 500, 30);

    $bloc = (new EggAnalysisService())->buildWhatsAppBlock();

    expect($bloc)->toContain($pondeuses->code)
        ->and($bloc)->not->toContain($poulettes->code);
});

test('l’alerte CRITIQUE de chute globale ne se déclenche plus sur un cheptel sans pondeuse', function () {
    /*
     * L'alerte la plus nuisible : sévérité critique, chaque matin, pour une
     * production qui ne peut pas exister.
     */
    lotDePonte($this->farm->id, $this->building->id, 1_000, 41);

    // Une collecte ancienne, pour que la moyenne 7j soit non nulle — sans elle
    // le test passerait pour la mauvaise raison.
    $ancien = lotDePonte($this->farm->id, $this->building->id, 500, 300);
    EggProduction::create([
        'farm_id'              => $this->farm->id,
        'batch_id'             => $ancien->id,
        'production_date'      => today()->subDays(4)->toDateString(),
        'total_eggs_collected' => 400,
        'user_id'              => $this->adminUser->id,
    ]);
    $ancien->delete();   // le lot producteur est parti : il ne reste que la poulette

    $rapport = (new EggAnalysisService())->getDailyReport();

    expect(collect($rapport['irregularities'] ?? [])->where('type', 'global_drop'))->toBeEmpty();
});

test('le TABLEAU DE BORD applique le même dénominateur', function () {
    /*
     * Le résumé quotidien et le tableau de bord doivent annoncer le MÊME taux de
     * ponte. Le second retenait tout lot « suivi en œufs », sans l'âge : deux
     * écrans, deux chiffres, pour la même journée.
     */
    $pondeuses = lotDePonte($this->farm->id, $this->building->id, 500, 200);
    lotDePonte($this->farm->id, $this->building->id, 500, 30);   // poulettes

    EggProduction::create([
        'farm_id'              => $this->farm->id,
        'batch_id'             => $pondeuses->id,
        'production_date'      => today()->toDateString(),
        'total_eggs_collected' => 400,
        'user_id'              => $this->adminUser->id,
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('hdp', fn ($hdp) => round((float) $hdp, 1) === 80.0);
});

test('un lot TROP JEUNE mais ayant des œufs enregistrés reste compté', function () {
    /*
     * La borne que la suite complète a révélée, et elle compte : filtrer sur le
     * seul âge aurait MASQUÉ des œufs réellement consignés — collecte saisie
     * avant que le garde-fou n'existe, reprise de données, souche précoce sans
     * norme renseignée. Faire disparaître d'un rapport une production
     * enregistrée serait pire que le bruit qu'on supprime : le bruit se voit,
     * l'effacement non.
     */
    $precoce = lotDePonte($this->farm->id, $this->building->id, 200, 30);

    EggProduction::create([
        'farm_id'              => $this->farm->id,
        'batch_id'             => $precoce->id,
        'production_date'      => today()->subDay()->toDateString(),
        'total_eggs_collected' => 160,
        'user_id'              => $this->adminUser->id,
    ]);

    $rapport = (new EggAnalysisService())->getDailyReport();

    expect($rapport['has_layers'])->toBeTrue()
        ->and($rapport['total_eggs'])->toBe(160.0)
        ->and($rapport['total_hens'])->toBe(200);
});
