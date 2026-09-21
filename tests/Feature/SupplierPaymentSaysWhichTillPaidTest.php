<?php

use App\Actions\FeedPurchase\CreateFeedPurchase;
use App\Models\Batch;
use App\Models\Provider;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\TreasuryAccount;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE RÈGLEMENT FOURNISSEUR NE POUVAIT PAS DIRE DE QUELLE CAISSE IL SORTAIT.
 *
 * `supplier_payments.treasury_account_id` existe depuis la migration
 * 2026_06_28_000200, et `TreasuryPostingService::resolveAccount()` le lit EN
 * PRIORITÉ sur le mode de paiement :
 *
 *     if ($explicitId && ($acc = TreasuryAccount::find($explicitId))) {
 *         return $acc;
 *     }
 *
 * Mais AUCUN des chemins qui créent un règlement fournisseur ne le remplissait.
 * Colonne lue, jamais écrite — un lecteur sans écrivain.
 *
 * ─── CE QUI REND CE TROU PARTICULIER ───
 *
 * Les trois autres portes de trésorerie le proposent DÉJÀ. `CreateExpense`,
 * `RecordPayment` et `CreateSale` acceptent toutes un compte explicite, et
 * l'écran de dépense offre le sélecteur « Auto (selon le mode) ». Le règlement
 * fournisseur était le seul des quatre à ne pas suivre la règle que les trois
 * autres suivaient.
 *
 * ─── CE QUE ÇA COÛTE ───
 *
 * Tant qu'une ferme n'a qu'une caisse, la résolution par le mode suffit et rien
 * ne se voit. Avec deux — une caisse principale et une caisse boutique, le cas
 * qui a motivé la colonne — le décaissement tombe TOUJOURS sur la première
 * caisse active :
 *
 *     $byType = static::active()->where('type', $type)->orderBy('id')->first();
 *
 * Les deux soldes sont alors faux en sens inverses, du montant réglé, et aucun
 * écran ne permet de le dire. Le rapprochement physique d'une des deux caisses
 * ne tombera jamais juste.
 *
 * ─── LA RÈGLE POSÉE ───
 *
 * Les deux chemins qui règlent un fournisseur demandent le compte, exactement
 * comme l'écran de dépense : facultatif, « Auto » gardant la résolution par le
 * mode, et le sélecteur ne s'affichant que si la ferme a plus d'un compte —
 * inutile de poser une question dont il n'existe qu'une réponse.
 *
 * Et l'historique des règlements l'AFFICHE : désigner un compte sans pouvoir le
 * vérifier nulle part ne vaudrait pas mieux que ne pas le désigner.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    // Deux caisses : le cas qui a motivé la colonne.
    $this->principale = TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Caisse principale', 'type' => 'caisse',
        'opening_balance' => 5_000_000, 'current_balance' => 5_000_000, 'is_active' => true,
    ]);
    $this->boutique = TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Caisse boutique', 'type' => 'caisse',
        'opening_balance' => 2_000_000, 'current_balance' => 2_000_000, 'is_active' => true,
    ]);
});

/** Un achat fournisseur validé, prêt à régler. */
function achatARegler(int $farmId, int $userId, float $montant = 400_000): SupplierInvoice
{
    $provider = Provider::create([
        'name' => 'Avipro', 'type' => 'Aliment', 'phone' => '620000000', 'status' => 'Actif',
    ]);

    return SupplierInvoice::create([
        'farm_id' => $farmId, 'provider_id' => $provider->id,
        'reference' => 'ACH-' . uniqid(), 'invoice_date' => now()->toDateString(),
        'category' => 'aliment', 'label' => 'Maïs', 'total_amount' => $montant,
        'status' => 'valide', 'posts_expense' => false, 'user_id' => $userId,
    ]);
}

test('régler depuis la caisse BOUTIQUE débite la boutique, pas la principale', function () {
    /*
     * LE défaut : quel que soit le compte réel, la sortie tombait sur la
     * première caisse active. Ici, la principale — qui n'avait rien payé.
     */
    $achat = achatARegler($this->farm->id, $this->adminUser->id);

    $this->post(route('purchases.pay', $achat), [
        'amount' => 400_000, 'method' => 'especes',
        'payment_date' => now()->toDateString(),
        'treasury_account_id' => $this->boutique->id,
    ]);

    expect((float) $this->boutique->fresh()->current_balance)->toBe(2_000_000.0 - 400_000.0)
        ->and((float) $this->principale->fresh()->current_balance)->toBe(5_000_000.0);
});

test('« Auto » garde la résolution par le mode — non-régression', function () {
    /*
     * LA borne : sans compte désigné, rien ne change. C'est le cas de très loin
     * le plus courant — une seule caisse — et il ne doit rien coûter.
     */
    $achat = achatARegler($this->farm->id, $this->adminUser->id);

    $this->post(route('purchases.pay', $achat), [
        'amount' => 400_000, 'method' => 'especes',
        'payment_date' => now()->toDateString(),
        'treasury_account_id' => '',
    ]);

    expect((float) $this->principale->fresh()->current_balance)->toBe(5_000_000.0 - 400_000.0)
        ->and(SupplierPayment::sole()->treasury_account_id)->toBeNull();
});

