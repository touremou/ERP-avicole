<?php

use App\Models\Provider;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'ARGENT QUI ENTRE ÉTAIT SURVEILLÉ ; CELUI QUI SORT NE L'ÉTAIT PAS.
 *
 * Deux symétries manquaient au règlement fournisseur, et toutes deux existent
 * déjà, écrites et documentées, du côté de l'encaissement client.
 *
 * ─── 1. LE VERROU ───
 *
 * `RecordPayment` porte cette note : « AUDIT C3 (prouvé par drill parallèle) :
 * re-résoudre la vente SOUS verrou — les contrôles faits hors transaction sur
 * une vente lue sans verrou laissaient passer deux encaissements concurrents
 * (120 000 acceptés sur 100 000 dus) ».
 *
 * Le règlement fournisseur avait exactement cette forme : bornes vérifiées sur
 * une facture lue sans verrou, hors transaction. Deux règlements simultanés du
 * même achat passaient donc tous les deux le contrôle du reste dû — et le
 * fournisseur était payé deux fois. Le défaut avait été prouvé et corrigé du
 * côté où l'argent ENTRE ; celui où il SORT était resté en l'état.
 *
 * ─── 2. L'ALERTE ───
 *
 * Chaque encaissement client est annoncé au promoteur (notifyPaymentReceived,
 * décrit sur place comme « pièce centrale de la prévention des malversations
 * sur les paiements »), horaire inhabituel signalé compris. Un règlement
 * fournisseur ne prévenait personne — alors que c'est la voie la plus directe
 * par laquelle l'argent quitte l'exploitation, et que le promoteur vit à
 * l'étranger.
 *
 * L'alerte vit dans l'OBSERVER, pas dans l'écran : trois chemins créent un
 * règlement fournisseur. Les brancher un par un rouvrirait le trou au
 * quatrième — la leçon des ajustements de stock (#215, #229, #230, #234).
 */

/**
 * Corps de TOUTES les alertes émises, concaténé.
 *
 * On ne prend pas « la dernière » : deux notifications écrites dans la même
 * seconde ne s'ordonnent pas de façon déterministe par `created_at`, et l'id
 * est un uuid — le tri ne départage rien. Le test devenait alors vert ou rouge
 * selon l'ordre d'insertion, ce qui ne s'est vu qu'en suite complète.
 *
 * Les accents ET les barres obliques sont échappés par le cast JSON : on
 * redécode avant de chercher (cf. #229, #233).
 */
function corpsDesAlertes(): string
{
    return DB::table('notifications')->pluck('data')
        ->map(fn ($d) => json_encode(json_decode($d, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        ->implode("\n");
}

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->fournisseur = Provider::create([
        'name' => 'Provende SARL', 'type' => 'Aliment', 'phone' => '620000000', 'status' => 'Actif',
    ]);

    $this->achat = SupplierInvoice::create([
        'provider_id'  => $this->fournisseur->id,
        'reference'    => 'ACH-09001',
        'invoice_date' => now()->toDateString(),
        'category'     => 'fournitures',
        'label'        => '20 sacs de provende',
        'total_amount' => 1_000_000,
        'status'       => 'valide',
        'user_id'      => auth()->id(),
    ]);
});

test('un règlement fournisseur ALERTE le promoteur', function () {
    // LE défaut : l'argent sortait sans que personne hors site ne l'apprenne.
    $avant = DB::table('notifications')->count();

    $this->post(route('purchases.pay', $this->achat), [
        'amount'       => 400_000,
        'method'       => 'especes',
        'payment_date' => now()->toDateString(),
    ])->assertRedirect();

    expect(DB::table('notifications')->count())->toBeGreaterThan($avant);
});

test('l’alerte nomme le fournisseur, le montant et le reste dû', function () {
    $this->post(route('purchases.pay', $this->achat), [
        'amount'       => 400_000,
        'method'       => 'especes',
        'payment_date' => now()->toDateString(),
    ])->assertRedirect();

    $corps = corpsDesAlertes();

    expect($corps)->toContain('Provende SARL')
        ->and($corps)->toContain('ACH-09001')
        ->and($corps)->toContain('400.000')
        ->and($corps)->toContain('600.000'); // reste dû
});

test('un AVOIR est annoncé comme un retour d’argent, pas comme une sortie', function () {
    // Un montant négatif est un remboursement REÇU du fournisseur : l'annoncer
    // comme un décaissement serait un contresens.
    SupplierPayment::create([
        'supplier_invoice_id' => $this->achat->id,
        'amount' => 500_000, 'payment_date' => now()->toDateString(),
        'method' => 'especes', 'paid_by' => auth()->id(),
    ]);

    $this->post(route('purchases.pay', $this->achat), [
        'amount'       => -200_000,
        'method'       => 'especes',
        'payment_date' => now()->toDateString(),
    ])->assertRedirect();

    expect(corpsDesAlertes())->toContain('AVOIR')
        ->and(corpsDesAlertes())->toContain('200.000');
});

test('TOUS les chemins de règlement alertent, pas seulement l’écran d’achat', function () {
    // La raison d'être de l'observer : l'achat d'aliment crée lui aussi des
    // règlements, sans passer par le contrôleur d'achats.
    $avant = DB::table('notifications')->count();

    SupplierPayment::create([
        'supplier_invoice_id' => $this->achat->id,
        'amount' => 300_000, 'payment_date' => now()->toDateString(),
        'method' => 'virement', 'paid_by' => auth()->id(),
    ]);

    expect(DB::table('notifications')->count())->toBeGreaterThan($avant);
});

test('le contrôle du reste dû se fait SOUS VERROU', function () {
    /*
     * On ne peut pas rejouer une course dans un test sqlite mono-connexion : on
     * vérifie la propriété qui l'empêche, comme le fait la garde de
     * numérotation (#240). Le verrou doit porter sur la facture RE-LUE, pas sur
     * l'instance reçue en paramètre — c'est la forme exacte de la correction
     * documentée côté encaissement client (AUDIT C3).
     */
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path('Http/Controllers/SupplierInvoiceController.php')));

    expect($code)->toContain('SupplierInvoice::lockForUpdate()->findOrFail($invoice->id)')
        ->and($code)->toContain('DB::transaction');
});

