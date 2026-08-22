<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\EggProduction;
use App\Models\FeedPurchase;
use App\Models\HealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * METTRE UN LOT À LA CORBEILLE DÉTRUISAIT SON HISTOIRE POUR DE BON.
 *
 * `BatchObserver::deleting()` cascadait `delete()` vers quatre relations. Trois
 * d'entre elles n'utilisaient PAS la suppression douce : sur celles-là,
 * `delete()` détruit définitivement.
 *
 * Mettre un lot à la corbeille effaçait donc pour toujours ses interventions
 * sanitaires, ses collectes d'œufs et ses achats d'aliment — pendant que le lot,
 * lui, s'affichait comme récupérable. Et rien ne s'y opposait :
 * `BatchController::destroy()` ne vérifie que le droit, sans garde de
 * dépendances.
 *
 * Deux de ces tables portaient pourtant `deleted_at` : la suppression douce
 * avait été prévue au schéma, jamais câblée au modèle.
 *
 * ─── ET LA RESTAURATION NE RESTAURAIT RIEN ───
 *
 * `restoring()` conditionnait ses deux appels à
 * `method_exists($batch->dailyChecks(), 'withTrashed')` — une vérification qui
 * rend TOUJOURS false : `withTrashed` n'est pas déclarée sur `HasMany`, elle est
 * atteinte par `__call`. Le garde « anti-crash » désactivait donc en permanence
 * la restauration qu'il prétendait protéger.
 *
 * Un lot sorti de la corbeille revenait vide de son histoire.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'building_id'      => $this->building->id,
        'initial_quantity' => 1000,
        'current_quantity' => 1000,
        'status'           => 'Actif',
    ]);

    DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->lot->id,
        'check_date' => today()->subDay()->toDateString(),
        'mortality' => 40, 'user_id' => $this->adminUser->id,
    ]);

    HealthCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->lot->id,
        'type' => 'Vaccin', 'product_name' => 'Newcastle',
        'mode_administration' => 'Eau de boisson',
        'intervention_date' => today()->subDays(3)->toDateString(),
        'cost' => 150_000, 'user_id' => $this->adminUser->id,
    ]);

    FeedPurchase::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->lot->id,
        'purchase_date' => today()->subDays(5)->toDateString(),
        'feed_type' => 'Ponte', 'quantity' => 500,
        'unit_price' => 4000, 'total_price' => 2_000_000,
        'user_id' => $this->adminUser->id,
    ]);

    EggProduction::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->lot->id,
        'production_date' => today()->subDay()->toDateString(),
        'total_eggs_collected' => 800, 'user_id' => $this->adminUser->id,
    ]);
});

test('l’ACHAT D’ALIMENT survit à la mise en corbeille', function () {
    /*
     * Une pièce comptable. Elle était DÉTRUITE — `feed_purchases` n'a même pas
     * de colonne `deleted_at`, la suppression était donc définitive.
     */
    $this->lot->delete();

    expect(FeedPurchase::where('batch_id', $this->lot->id)->count())->toBe(1);
});

test('la COLLECTE D’ŒUFS y survit aussi', function () {
    // Même raison. Et on ne peut pas la masquer par suppression douce : la table
    // porte un UNIQUE (batch_id, production_date) qu'une ligne masquée
    // bloquerait à la ressaisie.
    $this->lot->delete();

    expect(EggProduction::where('batch_id', $this->lot->id)->count())->toBe(1);
});

test('le REGISTRE SANITAIRE est masqué, mais récupérable', function () {
    /*
     * Pièce réglementaire : elle doit disparaître des écrans avec le lot, sans
     * être détruite. Le trait vient d'être posé sur un `deleted_at` qui existait
     * déjà au schéma.
     */
    $this->lot->delete();

    expect(HealthCheck::where('batch_id', $this->lot->id)->count())->toBe(0)
        ->and(HealthCheck::withTrashed()->where('batch_id', $this->lot->id)->count())->toBe(1);
});

test('SORTIR le lot de la corbeille lui rend son histoire', function () {
    /*
     * LA borne. Le garde `method_exists` rendait toujours false : rien n'était
     * restauré, et le lot revenait vide.
     */
    $this->lot->delete();
    Batch::withTrashed()->find($this->lot->id)->restore();

    expect(DailyCheck::where('batch_id', $this->lot->id)->count())->toBe(1)
        ->and(HealthCheck::where('batch_id', $this->lot->id)->count())->toBe(1);
});

test('l’EFFECTIF fait l’aller-retour sans dériver', function () {
    /*
     * Ce qui marchait déjà, et qu'il ne faut pas casser : les cascades étant en
     * masse, aucun événement par modèle ne se déclenche, donc la mortalité n'est
     * ni restituée à la suppression ni ré-appliquée à la restauration.
     */
    $avant = (int) $this->lot->fresh()->current_quantity;

    $this->lot->delete();
    Batch::withTrashed()->find($this->lot->id)->restore();

    expect((int) Batch::find($this->lot->id)->current_quantity)->toBe($avant)
        ->and($avant)->toBe(960);
});

test('le garde `method_exists` sur une relation ne revient pas', function () {
    /*
     * Il ne pouvait pas fonctionner : `withTrashed` n'est pas DÉCLARÉE sur
     * `HasMany`, elle est atteinte par `__call`. Toute vérification de ce genre
     * sur une relation est fausse par construction.
     */
    // On lit le CODE, pas la prose : le commentaire ci-dessus cite le garde
    // pour l'expliquer, et une recherche naïve se déclencherait dessus.
    $lignes = file(base_path('app/Observers/BatchObserver.php'));

    $code = collect($lignes)
        ->map(fn ($l) => trim($l))
        ->reject(fn ($l) => $l === '' || str_starts_with($l, '*') || str_starts_with($l, '/*')
            || str_starts_with($l, '//') || str_starts_with($l, '*/'))
        ->implode("\n");

    expect(str_contains($code, 'method_exists($batch->'))
        ->toBeFalse('Un method_exists sur une relation rend toujours false.');
});
