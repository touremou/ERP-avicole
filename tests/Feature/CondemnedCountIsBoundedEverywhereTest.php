<?php

use App\Models\Batch;
use App\Models\FinishedProduct;
use App\Models\SlaughterOrder;
use App\Models\SlaughterResult;
use App\Services\Sync\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * ON POUVAIT SAISIR PLUS DE CARCASSES QU'ON N'AVAIT ABATTU DE SUJETS.
 *
 * Deux règles de cohérence des pesées vivaient dans le SEUL contrôleur web :
 * carcasse ≤ poids vif, et saisies sanitaires ≤ sujets abattus.
 *
 * `SlaughterService::executeSlaughter` — la porte COMMUNE du bureau et du
 * terrain — calculait `$effectiveQty = $actualQty - $condemned` sans jamais
 * vérifier le signe. Et la validation de la synchro, qui borne bien le poids
 * (`lte:total_live_weight_kg`), laissait `condemned_count` en
 * `nullable|integer|min:0` : aucune borne haute.
 *
 * Mesuré, par la synchro — 50 sujets abattus, 60 saisis à l'inspection :
 * ACCEPTÉ, statut « success », et alors
 *
 *   • `condemned_count` vaut 60 en base : le rapport HACCP (Σ condemned_count)
 *     compte plus de saisies que d'abattages ;
 *   • `avg_carcass_weight_kg` tombe à ZÉRO en silence — le diviseur étant
 *     négatif, la garde `> 0` l'écrase ;
 *   • 70 kg entrent en produits finis avec ZÉRO pièce : la viande existe au
 *     kilo et n'existe pas à la tête.
 *
 * Le même formulaire au bureau refuse exactement ces chiffres.
 *
 * ─── LA RÈGLE ───
 *
 * Elle vit sur le modèle (`SlaughterResult::weighingRefusals`) et les deux
 * portes la lisent : l'écran rend les motifs CHAMP PAR CHAMP, le service la
 * jette en `ValidationException` — donc un refus DÉFINITIF côté terrain, ce qui
 * est juste : une pesée fausse ne se corrige pas toute seule au rejeu.
 *
 * La règle du POIDS est déjà tenue des deux côtés ; on la remonte quand même,
 * pour qu'une carcasse plus lourde que l'animal vivant soit refusée là où le
 * calcul se fait, et pas seulement aux portes.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'initial_quantity' => 200,
        'current_quantity' => 200,
    ]);
});

/** Un ordre d'abattage planifié sur le lot de l'essai. */
function ordrePlanifie(int $farmId, int $batchId, int $userId, int $qty = 100): SlaughterOrder
{
    return SlaughterOrder::create([
        'farm_id'          => $farmId,
        'order_number'     => SlaughterOrder::generateNumber(),
        'batch_id'         => $batchId,
        'planned_date'     => today()->toDateString(),
        'planned_quantity' => $qty,
        'status'           => 'planifie',
        'requested_by'     => $userId,
    ]);
}

/** Pousse une exécution d'abattage par la file de synchro du terrain. */
function pousserAbattage(SlaughterOrder $ordre, int $abattus, float $vif, float $carcasse, int $saisies): array
{
    return app(SyncService::class)->handle('slaughter.execute', [
        'uuid'                    => (string) Str::uuid(),
        'slaughter_order_id'      => $ordre->id,
        'execution_date'          => today()->toDateString(),
        'actual_quantity'         => $abattus,
        'total_live_weight_kg'    => $vif,
        'total_carcass_weight_kg' => $carcasse,
        'condemned_count'         => $saisies,
    ]);
}

test('la SYNCHRO refuse plus de saisies que d’abattus', function () {
    /*
     * LE défaut : accepté, avec un effectif négatif en coulisses.
     */
    $ordre = ordrePlanifie($this->farm->id, $this->lot->id, $this->adminUser->id);

    $resultat = pousserAbattage($ordre, abattus: 50, vif: 100, carcasse: 70, saisies: 60);

    expect($resultat['status'])->toBe('conflict')
        ->and(SlaughterResult::withoutGlobalScopes()->count())->toBe(0)
        ->and(FinishedProduct::withoutGlobalScopes()->count())->toBe(0);
});

