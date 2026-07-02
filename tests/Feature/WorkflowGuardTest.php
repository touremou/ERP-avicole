<?php

use App\Actions\Sale\CreateSale;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Audit 360 §2.1 (W1-W3) — machines à états du cœur financier.
 *
 * Chaque transition ILLÉGALE doit être rejetée CÔTÉ SERVEUR (pas seulement
 * un bouton masqué), l'état doit rester inchangé, et les effets de bord
 * (déstockage, entrée au P&L) ne doivent JAMAIS s'appliquer deux fois.
 * Tests via HTTP : ils traversent routes + Gates + contrôleurs + Actions.
 */

beforeEach(function () {
    $this->setUpRbac();

    // Article stocké ciblé par les ventes (déstockage à la VALIDATION).
    $this->stock = Stock::factory()->create([
        'item_name'        => 'Oeufs calibre L',
        'category'         => 'oeufs',
        'unit'             => 'KG',
        'current_quantity' => 100,
    ]);

    $client = [
        'client_id'  => 'CLI-0001',
        'name'       => 'Client Test',
        'type'       => 'particulier',
        'category'   => 'detaillant',
        'status'     => 'actif',
        'created_at' => now(),
        'updated_at' => now(),
    ];
    if (Schema::hasColumn('clients', 'farm_id')) {
        $client['farm_id'] = $this->farm->id;
    }
    $this->clientId = DB::table('clients')->insertGetId($client);

    // Vente BROUILLON de 10 kg d'oeufs via l'Action partagée (closure liée au
    // TestCase : les propriétés du trait RBAC sont protected).
    $this->draftSale = function (): Sale {
        $this->actingAs($this->managerUser); // CreateSale trace l'auteur (Auth::id)

        return app(CreateSale::class)->execute([
            'client_id' => $this->clientId,
            'sale_date' => now()->toDateString(),
            'type'      => 'bon_livraison',
            'items'     => [[
                'product_type' => 'oeufs',
                'product_name' => 'Oeufs calibre L',
                'quantity'     => 10,
                'unit'         => 'kg',
                'unit_price'   => 15000,
            ]],
        ]);
    };
});

// ─── VENTES ───

test('valider deux fois une vente : le second appel est refusé et ne re-déstocke pas', function () {
    $sale = ($this->draftSale)();

    $this->actingAs($this->managerUser)
        ->put(route('sales.validate', $sale))
        ->assertSessionHas('success');

    expect($sale->fresh()->status)->toBe('valide');
    expect((float) $this->stock->fresh()->current_quantity)->toEqual(90.0);

    $validatedAt = $sale->fresh()->validated_at;

    // Rejeu (double-clic, onglet resté ouvert...) → refus, stock intact.
    $this->actingAs($this->managerUser)
        ->put(route('sales.validate', $sale))
        ->assertSessionHas('error');

    expect($sale->fresh()->status)->toBe('valide');
    expect((float) $this->stock->fresh()->current_quantity)->toEqual(90.0);
    expect($sale->fresh()->validated_at?->toIso8601String())
        ->toBe($validatedAt?->toIso8601String());
});

test('livrer un brouillon est refusé (la validation est un préalable)', function () {
    $sale = ($this->draftSale)();

    $this->actingAs($this->managerUser)
        ->put(route('sales.deliver', $sale))
        ->assertSessionHas('error');

    expect($sale->fresh()->status)->toBe('brouillon');
    expect($sale->fresh()->delivered_at)->toBeNull();
});

test('annuler une vente qui porte un paiement est refusé (rembourser d\'abord)', function () {
    $sale = ($this->draftSale)();

    $this->actingAs($this->managerUser)->put(route('sales.validate', $sale));

    $payment = [
        'sale_id'      => $sale->id,
        'amount'       => 50000,
        'payment_date' => now()->toDateString(),
        'method'       => 'especes',
        'received_by'  => $this->managerUser->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ];
    if (Schema::hasColumn('payments', 'farm_id')) {
        $payment['farm_id'] = $this->farm->id;
    }
    DB::table('payments')->insert($payment);

    $this->actingAs($this->adminUser) // même l'admin est bloqué par la règle métier
        ->put(route('sales.cancel', $sale))
        ->assertSessionHas('error');

    expect($sale->fresh()->status)->toBe('valide');
    expect((float) $this->stock->fresh()->current_quantity)->toEqual(90.0); // pas de restockage sauvage
});

