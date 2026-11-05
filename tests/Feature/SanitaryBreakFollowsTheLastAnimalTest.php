<?php

use App\Actions\Batch\CloseBatch;
use App\Actions\Batch\CreateBatch;
use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE BÂTIMENT SUIT L'EFFECTIF PHYSIQUE ; LE DOSSIER DU LOT SOLDÉ RESTE OUVERT.
 *
 * Décisions de l'exploitation :
 *   • le vide sanitaire commence à la sortie du DERNIER ANIMAL (effectif à 0),
 *     plus à la clôture comptable, souvent des semaines plus tard ;
 *   • un bâtiment en vide sanitaire ne reçoit pas de bande tant que le délai
 *     réglementaire n'est pas écoulé ou le protocole d'hygiène validé ;
 *   • un lot soldé mais non clôturé ne bloque pas son bâtiment ;
 *   • un pointage passé d'un lot SOLDÉ se corrige encore — profil autorisé,
 *     journal d'audit — et plus du tout une fois le lot clôturé.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);

    $this->building->update(['status' => Building::STATUS_OCCUPE]);
    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'type' => 'chair',
        'status' => 'Actif', 'initial_quantity' => 5, 'current_quantity' => 5,
        'arrival_date' => today()->subDays(30)->toDateString(),
    ]);
});

function derniersMorts(Batch $lot, int $morts): DailyCheck
{
    return DailyCheck::create([
        'farm_id' => $lot->farm_id, 'batch_id' => $lot->id,
        'check_date' => today()->toDateString(), 'mortality' => $morts,
    ]);
}

function nouvelleBande(int $batiment): Batch
{
    return app(CreateBatch::class)->execute([
        'code' => 'NB-' . uniqid(), 'type' => 'chair', 'building_id' => $batiment,
        'qty_alive' => 100, 'qty_dead' => 0, 'buy_price_per_unit' => 500,
        'arrival_date' => today()->toDateString(),
    ]);
}

function corrigerLePointage(Tests\TestCase $test, DailyCheck $pointage, int $morts)
{
    return $test->put(route('daily-checks.update', $pointage), [
        'mortality' => $morts, 'feed_consumed' => 0, 'feed_type' => 'Chair Démarrage',
        'qty_quarantine_in' => 0, 'qty_quarantine_out' => 0,
    ]);
}

// ── LE VIDE SANITAIRE À LA SORTIE DU DERNIER ANIMAL ──────────────────────

test('le dernier animal sorti : lot soldé, bâtiment en vide sanitaire dès aujourd’hui', function () {
    derniersMorts($this->lot, 5);

    $batiment = $this->building->fresh();
    expect($this->lot->fresh()->status)->toBe(Batch::STATUS_SOLDE)
        ->and($batiment->status)->toBe(Building::STATUS_DESINFECTION)
        ->and($batiment->disinfection_started_at->isToday())->toBeTrue();
});

test('une autre bande vit encore dans le bâtiment : pas de vide sanitaire', function () {
    Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'type' => 'chair',
        'status' => 'Actif', 'initial_quantity' => 50, 'current_quantity' => 50,
    ]);

    derniersMorts($this->lot, 5);

    expect($this->building->fresh()->status)->toBe(Building::STATUS_OCCUPE);
});

test('la clôture comptable d’un lot soldé ne relance pas le vide sanitaire', function () {
    derniersMorts($this->lot, 5);
    $debut = today()->subDays(10);
    $this->building->update(['disinfection_started_at' => $debut]);

    app(CloseBatch::class)->execute($this->lot->fresh(), [
        'actual_sell_price_per_unit' => 0, 'closing_date' => today()->toDateString(),
    ]);

    expect($this->building->fresh()->disinfection_started_at->toDateString())->toBe($debut->toDateString());
});

test('un lot actif clôturé avec ses animaux ouvre le vide sanitaire — non-régression', function () {
    app(CloseBatch::class)->execute($this->lot->fresh(), [
        'actual_sell_price_per_unit' => 1000, 'closing_date' => today()->toDateString(),
    ]);

    expect($this->building->fresh()->status)->toBe(Building::STATUS_DESINFECTION);
});

// ── PAS DE BANDE PENDANT LE VIDE SANITAIRE ───────────────────────────────

test('pendant le vide sanitaire, la mise en place est refusée', function () {
    derniersMorts($this->lot, 5);

    expect(fn () => nouvelleBande($this->building->id))
        ->toThrow(ValidationException::class, 'Vide sanitaire en cours');
});