test('un compte inexistant est refusé', function () {
    // La borne de la validation : on ne rattache pas un décaissement au vide.
    $achat = achatARegler($this->farm->id, $this->adminUser->id);

    $this->post(route('purchases.pay', $achat), [
        'amount' => 400_000, 'method' => 'especes',
        'payment_date' => now()->toDateString(),
        'treasury_account_id' => 999_999,
    ])->assertSessionHasErrors('treasury_account_id');

    expect(SupplierPayment::count())->toBe(0);
});

test('l’écran propose le choix quand il y a plusieurs caisses', function () {
    // Sans le sélecteur, la colonne resterait un lecteur sans écrivain.
    $achat = achatARegler($this->farm->id, $this->adminUser->id);

    $this->get(route('purchases.show', $achat))
        ->assertOk()
        ->assertSee('name="treasury_account_id"', false)
        ->assertSee('Caisse boutique');
});

test('et ne le pose pas quand il n’y a qu’une caisse', function () {
    /*
     * Une question dont il n'existe qu'une réponse est du bruit : l'écran ne la
     * pose pas, et la résolution par le mode fait le travail.
     */
    $this->boutique->delete();

    $achat = achatARegler($this->farm->id, $this->adminUser->id);

    $this->get(route('purchases.show', $achat))
        ->assertOk()
        ->assertDontSee('name="treasury_account_id"', false);
});

test('l’historique AFFICHE le compte débité', function () {
    /*
     * Désigner un compte sans pouvoir le vérifier nulle part ne vaudrait guère
     * mieux que ne pas le désigner : c'est cette colonne qui permet au bureau de
     * contrôler que la bonne caisse a été mouvementée.
     */
    $achat = achatARegler($this->farm->id, $this->adminUser->id);

    $this->post(route('purchases.pay', $achat), [
        'amount' => 400_000, 'method' => 'especes',
        'payment_date' => now()->toDateString(),
        'treasury_account_id' => $this->boutique->id,
    ]);

    $this->get(route('purchases.show', $achat))->assertOk()->assertSee('Caisse boutique');
});

test('un achat d’aliment comptant sort de la caisse désignée', function () {
    /*
     * L'AUTRE porte qui crée un règlement fournisseur. Le règlement « fait à
     * l'achat » est implicite, mais il sort de l'argent bien réel : il doit
     * pouvoir dire d'où, sinon le trou est simplement déplacé.
     */
    $batch = Batch::factory()->create(['status' => 'Actif']);

    (new CreateFeedPurchase())->execute([
        'batch_id' => $batch->id, 'purchase_date' => now()->toDateString(),
        'feed_type' => 'Maïs', 'quantity' => 10, 'unit_price' => 500_000,
        'supplier' => 'Provende Express', 'unit' => 'Sac',
        'treasury_account_id' => $this->boutique->id,
    ]);

    expect((float) $this->boutique->fresh()->current_balance)->toBe(2_000_000.0 - 500_000.0)
        ->and((float) $this->principale->fresh()->current_balance)->toBe(5_000_000.0);
});

test('et sans compte désigné, il sort de la première comme avant — non-régression', function () {
    $batch = Batch::factory()->create(['status' => 'Actif']);

    (new CreateFeedPurchase())->execute([
        'batch_id' => $batch->id, 'purchase_date' => now()->toDateString(),
        'feed_type' => 'Maïs', 'quantity' => 10, 'unit_price' => 500_000,
        'supplier' => 'Provende Express', 'unit' => 'Sac',
    ]);

    expect((float) $this->principale->fresh()->current_balance)->toBe(5_000_000.0 - 500_000.0)
        ->and((float) $this->boutique->fresh()->current_balance)->toBe(2_000_000.0);
});

test('rectifier un achat conserve la caisse d’origine', function () {
    /*
     * Le lien avec la rectification déjà corrigée : le compte fait partie des
     * faits que le formulaire d'achat ne doit pas réécrire. Maintenant qu'il
     * peut être renseigné, cette règle cesse d'être théorique.
     */
    $batch = Batch::factory()->create(['status' => 'Actif']);

    (new CreateFeedPurchase())->execute([
        'batch_id' => $batch->id, 'purchase_date' => now()->toDateString(),
        'feed_type' => 'Maïs', 'quantity' => 10, 'unit_price' => 500_000,
        'supplier' => 'Provende Express', 'unit' => 'Sac',
        'treasury_account_id' => $this->boutique->id,
    ]);

    (new \App\Actions\FeedPurchase\UpdateFeedPurchase())->execute(
        \App\Models\FeedPurchase::firstOrFail(),
        [
            'feed_type' => 'Maïs', 'quantity' => 10, 'unit_price' => 650_000,
            'supplier' => 'Provende Express', 'purchase_date' => now()->toDateString(),
        ]
    );

    expect(SupplierPayment::sole()->treasury_account_id)->toBe($this->boutique->id)
        ->and((float) $this->boutique->fresh()->current_balance)->toBe(2_000_000.0 - 650_000.0)
        ->and((float) $this->principale->fresh()->current_balance)->toBe(5_000_000.0);
});
