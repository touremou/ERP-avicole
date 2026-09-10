<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE TERRAIN POUVAIT DÉCLARER PLUS DE MORTS QUE DE SUJETS VIVANTS.
 *
 * « On ne retire pas plus de sujets qu'il n'y en a » vivait dans la seule
 * `StoreDailyCheckRequest`, donc sur le chemin du bureau. `RecordDailyCheck` est
 * pourtant la porte COMMUNE du bureau et du terrain, et la validation de la
 * synchro ne bornait chaque champ que par `min:0`.
 *
 * Mesuré — 500 morts déclarés sur un lot de 100 vivants :
 *
 *   • par le BUREAU  : refusé, « Impact total (500) dépasse l'effectif vivant
 *     (100) », effectif intact ;
 *   • par le TERRAIN : ACCEPTÉ. Le pointage garde « mortalité 500 », et
 *     `applyBatchImpact` plafonne l'effectif à ZÉRO avec une simple ligne de
 *     journal — « Effectif négatif bloqué ».
 *
 * Le lot se retrouvait donc vide, sa mortalité cumulée annonçait 500 %, et
 * l'alerte de surmortalité — que l'on vient justement de rebrancher sur les
 * morts réels — se déclenchait sur un chiffre impossible.
 *
 * ─── LA FORMULE VIVAIT EN DOUBLE ───
 *
 * L'impact sur l'effectif (morts + isolés + triés − rétablis) était déclaré sur
 * `DailyCheck::calculateNetImpact()` ET recopié à la main dans la requête web.
 * Deux exemplaires d'un même calcul, et aucun sur le chemin du terrain. La
 * formule est désormais unique (`DailyCheck::netImpactOf`), et la règle vit dans
 * l'action partagée, à côté de la cohérence d'infirmerie qui s'y trouvait déjà.
 *
 * ─── POURQUOI LE DELTA, ET PAS L'IMPACT BRUT ───
 *
 * Corriger un pointage existant ne doit être borné que par ce qu'il AJOUTE :
 * ramener 60 morts à 50 sur un lot déjà décrémenté ne retire rien de plus. Pour
 * une saisie neuve, l'impact existant vaut zéro et la règle se réduit
 * exactement à celle du bureau.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();

    // Contexte de ferme de l'API : `SetApiFarmContext` le résout depuis le
    // rattachement du compte. Sans lui, `farmScopedExists('batches')` refuse le
    // lot et la synchro répond « batch id invalide » — un refus qui n'a rien à
    // voir avec la règle testée ici.
    \Illuminate\Support\Facades\DB::table('farm_user')->updateOrInsert(
        ['farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id],
        ['is_default' => true, 'is_owner' => true, 'created_at' => now(), 'updated_at' => now()],
    );
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'initial_quantity' => 100,
        'current_quantity' => 100,
    ]);
});

/** Pousse un pointage par l'API de synchro, comme le téléphone le fait. */
function pousserPointageTerrain(object $test, \App\Models\User $agent, int $batchId, array $payload): array
{
    Sanctum::actingAs($agent);

    return $test->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(),
        'type'    => 'daily_check.create',
        'payload' => array_merge([
            'uuid'       => (string) Str::uuid(),
            'batch_id'   => $batchId,
            'check_date' => today()->toDateString(),
        ], $payload),
    ]]])->assertOk()->json('results.0');
}

test('le TERRAIN ne peut pas déclarer plus de morts que de vivants', function () {
    /*
     * LE défaut : accepté, effectif plafonné à zéro en silence.
     */
    $resultat = pousserPointageTerrain($this, $this->adminUser, $this->lot->id, ['mortality' => 500]);

    /*
     * « conflict » : le SyncController traite une `ValidationException` comme un
     * refus DÉFINITIF, non rejouable — le téléphone la sort de sa file vers le
     * bac « À corriger ». C'est ce qu'il faut : un chiffre impossible ne
     * deviendra pas possible au prochain essai.
     */
    expect($resultat['status'])->toBe('conflict')
        ->and(DailyCheck::withoutGlobalScopes()->count())->toBe(0)
        ->and((int) $this->lot->fresh()->current_quantity)->toBe(100);
});

test('l’IMPACT TOTAL est borné, pas seulement la mortalité', function () {
    /*
     * La formule compte aussi les isolements et les tris : 60 morts + 30 isolés
     * + 30 triés = 120 sur 100 vivants. Ne borner que la mortalité aurait
     * laissé passer exactement le même vidage de lot.
     */
    $resultat = pousserPointageTerrain($this, $this->adminUser, $this->lot->id, [
        'mortality'         => 60,
        'qty_quarantine_in' => 30,
        'qty_sorted_out'    => 30,
    ]);

    expect($resultat['status'])->toBe('conflict')
        ->and((int) $this->lot->fresh()->current_quantity)->toBe(100);
});