test('annuler une vente validée restocke exactement les quantités déstockées', function () {
    $sale = ($this->draftSale)();

    $this->actingAs($this->managerUser)->put(route('sales.validate', $sale));
    expect((float) $this->stock->fresh()->current_quantity)->toEqual(90.0);

    $this->actingAs($this->adminUser)
        ->put(route('sales.cancel', $sale), ['reason' => 'Erreur de saisie'])
        ->assertSessionHas('success');

    expect($sale->fresh()->status)->toBe('annule');
    expect((float) $this->stock->fresh()->current_quantity)->toEqual(100.0); // retour à l'état initial

    // Ré-annuler → refus (déjà annulée), stock inchangé.
    $this->actingAs($this->adminUser)
        ->put(route('sales.cancel', $sale))
        ->assertSessionHas('error');
    expect((float) $this->stock->fresh()->current_quantity)->toEqual(100.0);
});

// ─── DÉPENSES ───

test('approuver deux fois une dépense : le second appel est refusé (une seule entrée P&L)', function () {
    $expense = Expense::factory()->create([
        'user_id' => $this->managerUser->id,
        'status'  => 'en_attente',
    ]);

    $this->actingAs($this->managerUser)
        ->put(route('expenses.approve', $expense))
        ->assertSessionHas('success');

    $approvedAt = $expense->fresh()->approved_at;
    expect($expense->fresh()->status)->toBe('valide');

    $this->actingAs($this->managerUser)
        ->put(route('expenses.approve', $expense))
        ->assertSessionHas('error');

    expect($expense->fresh()->status)->toBe('valide');
    expect($expense->fresh()->approved_at?->toIso8601String())
        ->toBe($approvedAt?->toIso8601String()); // pas ré-horodatée
});

test('approuver une dépense annulée est refusé (l\'annulation est terminale)', function () {
    $expense = Expense::factory()->create([
        'user_id' => $this->managerUser->id,
        'status'  => 'en_attente',
    ]);

    $this->actingAs($this->managerUser)->put(route('expenses.cancel', $expense));
    expect($expense->fresh()->status)->toBe('annule');

    $this->actingAs($this->managerUser)
        ->put(route('expenses.approve', $expense))
        ->assertSessionHas('error');

    expect($expense->fresh()->status)->toBe('annule');
});

test('le statut d\'une dépense n\'est pas mass-assignable via le formulaire d\'édition', function () {
    $expense = Expense::factory()->create([
        'user_id'  => $this->managerUser->id,
        'status'   => 'en_attente',
        'category' => 'fournitures',
    ]);

    // Un client malveillant poste un champ « status » en plus des champs légitimes.
    $this->actingAs($this->managerUser)->put(route('expenses.update', $expense), [
        'category'       => 'fournitures',
        'label'          => 'Libellé modifié',
        'amount'         => 75000,
        'expense_date'   => now()->toDateString(),
        'payment_method' => 'especes',
        'status'         => 'valide', // ← injection tentée
    ]);

    $fresh = $expense->fresh();
    expect($fresh->label)->toBe('Libellé modifié');   // l'édition légitime passe
    expect($fresh->status)->toBe('en_attente');       // le statut est ignoré
    expect($fresh->approved_at)->toBeNull();
});

// ─── PERMISSIONS (le rôle sans droit est bloqué AVANT la machine à états) ───

test('un opérateur (C) ne peut ni valider ni annuler une vente', function () {
    $sale = ($this->draftSale)();

    $this->actingAs($this->operatorUser)
        ->put(route('sales.validate', $sale))
        ->assertSessionHas('error');

    expect($sale->fresh()->status)->toBe('brouillon');
    expect((float) $this->stock->fresh()->current_quantity)->toEqual(100.0);

    $this->actingAs($this->operatorUser)
        ->put(route('sales.cancel', $sale))
        ->assertSessionHas('error');

    expect($sale->fresh()->status)->toBe('brouillon');
});
