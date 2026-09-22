<?php

use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\Plot;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'ÉCRAN CONFIRMAIT UNE CORRECTION DE SÉCURITÉ ALIMENTAIRE QU'IL N'AVAIT PAS
 * FAITE.
 *
 * `preharvest_days` — le délai avant récolte de la notice — bloque la récolte
 * d'un cycle tant qu'il court : `CropCycle::activePreharvestInterval()` le lit,
 * `RecordHarvest` refuse la coupe, et la synchro rend un « conflict » non
 * rejouable.
 *
 * Un audit antérieur avait déjà corrigé son rejet silencieux : le champ est
 * validé aux TROIS portes de CRÉATION — web (`storeInput`), terrain
 * (`SyncService`), import — et le docblock de `PhytoWithdrawalService` acte que
 * « le stockage est corrigé ».
 *
 * L'ÉCRAN DE MODIFICATION n'avait jamais été repris. `updateInput` validait
 * neuf champs ; `preharvest_days` n'en faisait pas partie. Le formulaire, lui,
 * rend le champ — pré-rempli de la valeur enregistrée.
 *
 * ─── MESURÉ ───
 *
 * Traitement phytosanitaire saisi sans délai (oubli, ou saisie antérieure à la
 * correction). Le technicien reprend la notice du produit, ouvre la fiche et
 * corrige le délai à 21 jours :
 *
 *   • l'écran répond « Intrant mis à jour. » ;
 *   • `preharvest_days` reste NULL ;
 *   • `isHarvestBlocked()` reste FAUX — la récolte est toujours permise.
 *
 * Le geste qui devait armer la garde la laissait désarmée, en annonçant le
 * contraire. C'est la pire combinaison : le technicien a fait ce qu'il fallait,
 * le système lui a dit que c'était fait, et le produit part avec ses résidus.
 *
 * ─── LA RÈGLE POSÉE ───
 *
 * La même règle qu'à la création et qu'au terrain : une seule déclaration du
 * délai (`nullable|integer|min:0|max:365`), trois portes qui l'appliquent. Il
 * doit aussi pouvoir être RAMENÉ à zéro — un délai saisi par erreur qui
 * bloquerait indéfiniment une récolte serait l'autre moitié du même défaut.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->parcelle = Plot::create([
        'farm_id' => $this->farm->id, 'name' => 'Parcelle tomate',
        'area_ha' => 1, 'status' => Plot::STATUS_EN_CULTURE,
    ]);
});

/** Un cycle en cours, sur la parcelle du décor. */
function cyclePourCorrection(int $farmId, int $plotId): CropCycle
{
    return CropCycle::create([
        'farm_id' => $farmId, 'plot_id' => $plotId,
        'code' => 'TOM-' . Str::upper(Str::random(4)),
        'crop_name' => 'Tomate', 'planting_date' => now()->subMonths(2)->toDateString(),
        'area_used_ha' => 1, 'status' => CropCycle::STATUS_EN_COURS,
    ]);
}

/** Un traitement phyto appliqué il y a N jours, avec ce délai (ou aucun). */
function traitementDe(CropCycle $cycle, ?int $delai, int $ilYA = 2): CropInput
{
    return CropInput::create([
        'farm_id' => $cycle->farm_id, 'crop_cycle_id' => $cycle->id,
        'type' => 'phyto', 'name' => 'Insecticide X',
        'quantity' => 2, 'unit' => 'l', 'unit_cost' => 50_000, 'total_cost' => 100_000,
        'input_date' => now()->subDays($ilYA)->toDateString(),
        'preharvest_days' => $delai,
    ]);
}

/** Le geste de l'écran : corriger la fiche de l'intrant. */
function corrigerLIntrant(object $test, CropCycle $cycle, CropInput $intrant, array $champs = [])
{
    return $test->put(route('crop-cycles.inputs.update', [$cycle, $intrant]), array_merge([
        'type'       => 'phyto',
        'name'       => 'Insecticide X',
        'input_date' => $intrant->input_date->toDateString(),
        'quantity'   => 2,
        'unit'       => 'l',
        'unit_cost'  => 50_000,
        'total_cost' => 100_000,
    ], $champs));
}

test('corriger le délai par l’écran l’enregistre enfin', function () {
    /*
     * LE défaut : l'écran répondait « Intrant mis à jour. » et n'écrivait rien.
     */
    $cycle   = cyclePourCorrection($this->farm->id, $this->parcelle->id);
    $intrant = traitementDe($cycle, null);

    corrigerLIntrant($this, $cycle, $intrant, ['preharvest_days' => 21]);

    expect((int) $intrant->fresh()->preharvest_days)->toBe(21);
});