test('les RÉTABLIS reviennent en déduction — non-régression', function () {
    /*
     * L'autre bout de la formule : un sujet qui sort de l'infirmerie REVIENT à
     * l'effectif. Le compter comme une sortie interdirait des pointages
     * parfaitement normaux.
     */
    // Un pointage antérieur isole 30 sujets : l'effectif tombe à 70, et 30
    // sujets sont disponibles à l'infirmerie pour en ressortir.
    DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->lot->id,
        'check_date' => today()->subDays(2)->toDateString(),
        'mortality' => 0, 'qty_quarantine_in' => 30,
    ]);

    expect((int) $this->lot->fresh()->current_quantity)->toBe(70);

    $resultat = pousserPointageTerrain($this, $this->adminUser, $this->lot->id, [
        'mortality'          => 60,
        'qty_quarantine_out' => 30,
    ]);

    // 60 − 30 = 30 d'impact sur 70 vivants : accepté.
    expect($resultat['status'])->toBe('success')
        ->and((int) $this->lot->fresh()->current_quantity)->toBe(40);
});

test('un pointage NORMAL passe toujours par le terrain — non-régression', function () {
    // Le geste quotidien, celui qui doit surtout continuer de marcher.
    $resultat = pousserPointageTerrain($this, $this->adminUser, $this->lot->id, ['mortality' => 3]);

    expect($resultat['status'])->toBe('success')
        ->and((int) $this->lot->fresh()->current_quantity)->toBe(97);
});

test('vider EXACTEMENT le lot reste permis — la borne', function () {
    /*
     * Une hécatombe totale est rare et terrible, mais elle s'enregistre : la
     * comparaison doit être stricte, sinon on interdirait de saisir le pire des
     * jours.
     */
    $resultat = pousserPointageTerrain($this, $this->adminUser, $this->lot->id, ['mortality' => 100]);

    expect($resultat['status'])->toBe('success')
        ->and((int) $this->lot->fresh()->current_quantity)->toBe(0);
});

test('CORRIGER un pointage n’est borné que par ce qu’il ajoute', function () {
    /*
     * LA raison du delta, et elle compte : sans lui, la règle deviendrait
     * inapplicable dès qu'un lot est bas.
     *
     * 100 sujets, 90 morts pointés → il en reste 10. Corriger ce même pointage
     * à 95 morts n'en retire que 5 de plus, ce qui est possible. Comparer
     * l'impact BRUT (95) à l'effectif restant (10) refuserait la correction
     * d'une saisie pourtant légitime — et l'éleveur n'aurait aucun moyen de
     * rectifier son chiffre.
     */
    app(\App\Actions\DailyCheck\RecordDailyCheck::class)->execute([
        'batch_id'      => $this->lot->id,
        'check_date'    => today()->toDateString(),
        'mortality'     => 90,
        'health_status' => 'Normal',
        'feed_type'     => '',
        'feed_consumed' => 0,
    ]);

    expect((int) $this->lot->fresh()->current_quantity)->toBe(10);

    app(\App\Actions\DailyCheck\RecordDailyCheck::class)->execute([
        'batch_id'      => $this->lot->id,
        'check_date'    => today()->toDateString(),
        'mortality'     => 95,
        'health_status' => 'Normal',
        'feed_type'     => '',
        'feed_consumed' => 0,
    ]);

    expect((int) $this->lot->fresh()->current_quantity)->toBe(5)
        ->and((int) DailyCheck::withoutGlobalScopes()->value('mortality'))->toBe(95);
});

test('une CORRECTION qui dépasse quand même est refusée', function () {
    // La borne du delta : 90 morts pointés, puis 120 — il n'en reste que 10.
    app(\App\Actions\DailyCheck\RecordDailyCheck::class)->execute([
        'batch_id'      => $this->lot->id,
        'check_date'    => today()->toDateString(),
        'mortality'     => 90,
        'health_status' => 'Normal',
        'feed_type'     => '',
        'feed_consumed' => 0,
    ]);

    expect(fn () => app(\App\Actions\DailyCheck\RecordDailyCheck::class)->execute([
        'batch_id'      => $this->lot->id,
        'check_date'    => today()->toDateString(),
        'mortality'     => 120,
        'health_status' => 'Normal',
        'feed_type'     => '',
        'feed_consumed' => 0,
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect((int) $this->lot->fresh()->current_quantity)->toBe(10);
});

test('le BUREAU refuse les mêmes chiffres — non-régression', function () {
    /*
     * L'égalité entre les deux portes. Le bureau tenait déjà la règle ; il doit
     * continuer de la rendre CHAMP PAR CHAMP après qu'on a déplacé la formule.
     */
    $this->actingAs($this->adminUser)
        ->post(route('daily-checks.store'), [
            'batch_id'      => $this->lot->id,
            'check_date'    => today()->toDateString(),
            'mortality'     => 500,
            'health_status' => 'Normal',
            'feed_type'     => 'Provende',
            'feed_consumed' => 0,
        ])
        ->assertSessionHasErrors('mortality');

    expect((int) $this->lot->fresh()->current_quantity)->toBe(100);
});