test('rien n’entre en stock quand la pesée est refusée', function () {
    /*
     * La conséquence qui rendait le défaut coûteux : 70 kg de viande entraient
     * en produits finis avec ZÉRO pièce — existante au kilo, inexistante à la
     * tête. La transaction doit retomber en entier.
     */
    $ordre = ordrePlanifie($this->farm->id, $this->lot->id, $this->adminUser->id);

    pousserAbattage($ordre, abattus: 50, vif: 100, carcasse: 70, saisies: 60);

    expect($ordre->fresh()->status)->toBe('planifie');
});

test('une pesée COHÉRENTE passe toujours par la synchro — non-régression', function () {
    /*
     * LA borne : on ferme une incohérence, pas la porte. Le geste courant du
     * terrain doit continuer de passer, saisies comprises.
     */
    $ordre = ordrePlanifie($this->farm->id, $this->lot->id, $this->adminUser->id);

    $resultat = pousserAbattage($ordre, abattus: 50, vif: 100, carcasse: 70, saisies: 5);

    expect($resultat['status'])->toBe('success');

    $enregistre = SlaughterResult::withoutGlobalScopes()->firstOrFail();

    expect((int) $enregistre->condemned_count)->toBe(5)
        // 70 kg pour 45 carcasses retenues (50 − 5) = 1,556 kg pièce.
        ->and((float) $enregistre->avg_carcass_weight_kg)->toBe(1.556);
});

test('saisir AUTANT que d’abattus reste permis — la borne exacte', function () {
    /*
     * Tout le lot condamné : c'est rare, c'est grave, et c'est légitime. La
     * comparaison doit être stricte, sinon on interdirait d'enregistrer la pire
     * des journées.
     */
    $ordre = ordrePlanifie($this->farm->id, $this->lot->id, $this->adminUser->id);

    expect(pousserAbattage($ordre, abattus: 50, vif: 100, carcasse: 70, saisies: 50)['status'])
        ->toBe('success');
});

test('l’ÉCRAN refuse les mêmes chiffres, champ par champ — non-régression', function () {
    /*
     * L'égalité entre les deux portes, et la raison de garder une lecture
     * séparée dans le contrôleur : le motif doit rester attaché au champ fautif
     * à la saisie.
     */
    $ordre = ordrePlanifie($this->farm->id, $this->lot->id, $this->adminUser->id);

    $this->post(route('slaughter.execute.store', $ordre), [
        'actual_quantity'         => 50,
        'total_live_weight_kg'    => 100,
        'total_carcass_weight_kg' => 70,
        'condemned_count'         => 60,
        'execution_date'          => today()->toDateString(),
    ])->assertSessionHasErrors('condemned_count');

    expect(SlaughterResult::withoutGlobalScopes()->count())->toBe(0);
});

test('une carcasse plus lourde que le vif est refusée par le SERVICE aussi', function () {
    /*
     * La seconde règle, remontée elle aussi : elle est déjà tenue aux deux
     * portes, mais elle doit vivre là où le calcul se fait. Une carcasse plus
     * lourde que l'animal vivant est une création de matière.
     */
    $ordre = ordrePlanifie($this->farm->id, $this->lot->id, $this->adminUser->id);

    expect(fn () => app(\App\Services\SlaughterService::class)->executeSlaughter($ordre, [
        'actual_quantity'         => 50,
        'total_live_weight_kg'    => 100,
        'total_carcass_weight_kg' => 120,
        'condemned_count'         => 0,
        'execution_date'          => today()->toDateString(),
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(SlaughterResult::withoutGlobalScopes()->count())->toBe(0);
});
