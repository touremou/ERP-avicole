<?php

use App\Models\Provider;
use App\Models\RawMaterial;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\TreasuryTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'ÉCRAN DISAIT « RÉCEPTION » ET « MONTANT FACTURÉ ». IL NE FACTURAIT RIEN.
 *
 * Entrer du maïs en magasin demandait un fournisseur et un montant facturé, puis
 * annonçait « Stock approvisionné ». Aucune facture fournisseur, aucun règlement,
 * aucune écriture de trésorerie n'en sortait. Le fournisseur choisi n'était même
 * pas enregistré : le champ était validé, jamais lu.
 *
 * ─── LA CONVENTION, TRANCHÉE ───
 *
 * Ce geste est une CORRECTION D'INVENTAIRE. Le prix ne sert qu'à réajuster le
 * coût moyen pondéré ; l'achat se saisit séparément au module Dépenses.
 *
 * C'est une décision d'exploitation valable — mais rien, dans le code ni à
 * l'écran, ne la disait. Un libellé qui promet une facture finit par être cru :
 * la dépense n'est jamais saisie, et le compte de résultat ignore l'achat tandis
 * que le stock, lui, est bien monté.
 *
 * D'où ce fichier. Il ne corrige pas un calcul : il EMPÊCHE la convention de
 * dériver. Si quelqu'un ajoute un jour une facture ou une écriture de caisse sur
 * ce chemin, les deux premiers tests tombent et la question se repose — au lieu
 * que l'achat soit compté deux fois, une fois ici et une fois aux Dépenses.
 *
 * ─── ET LE CHEMIN QUI, LUI, PORTE L'ARGENT ───
 *
 * `CreateFeedPurchase` pose bien facture et règlement. Il porte sur l'ALIMENT
 * consommé par les bandes, pas sur les ingrédients de la provenderie : deux
 * objets, deux chemins. Le dernier test tient cette frontière.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->article = Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'Maïs concassé',
        'category' => 'matiere_premiere', 'unit' => 'KG',
        'current_quantity' => 0, 'alert_threshold' => 100,
    ]);

    $this->mais = RawMaterial::create([
        'farm_id' => $this->farm->id, 'name' => 'Maïs concassé', 'unit' => 'kg',
        'stock_qty' => 1000, 'unit_cost' => 5000, 'alert_threshold' => 100,
        'stock_id' => $this->article->id, 'is_active' => true,
    ]);
});

/** Entrée d'inventaire depuis l'écran provenderie. */
function entrer(RawMaterial $m, array $payload = [])
{
    return test()->put(route('raw-materials.update-stock', $m->id), array_merge([
        'added_qty'      => 500,
        'purchase_price' => 3_000_000,
        'reason'         => 'Réception commande du 12',
    ], $payload));
}

test('l’entrée ne crée AUCUNE trace financière', function () {
    /*
     * La convention, énoncée en trois absences. Le fournisseur est renseigné —
     * c'est justement le cas qui ressemblait le plus à un achat.
     */
    $fournisseur = Provider::create([
        'name' => 'Coopérative de Kindia', 'type' => 'Matière première',
        'phone' => '620000000', 'status' => 'Actif',
    ]);

    entrer($this->mais, ['provider_id' => $fournisseur->id])->assertRedirect();

    expect(SupplierInvoice::count())->toBe(0)
        ->and(SupplierPayment::count())->toBe(0)
        ->and(TreasuryTransaction::count())->toBe(0);
});

test('le CMP est recalculé, et lui seul porte le prix', function () {
    // 1 000 kg à 5 000 = 5 000 000, plus 500 kg pour 3 000 000.
    // (5 000 000 + 3 000 000) ÷ 1 500 = 5 333,33.
    entrer($this->mais)->assertRedirect();

    $mais = $this->mais->fresh();

    expect((float) $mais->stock_qty)->toBe(1500.0)
        ->and((float) $mais->unit_cost)->toBe(5333.33);
});

test('le mouvement de stock dit « correction », et porte le motif', function () {
    /*
     * Le journal du magasin est ce qu'on relit à l'inventaire. Il annonçait
     * « Réception … » — le mot même qui égarait. Il porte maintenant le motif,
     * comme la sortie symétrique le fait depuis toujours.
     */
    entrer($this->mais, ['reason' => 'Écart constaté au comptage'])->assertRedirect();

    $mouvement = StockMovement::where('stock_id', $this->article->id)->first();

    expect($mouvement->type)->toBe('in')
        ->and((float) $mouvement->quantity)->toBe(500.0)
        ->and($mouvement->notes)->toContain('Correction')
        ->and($mouvement->notes)->toContain('Écart constaté au comptage')
        ->and($mouvement->notes)->not->toContain('Réception');
});