test('et la récolte devient alors INTERDITE', function () {
    /*
     * L'enjeu de bout en bout, et la seule chose qui compte vraiment : le geste
     * du technicien doit ARMER la garde. Éprouver l'écriture sans éprouver son
     * effet laisserait passer un champ stocké qui ne bloque rien.
     */
    $cycle   = cyclePourCorrection($this->farm->id, $this->parcelle->id);
    $intrant = traitementDe($cycle, null);

    expect($cycle->fresh()->isHarvestBlocked())->toBeFalse();   // avant : permise

    corrigerLIntrant($this, $cycle, $intrant, ['preharvest_days' => 21]);

    expect($cycle->fresh()->isHarvestBlocked())->toBeTrue();
});

test('et la RÉCOLTE elle-même est refusée', function () {
    /*
     * Par la garde réelle, celle que le web ET le terrain traversent tous deux :
     * `RecordHarvest` la porte « ICI = un seul point pour le web, la sync mobile
     * et tout appelant futur », dit son commentaire.
     *
     * Éprouver l'écriture du champ sans éprouver son EFFET laisserait passer une
     * valeur stockée qui ne bloque rien — et c'est précisément le défaut qu'un
     * audit antérieur avait déjà rencontré sur ce même champ.
     */
    $cycle   = cyclePourCorrection($this->farm->id, $this->parcelle->id);
    $intrant = traitementDe($cycle, null);

    corrigerLIntrant($this, $cycle, $intrant, ['preharvest_days' => 21]);

    expect(fn () => app(\App\Actions\Crop\RecordHarvest::class)->execute($cycle->fresh(), [
        'harvest_date' => now()->toDateString(),
        'quantity'     => 100,
        'unit'         => 'kg',
    ]))->toThrow(Exception::class, 'délai avant récolte');

    expect(\App\Models\Harvest::count())->toBe(0);
});

test('un délai saisi par ERREUR peut être ramené à zéro', function () {
    /*
     * L'autre moitié du même défaut : si la correction ne marchait que dans le
     * sens qui bloque, un délai saisi par erreur interdirait la récolte
     * indéfiniment, sans recours par l'écran.
     */
    $cycle   = cyclePourCorrection($this->farm->id, $this->parcelle->id);
    $intrant = traitementDe($cycle, 21);

    expect($cycle->fresh()->isHarvestBlocked())->toBeTrue();

    corrigerLIntrant($this, $cycle, $intrant, ['preharvest_days' => 0]);

    expect((int) $intrant->fresh()->preharvest_days)->toBe(0)
        ->and($cycle->fresh()->isHarvestBlocked())->toBeFalse();
});

test('l’écran PROPOSE le délai enregistré', function () {
    /*
     * Sans cela, rouvrir la fiche et enregistrer sans y toucher renverrait un
     * champ vide — et effacerait le délai. Le champ doit dire la vérité sur ce
     * qui est enregistré.
     */
    $cycle   = cyclePourCorrection($this->farm->id, $this->parcelle->id);
    $intrant = traitementDe($cycle, 21);

    $this->get(route('crop-cycles.inputs.edit', [$cycle, $intrant]))
        ->assertOk()
        ->assertSee('value="21"', false);
});

test('un délai hors bornes est refusé', function () {
    // La même borne qu'aux deux autres portes : 0 à 365.
    $cycle   = cyclePourCorrection($this->farm->id, $this->parcelle->id);
    $intrant = traitementDe($cycle, 21);

    corrigerLIntrant($this, $cycle, $intrant, ['preharvest_days' => 400])
        ->assertSessionHasErrors('preharvest_days');

    expect((int) $intrant->fresh()->preharvest_days)->toBe(21);   // inchangé
});

test('les autres champs de la fiche restent enregistrés — non-régression', function () {
    // On ajoute une règle, on n'en retire aucune.
    $cycle   = cyclePourCorrection($this->farm->id, $this->parcelle->id);
    $intrant = traitementDe($cycle, 7);

    corrigerLIntrant($this, $cycle, $intrant, [
        'name'            => 'Insecticide Y',
        'quantity'        => 5,
        'unit_cost'       => 30_000,
        'total_cost'      => 150_000,
        'preharvest_days' => 7,
    ]);

    $frais = $intrant->fresh();

    expect($frais->name)->toBe('Insecticide Y')
        ->and((float) $frais->quantity)->toBe(5.0)
        ->and((float) $frais->total_cost)->toBe(150_000.0);
});

test('un délai ÉCHU ne bloque plus — non-régression', function () {
    /*
     * La levée est automatique à la date : on ne ferme pas la récolte, on la
     * date. Sans cette mesure, une garde trop large ferait passer les tests
     * ci-dessus en bloquant tout.
     */
    $cycle   = cyclePourCorrection($this->farm->id, $this->parcelle->id);
    $intrant = traitementDe($cycle, null, ilYA: 30);

    corrigerLIntrant($this, $cycle, $intrant, ['preharvest_days' => 7]);

    expect((int) $intrant->fresh()->preharvest_days)->toBe(7)
        ->and($cycle->fresh()->isHarvestBlocked())->toBeFalse();
});