test('un règlement au-delà du reste dû reste refusé', function () {
    // Non-régression : la borne existait, elle doit survivre au verrou.
    $this->post(route('purchases.pay', $this->achat), [
        'amount'       => 1_500_000,
        'method'       => 'especes',
        'payment_date' => now()->toDateString(),
    ])->assertRedirect()->assertSessionHas('error');

    expect(SupplierPayment::count())->toBe(0);
});

test('un avoir au-delà du déjà réglé reste refusé', function () {
    $this->post(route('purchases.pay', $this->achat), [
        'amount'       => -50_000,
        'method'       => 'especes',
        'payment_date' => now()->toDateString(),
    ])->assertRedirect()->assertSessionHas('error');

    expect(SupplierPayment::count())->toBe(0);
});

test('un achat NON VALIDÉ ne peut toujours pas être réglé', function () {
    $brouillon = SupplierInvoice::create([
        'provider_id'  => $this->fournisseur->id,
        'reference'    => 'ACH-09002',
        'invoice_date' => now()->toDateString(),
        'category'     => 'fournitures',
        'label'        => 'Achat en brouillon',
        'total_amount' => 100_000,
        'status'       => 'brouillon',
        'user_id'      => auth()->id(),
    ]);

    $this->post(route('purchases.pay', $brouillon), [
        'amount'       => 10_000,
        'method'       => 'especes',
        'payment_date' => now()->toDateString(),
    ])->assertRedirect()->assertSessionHas('error');

    expect(SupplierPayment::count())->toBe(0);
});

test('l’alerte est rattachée à une souscription, jamais au défaut « tout le monde »', function () {
    // Sans rattachement, `subscriptionColumnFor` renvoie null — c'est-à-dire
    // AUCUN filtre, donc un WhatsApp à tout compte ayant un numéro. Élargir une
    // audience payante par omission serait le contraire d'une correction.
    $methode = new ReflectionMethod(NotificationHub::class, 'subscriptionColumnFor');
    $methode->setAccessible(true);

    expect($methode->invoke(app(NotificationHub::class), 'alert_purchase'))->toBe('alert_fraud');
});

test('l’alerte mène quelque part, au bureau comme au terrain', function () {
    // Un clic sans effet se lit comme une panne : la destination doit exister.
    expect(NotificationHub::destinationFor('alert_purchase'))
        ->toBe(route('purchases.index', absolute: false));
});