test('le délai écoulé, ou le protocole d’hygiène validé, la bande s’installe', function () {
    derniersMorts($this->lot, 5);

    // Protocole validé : le responsable passe le bâtiment « Disponible ».
    $this->building->update(['status' => Building::STATUS_DISPONIBLE]);
    expect(nouvelleBande($this->building->id)->exists)->toBeTrue();

    // Délai écoulé, la libération automatique pas encore passée.
    $autre = Building::factory()->create(['name' => 'B-VS', 'type' => 'chair', 'capacity' => 1000,
        'status' => Building::STATUS_DESINFECTION,
        'disinfection_started_at' => now()->subDays(Building::sanitaryBreakDays() + 1)]);
    expect(nouvelleBande($autre->id)->exists)->toBeTrue();
});

test('le lot soldé non clôturé ne bloque pas : après le vide sanitaire, nouvelle bande, et sa clôture ne touche plus au bâtiment', function () {
    derniersMorts($this->lot, 5);
    $this->building->update(['status' => Building::STATUS_VIDE, 'disinfection_started_at' => null]);   // vide sanitaire achevé

    $suivante = nouvelleBande($this->building->id);
    expect($this->lot->fresh()->status)->toBe(Batch::STATUS_SOLDE);

    app(CloseBatch::class)->execute($this->lot->fresh(), [
        'actual_sell_price_per_unit' => 0, 'closing_date' => today()->toDateString(),
    ]);

    expect($this->building->fresh()->status)->toBe(Building::STATUS_OCCUPE)
        ->and($suivante->fresh()->status)->toBe(Batch::STATUS_ACTIF);
});

// ── CORRIGER UN POINTAGE D'UN LOT SOLDÉ ─────────────────────────────────

test('un profil autorisé corrige le pointage d’un lot soldé, et c’est journalisé', function () {
    $pointage = derniersMorts($this->lot, 5);

    corrigerLePointage($this, $pointage, 4)->assertSessionHasNoErrors()->assertSessionMissing('error');

    $journal = Activity::where('log_name', 'audit')
        ->where('subject_type', DailyCheck::class)->where('subject_id', $pointage->id)
        ->where('description', 'Correction d’un pointage sur lot soldé')->first();
    expect($pointage->fresh()->mortality)->toBe(4)
        ->and($journal)->not->toBeNull()
        ->and($journal->causer_id)->toBe($this->adminUser->id)
        ->and($journal->properties['avant']['mortality'])->toBe(5)
        ->and($journal->properties['apres']['mortality'])->toBe(4);

    // Un animal revenu au compte : le lot redevient actif, le bâtiment occupé.
    expect($this->lot->fresh()->status)->toBe(Batch::STATUS_ACTIF)
        ->and($this->building->fresh()->status)->toBe(Building::STATUS_OCCUPE);
});

test('sans le droit S, la correction d’un lot soldé est refusée', function () {
    $pointage = derniersMorts($this->lot, 5);
    $this->actingAs($this->managerUser);

    corrigerLePointage($this, $pointage, 4)->assertSessionHas('error');

    expect($pointage->fresh()->mortality)->toBe(5);
});

test('une fois le lot clôturé, plus aucune correction — même pour un administrateur', function () {
    $pointage = derniersMorts($this->lot, 5);
    app(CloseBatch::class)->execute($this->lot->fresh(), [
        'actual_sell_price_per_unit' => 0, 'closing_date' => today()->toDateString(),
    ]);

    corrigerLePointage($this, $pointage, 4)->assertSessionHas('error');

    expect($pointage->fresh()->mortality)->toBe(5);
});

// ── CHANGER LE BÂTIMENT D'UN LOT : DÉMÉNAGEMENT OU CORRECTION ───────────

test('un lot qui a vécu là change de bâtiment : l’ancien entre en vide sanitaire', function () {
    DailyCheck::create(['farm_id' => $this->farm->id, 'batch_id' => $this->lot->id,
        'check_date' => today()->toDateString(), 'mortality' => 0]);
    $nouveau = Building::factory()->create(['name' => 'B-NEUF', 'type' => 'chair', 'capacity' => 1000,
        'status' => Building::STATUS_DISPONIBLE]);

    app(\App\Actions\Batch\UpdateBatch::class)->execute($this->lot->fresh(), ['building_id' => $nouveau->id]);

    expect($this->building->fresh()->status)->toBe(Building::STATUS_DESINFECTION)
        ->and($nouveau->fresh()->status)->toBe(Building::STATUS_OCCUPE);
});

test('un lot sans pointage change de bâtiment : correction de saisie, l’ancien redevient disponible', function () {
    $nouveau = Building::factory()->create(['name' => 'B-NEUF', 'type' => 'chair', 'capacity' => 1000,
        'status' => Building::STATUS_DISPONIBLE]);

    app(\App\Actions\Batch\UpdateBatch::class)->execute($this->lot->fresh(), ['building_id' => $nouveau->id]);

    expect($this->building->fresh()->status)->toBe(Building::STATUS_DISPONIBLE);
});
