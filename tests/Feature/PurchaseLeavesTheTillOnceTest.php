<?php

use App\Models\Expense;
use App\Models\FuelPurchase;
use App\Models\Provider;
use App\Models\SupplierInvoice;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN ACHAT PAYÉ UNE FOIS SORTAIT DEUX FOIS DE LA CAISSE.
 *
 * L'achat fournisseur pose, à la validation, une DÉPENSE MIROIR — la pièce qui
 * porte le coût au compte de résultat. Le commentaire du modèle l'annonce :
 * « même convention que FuelPurchase ».
 *
 * Mais l'observateur des dépenses fait DEUX choses d'un coup : il compte au P&L
 * et il sort l'argent de la trésorerie. Chez FuelPurchase c'est juste — il n'y a
 * pas d'autre pièce, l'achat de gasoil est payé sur-le-champ et la dépense EST
 * le décaissement.
 *
 * L'achat fournisseur, lui, a une seconde pièce : le RÈGLEMENT, qui poste son
 * propre décaissement. La convention a été reprise dans un décor où elle ne
 * tenait plus.
 *
 * ─── MESURÉ ───
 *
 * Achat de 5 000 000, validé puis réglé intégralement. Une seule sortie de
 * billets dans la vie réelle :
 *
 *     sorties enregistrées ........ 10 000 000
 *     réellement décaissé .......... 5 000 000
 *
 * ─── PIRE ENCORE POUR UN ACHAT À CRÉDIT ───
 *
 * La première sortie tombe à la VALIDATION, quand rien n'a été payé. Un achat
 * à 60 jours vide la caisse le jour de sa saisie. Le solde de trésorerie est
 * faux, et il l'est dans le sens qui fait peur : trop bas.
 *
 * ─── LA CORRECTION ───
 *
 * La dépense miroir d'un achat fournisseur reste au P&L et NE BOUGE PLUS
 * D'ARGENT : le règlement s'en charge. Un achat sans règlement est une dette,
 * pas un décaissement — c'est toute la raison d'être du module.
 *
 * Le test du bas garde FuelPurchase intact : là, la dépense doit continuer à
 * sortir l'argent, faute de quoi le carburant ne se verrait plus en caisse.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->caisse = TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Caisse principale',
        'type' => 'caisse', 'current_balance' => 20_000_000, 'is_active' => true,
    ]);

    $this->fournisseur = Provider::create([
        'name' => 'Avipro Guinée SARL', 'type' => 'Aliment',
        'phone' => '620111222', 'status' => 'Actif',
    ]);

    $this->achat = SupplierInvoice::create([
        'farm_id' => $this->farm->id, 'provider_id' => $this->fournisseur->id,
        'reference' => 'ACH-5001', 'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(60)->toDateString(),
        'category' => 'aliment', 'label' => 'Aliment ponte 40 sacs',
        'total_amount' => 5_000_000, 'status' => 'brouillon',
        'posts_expense' => true, 'user_id' => $this->adminUser->id,
    ]);
});

/** Sorties de trésorerie, tous chemins confondus. */
function sortiesTotales(): float
{
    return (float) TreasuryTransaction::where('direction', 'out')->sum('amount');
}

test('un achat validé PUIS réglé ne sort qu’une fois de la caisse', function () {
    /*
     * LE défaut, chiffré : 10 000 000 sortis pour un achat de 5 000 000.
     */
    $this->put(route('purchases.validate', $this->achat))->assertRedirect();

    $this->post(route('purchases.pay', $this->achat), [
        'amount' => 5_000_000, 'method' => 'especes',
        'payment_date' => now()->toDateString(),
    ])->assertRedirect();

    expect(sortiesTotales())->toBe(5000000.0);
});

test('un achat à CRÉDIT ne vide pas la caisse le jour de sa saisie', function () {
    /*
     * Le cas le plus courant, et le plus trompeur : l'achat est validé, rien
     * n'est payé. C'est une DETTE. La caisse ne doit pas bouger.
     */
    $this->put(route('purchases.validate', $this->achat))->assertRedirect();

    expect(sortiesTotales())->toBe(0.0)
        ->and((float) $this->caisse->fresh()->current_balance)->toBe(20000000.0);
});

test('le coût reste bien au compte de résultat', function () {
    /*
     * La borne qui compte : on retire le mouvement d'ARGENT, pas la CHARGE. La
     * dépense miroir doit rester, valide, sans quoi l'achat disparaîtrait du
     * compte de résultat — l'inverse exact du défaut d'origine.
     */
    $this->put(route('purchases.validate', $this->achat))->assertRedirect();

    $depense = $this->achat->fresh()->expense;

    expect($depense)->not->toBeNull()
        ->and($depense->status)->toBe('valide')
        ->and((float) $depense->amount)->toBe(5000000.0);
});

