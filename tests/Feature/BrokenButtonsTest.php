<?php

use App\Models\Batch;
use App\Models\FeedPurchase;
use App\Models\Provider;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DEUX BOUTONS QUI TOMBAIENT EN ERREUR.
 *
 * Vérifier que la méthode existe ne suffit pas : ce qui compte est que l'écran
 * réponde et que son contenu soit exploitable. On exerce donc les deux chemins
 * de bout en bout, depuis le clic tel que la vue le fabrique.
 */

beforeEach(function () {
    $this->setUpRbac();
});

test('le bouton Export des stocks renvoie un CSV exploitable', function () {
    Stock::factory()->create([
        'farm_id'          => $this->farm->id,
        'category'         => Stock::CAT_OEUFS,
        'item_name'        => 'Oeufs calibre L',
        'unit'             => 'Unité',
        'current_quantity' => 120,
        'alert_threshold'  => 200,     // sous le seuil : la colonne doit le dire
        'unit_price'       => 1500,
    ]);

    $response = $this->actingAs($this->adminUser)
        ->get(route('stocks.export', ['category' => Stock::CAT_OEUFS]))
        ->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('Oeufs calibre L')
        // Séparateur point-virgule et BOM : c'est ce qui permet à Excel d'ouvrir
        // le fichier avec ses accents, sans assistant d'importation.
        ->and($csv)->toContain(';')
        ->and(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        // Valeur = quantité × prix unitaire, calculée ici plutôt que dans le
        // tableur de l'utilisateur.
        ->and($csv)->toContain('180000')
        ->and($csv)->toContain('OUI');
});

test('l’export respecte la catégorie demandée', function () {
    Stock::factory()->create([
        'farm_id' => $this->farm->id, 'category' => Stock::CAT_OEUFS,
        'item_name' => 'Article oeufs', 'current_quantity' => 10, 'unit_price' => 100,
    ]);
    Stock::factory()->create([
        'farm_id' => $this->farm->id, 'category' => Stock::CAT_CONSO,
        'item_name' => 'Article consommable', 'current_quantity' => 10, 'unit_price' => 100,
    ]);

    $csv = $this->actingAs($this->adminUser)
        ->get(route('stocks.export', ['category' => Stock::CAT_CONSO]))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Article consommable')
        ->and($csv)->not->toContain('Article oeufs');
});

test('le crayon de rectification d’un achat d’aliment ouvre le formulaire', function () {
    Provider::factory()->create(['name' => 'Provenderie Kindia']);

    $batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    $purchase = FeedPurchase::create([
        'farm_id'    => $this->farm->id,
        'batch_id'   => $batch->id,
        'feed_type'  => 'Chair Démarrage',
        'quantity'   => 20,
        'unit_price' => 25000,
        'unit'       => 'Sac',
        'total_price' => 500000,
        'purchase_date' => now()->toDateString(),
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('feed-purchases.edit', $purchase->id))
        ->assertOk()
        ->assertSee('Modifier Ravitaillement', false)
        // Le formulaire doit poster vers `update`, qui lui existait déjà.
        ->assertSee(route('feed-purchases.update', $purchase->id), false)
        // La liste des fournisseurs vient de la même source que la création.
        ->assertSee('Provenderie Kindia', false);
});

test('archiver une bande retire ses achats — la rectification n’a plus de cible', function () {
    // Vérification du raisonnement, pas d'un cas de bord inventé : la vue de
    // rectification s'appuie sur `$batch`. On a d'abord cru qu'un achat pouvait
    // se retrouver sans lot ; c'est faux. `batch_id` est NOT NULL, et archiver un
    // lot supprime ses achats (BatchObserver). L'écran répond donc 404 — l'achat
    // n'existe plus — et non une erreur de rendu.
    //
    // Le garde-fou du contrôleur reste en place : trois lignes qui transforment
    // une éventuelle page d'erreur en message, si un chemin futur créait cet état.
    $batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    $purchase = FeedPurchase::create([
        'farm_id'    => $this->farm->id,
        'batch_id'   => $batch->id,
        'feed_type'  => 'Chair Démarrage',
        'quantity'   => 20,
        'unit_price' => 25000,
        'unit'       => 'Sac',
        'total_price' => 500000,
        'purchase_date' => now()->toDateString(),
    ]);

    $batch->delete();

    $this->actingAs($this->adminUser)
        ->get(route('feed-purchases.edit', $purchase->id))
        ->assertNotFound();
});
