<?php

use App\Models\Batch;
use App\Models\HealthIncident;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Déclaration d'anomalie sanitaire INDUSTRIALISÉE (audit UX 2026-07-03).
 *
 * - Symptômes en checklist standardisée (symptom_tags[]) composés côté
 *   serveur avec les détails libres — compat conservée : `symptoms` seul.
 * - Option « quarantaine immédiate » à la déclaration : gèle le lot dès le
 *   constat (réservée elevage.M — un opérateur C ne peut pas la poser).
 */

beforeEach(function () {
    $this->setUpRbac();

    $this->batch = Batch::factory()->create([
        'initial_quantity' => 100,
        'current_quantity' => 100,
        'qty_alive'        => 100,
    ]);
});

test('la page santé rend la modale industrialisée (checklist + option quarantaine)', function () {
    $this->actingAs($this->managerUser)
        ->get(route('health.index'))
        ->assertOk()
        ->assertSee('Prostration / abattement')
        ->assertSee('Placer le lot en quarantaine immédiatement');
});

test('déclaration par checklist : les tags sont composés avec les détails libres', function () {
    $this->actingAs($this->operatorUser)
        ->post(route('health.incidents.store'), [
            'batch_id'        => $this->batch->id,
            'mortality_count' => 2,
            'severity'        => 'modere',
            'symptom_tags'    => ['Prostration / abattement', 'Diarrhée blanche'],
            'symptoms'        => 'Surtout côté est du bâtiment',
        ])
        ->assertSessionHas('success');

    $incident = HealthIncident::latest('id')->first();
    expect($incident->symptoms)
        ->toBe('Prostration / abattement, Diarrhée blanche — Surtout côté est du bâtiment');
    expect($incident->status)->toBe(HealthIncident::STATUS_PENDING);
});

test('compat : une déclaration avec symptômes texte seul reste acceptée', function () {
    $this->actingAs($this->operatorUser)
        ->post(route('health.incidents.store'), [
            'batch_id'        => $this->batch->id,
            'mortality_count' => 1,
            'symptoms'        => 'Râles respiratoires généralisés',
        ])
        ->assertSessionHas('success');

    expect(HealthIncident::latest('id')->first()->symptoms)
        ->toBe('Râles respiratoires généralisés');
});

test('déclaration sans aucun symptôme (ni tags ni texte) : refusée', function () {
    $this->actingAs($this->operatorUser)
        ->from(route('health.index'))
        ->post(route('health.incidents.store'), [
            'batch_id'        => $this->batch->id,
            'mortality_count' => 1,
        ])
        ->assertSessionHasErrors();

    expect(HealthIncident::count())->toBe(0);
});

test('quarantaine immédiate par un manager (M) : le lot est gelé dès la déclaration', function () {
    $this->actingAs($this->managerUser)
        ->post(route('health.incidents.store'), [
            'batch_id'        => $this->batch->id,
            'mortality_count' => 8,
            'severity'        => 'critique',
            'symptom_tags'    => ['Mortalité brutale'],
            'quarantine_now'  => 1,
        ])
        ->assertSessionHas('success');

    $incident = HealthIncident::latest('id')->first();
    expect($incident->is_quarantined)->toBeTrue();
    expect($incident->quarantine_started_at)->not->toBeNull();
    expect($this->batch->fresh()->isQuarantined())->toBeTrue();
});

test('quarantaine immédiate par un opérateur (C sans M) : incident créé mais lot NON gelé', function () {
    $this->actingAs($this->operatorUser)
        ->post(route('health.incidents.store'), [
            'batch_id'        => $this->batch->id,
            'mortality_count' => 8,
            'symptom_tags'    => ['Mortalité brutale'],
            'quarantine_now'  => 1, // requête forgée : la case n'est pas montrée aux C
        ])
        ->assertSessionHas('success');

    $incident = HealthIncident::latest('id')->first();
    expect($incident->is_quarantined)->toBeFalse();
    expect($this->batch->fresh()->isQuarantined())->toBeFalse();
});