test('un règlement PARTIEL ne sort que ce qui est versé', function () {
    // La trésorerie suit l'argent, pas la facture.
    $this->put(route('purchases.validate', $this->achat))->assertRedirect();

    $this->post(route('purchases.pay', $this->achat), [
        'amount' => 2_000_000, 'method' => 'especes',
        'payment_date' => now()->toDateString(),
    ])->assertRedirect();

    expect(sortiesTotales())->toBe(2000000.0);
});

test('une dépense ORDINAIRE sort toujours de la caisse', function () {
    /*
     * La borne principale. La très grande majorité des dépenses n'ont pas
     * d'achat fournisseur derrière elles : le gasoil du jour, une réparation,
     * un transport. Pour celles-là, la dépense EST le décaissement, et elle
     * doit le rester.
     */
    Expense::create([
        'farm_id' => $this->farm->id, 'reference' => 'DEP-777',
        'category' => 'carburant', 'label' => 'Gasoil du jour',
        'amount' => 300_000, 'expense_date' => now()->toDateString(),
        'payment_method' => 'especes', 'status' => 'valide',
        'user_id' => $this->adminUser->id,
    ]);

    expect(sortiesTotales())->toBe(300000.0);
});

test('la reprise rend aux comptes les décaissements déjà posés en double', function () {
    /*
     * L'HISTORIQUE, ET IL CHANGE DES SOLDES VISIBLES.
     *
     * Corriger le code n'annule pas les sorties déjà enregistrées : la caisse
     * reste sous-estimée du total des décaissements posés par les dépenses
     * miroir. La migration les reprend ; on mesure ici la même action.
     *
     * On reconstitue l'état hérité — une écriture de sortie dont la source est
     * la dépense miroir — puisque le code ne la produit plus.
     */
    $this->put(route('purchases.validate', $this->achat))->assertRedirect();

    $depense = $this->achat->fresh()->expense;

    TreasuryTransaction::create([
        'farm_id' => $this->farm->id, 'treasury_account_id' => $this->caisse->id,
        'direction' => 'out', 'amount' => 5_000_000, 'category' => 'depense',
        'description' => 'Dépense Aliment ponte 40 sacs',
        'transaction_date' => now()->toDateString(),
        'source_type' => $depense->getMorphClass(), 'source_id' => $depense->id,
    ]);
    $this->caisse->decrement('current_balance', 5_000_000);

    $bilan = app(\App\Actions\Treasury\ReverseMirrorExpensePostings::class)->execute();

    expect($bilan['count'])->toBe(1)
        ->and($bilan['restored'])->toBe(5000000.0)
        ->and((float) $this->caisse->fresh()->current_balance)->toBe(20000000.0)
        ->and(sortiesTotales())->toBe(0.0);

    // Rejouable : une seconde exécution ne trouve plus rien.
    expect(app(\App\Actions\Treasury\ReverseMirrorExpensePostings::class)->execute()['count'])->toBe(0);
});

test('la reprise ne touche PAS aux dépenses ordinaires', function () {
    /*
     * La borne de la réparation. Le gasoil du jour a bien quitté la caisse : sa
     * sortie doit survivre à la reprise, sinon on répare un solde en en cassant
     * un autre.
     */
    Expense::create([
        'farm_id' => $this->farm->id, 'reference' => 'DEP-778',
        'category' => 'carburant', 'label' => 'Gasoil du jour',
        'amount' => 300_000, 'expense_date' => now()->toDateString(),
        'payment_method' => 'especes', 'status' => 'valide',
        'user_id' => $this->adminUser->id,
    ]);

    app(\App\Actions\Treasury\ReverseMirrorExpensePostings::class)->execute();

    expect(sortiesTotales())->toBe(300000.0);
});

test('l’achat de CARBURANT garde son décaissement', function () {
    /*
     * L'autre porteur de dépense miroir. Il n'a PAS de pièce de règlement : sa
     * dépense est le seul témoin du décaissement. Aligner les deux « pour faire
     * pareil » ferait disparaître le carburant de la trésorerie.
     */
    $groupe = \App\Models\EnergySource::create([
        'farm_id' => $this->farm->id, 'name' => 'Groupe électrogène',
        'type' => 'generateur', 'fuel_type' => 'diesel',
        'status' => 'Actif', 'is_active' => true,
    ]);

    $achatCarburant = FuelPurchase::create([
        'farm_id' => $this->farm->id, 'energy_source_id' => $groupe->id,
        'purchase_date' => now()->toDateString(),
        'quantity_liters' => 200, 'unit_price' => 15_000, 'total_cost' => 3_000_000,
        'supplier' => 'Total Kindia', 'user_id' => $this->adminUser->id,
    ]);

    $achatCarburant->syncLedgerExpense();

    expect(sortiesTotales())->toBe(3000000.0);
});