test('sans motif, l’entrée est refusée', function () {
    // La symétrie avec la sortie : celle-ci exigeait un motif, l'entrée non.
    entrer($this->mais, ['reason' => ''])->assertSessionHasErrors('reason');

    expect((float) $this->mais->fresh()->stock_qty)->toBe(1000.0);
});

test('le fournisseur choisi n’est plus perdu', function () {
    /*
     * Il était validé puis jeté. Faute de facture à porter, il se lit dans le
     * journal du magasin — c'est là qu'on cherche d'où vient un lot.
     */
    $fournisseur = Provider::create([
        'name' => 'Coopérative de Kindia', 'type' => 'Matière première',
        'phone' => '620000000', 'status' => 'Actif',
    ]);

    entrer($this->mais, ['provider_id' => $fournisseur->id])->assertRedirect();

    expect(StockMovement::first()->notes)->toContain('Coopérative de Kindia');
});

test('deux entrées successives cumulent quantité ET valeur', function () {
    /*
     * 1 000 + 500 + 500 = 2 000 kg, (5 000 000 + 3 000 000 + 3 000 000) ÷ 2 000.
     *
     * CE QUE CE TEST NE MESURE PAS : le verrou. Le CMP est un lire-puis-écrire,
     * et `lockForUpdate` a été ajouté pour que deux saisies SIMULTANÉES ne
     * repartent pas toutes deux de l'ancien état — la seconde écrasant alors la
     * quantité et la valeur de la première. Une exécution séquentielle relit de
     * toute façon l'état frais : elle passe avec ou sans verrou.
     *
     * Reproduire la concurrence ici demanderait deux connexions et un ordre
     * d'exécution imposé — un test qui mesurerait surtout le moteur de base.
     * On garde donc l'arithmétique du cumul, en disant ce qui n'est pas couvert
     * plutôt qu'en laissant croire à une couverture qu'on n'a pas.
     */
    entrer($this->mais)->assertRedirect();
    entrer($this->mais->fresh())->assertRedirect();

    $mais = $this->mais->fresh();

    expect((float) $mais->stock_qty)->toBe(2000.0)
        ->and((float) $mais->unit_cost)->toBe(5500.0);
});

test('une sortie ne peut pas faire passer le stock sous zéro', function () {
    /*
     * Le plafond tient : on ne sort pas 1 500 kg d'un magasin qui en contient
     * 1 000.
     *
     * Ici encore, c'est la règle de validation qui parle — la relecture sous
     * verrou ajoutée dans la transaction ne sert QUE le cas concurrent, où deux
     * sorties franchissent toutes deux un plafond mesuré avant la transaction.
     * Ce test garde la borne visible ; il ne prétend pas couvrir le verrou.
     */
    $this->put(route('raw-materials.remove-stock', $this->mais->id), [
        'qty' => 1500, 'reason' => 'Perte magasin',
    ]);

    expect((float) $this->mais->fresh()->stock_qty)->toBe(1000.0);
});

test('l’achat d’ALIMENT, lui, garde sa facture', function () {
    /*
     * La frontière. Ce test ne protège pas le geste corrigé : il protège
     * l'autre — celui qui porte réellement l'argent. Aligner les deux « pour
     * faire pareil » supprimerait la seule trace fournisseur du système.
     */
    $lot = \App\Models\Batch::factory()->create(['farm_id' => $this->farm->id]);

    app(\App\Actions\FeedPurchase\CreateFeedPurchase::class)->execute([
        'farm_id'       => $this->farm->id,
        'batch_id'      => $lot->id,
        'feed_type'     => 'Démarrage',
        'quantity'      => 10,
        'unit'          => 'Sac',
        'unit_price'    => 2_500_000,
        'purchase_date' => now()->toDateString(),
        'supplier'      => 'Provenderie du Fouta',
        'payment_mode'  => 'comptant',
    ]);

    expect(SupplierInvoice::count())->toBe(1)
        ->and(SupplierPayment::count())->toBe(1);
});
