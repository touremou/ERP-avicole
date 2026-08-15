<?php

use App\Actions\Sale\ValidateSale;
use App\Models\Client;
use App\Models\Sale;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE PLAFOND CRÉDIT NE TENAIT QUE SUR L'ÉCRAN DU BUREAU.
 *
 * Le promoteur vit à l'étranger ; le plafond par client est le seul frein sur
 * l'argent qui sort de l'exploitation en son absence. Il était vérifié à un
 * unique endroit — StoreSaleRequest, le formulaire du bureau.
 *
 *   • la SYNCHRO ne le connaissait pas. C'est pourtant LE canal des techniciens,
 *     ceux qui vendent réellement sur le terrain : `sale.create` acceptait
 *     n'importe quel montant à crédit, pour n'importe quel client — y compris
 *     SUSPENDU ou BLACKLISTÉ, l'autre garde absente de ce chemin.
 *
 *   • la VALIDATION non plus. Or c'est là que tout se joue : le solde client ne
 *     bouge qu'à la validation (recalculateBalance ignore les brouillons) et la
 *     marchandise ne sort qu'à la validation. Une vente créée avant la
 *     suspension d'un client, ou créée hors ligne, se validait sans jamais
 *     rencontrer son plafond.
 *
 * La règle vit désormais sur le modèle (Client::creditRefusalReason) et les
 * trois chemins l'appellent — c'est ce qui les empêche de re-diverger.
 *
 * CE QU'ON N'A PAS CHANGÉ : plafond à 0 = pas de plafond (convention du champ),
 * et une vente soldée d'avance ne crée aucun encours, donc ne bute sur rien.
 */

beforeEach(function () {
    $this->setUpRbac();

    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-CRED',
        'name' => 'Boutique Madina', 'type' => 'entreprise', 'category' => 'detaillant',
        'phone' => '620000000', 'status' => 'actif',
        'credit_limit' => 1_000_000, 'balance' => 0,
    ]);

    $this->article = Stock::create([
        'category' => Stock::CAT_OEUFS, 'item_name' => 'M', 'unit' => 'Alvéole',
        'current_quantity' => 1000, 'unit_price' => 0, 'last_unit_price' => 0, 'alert_threshold' => 0,
    ]);

    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

/** Opération de vente terrain (synchro). */
function venteTerrain(int $clientId, float $montant, float $paiement = 0): array
{
    return ['op_uuid' => (string) Str::uuid(), 'type' => 'sale.create', 'payload' => [
        'uuid'              => (string) Str::uuid(),
        'client_id'         => $clientId,
        'sale_date'         => now()->toDateString(),
        'type'              => 'bon_livraison',
        'immediate_payment' => $paiement,
        'items'             => [[
            'product_type' => 'oeufs', 'product_name' => 'M',
            'quantity' => 1, 'unit' => 'alveole', 'unit_price' => $montant,
        ]],
    ]];
}

/** Vente en brouillon, prête à valider. */
function venteBrouillon(int $clientId, float $montant): Sale
{
    $vente = Sale::create([
        'client_id' => $clientId, 'user_id' => auth()->id(),
        'reference' => \App\Services\SaleNumberingService::generate('bon_livraison'),
        'sale_date' => now()->toDateString(), 'type' => 'bon_livraison', 'status' => 'brouillon',
    ]);

    \App\Models\SaleItem::create([
        'sale_id' => $vente->id, 'product_type' => 'oeufs', 'product_name' => 'M',
        'quantity' => 1, 'unit' => 'alveole', 'unit_price' => $montant, 'total' => $montant,
    ]);

    $vente->recalculateTotals();

    return $vente->fresh();
}

test('SYNCHRO : une vente à crédit au-delà du plafond est refusée', function () {
    // LE défaut. Le canal des techniciens ignorait le plafond.
    Sanctum::actingAs($this->managerUser);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [
        venteTerrain($this->client->id, 1_500_000),
    ]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('validation_failed')
        ->and(Sale::withoutGlobalScopes()->count())->toBe(0);
});

test('SYNCHRO : le refus est DÉFINITIF, pas une erreur rejouable', function () {
    // Le plafond ne se lèvera pas tout seul : rejouer indéfiniment noierait le
    // journal de synchro sans jamais aboutir.
    Sanctum::actingAs($this->managerUser);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [
        venteTerrain($this->client->id, 1_500_000),
    ]])->assertOk()->json('results.0');

    expect($res['status'])->not->toBe('error');
});

test('SYNCHRO : un client SUSPENDU ne peut pas être livré', function () {
    // L'autre garde qui manquait sur ce chemin — indépendante du plafond.
    $this->client->update(['status' => 'suspendu']);
    Sanctum::actingAs($this->managerUser);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [
        venteTerrain($this->client->id, 10_000, 10_000),
    ]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('validation_failed')
        ->and(Sale::withoutGlobalScopes()->count())->toBe(0);
});

test('SYNCHRO : une vente sous le plafond passe normalement', function () {
    // On ne bloque pas le terrain : c'est le dépassement qu'on refuse.
    Sanctum::actingAs($this->managerUser);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [
        venteTerrain($this->client->id, 300_000),
    ]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('success')
        ->and(Sale::withoutGlobalScopes()->count())->toBe(1);
});

test('SYNCHRO : une vente SOLDÉE d’avance ne bute sur aucun plafond', function () {
    // Payée intégralement = aucun encours créé, quel que soit le montant.
    Sanctum::actingAs($this->managerUser);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [
        venteTerrain($this->client->id, 5_000_000, 5_000_000),
    ]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('success');
});

test('SYNCHRO : l’idempotence prime sur le plafond', function () {
    /*
     * Point de conception. La garde d'uuid passe AVANT la règle de crédit :
     * une vente déjà appliquée doit être reconnue comme telle même si le client
     * a depuis atteint son plafond. Sinon un simple rejeu réseau la ferait
     * « échouer » après coup, et le terrain la ressaisirait.
     */
    Sanctum::actingAs($this->managerUser);
    $op = venteTerrain($this->client->id, 900_000);

    expect($this->postJson('/api/v1/sync/push', ['operations' => [$op]])->json('results.0.status'))
        ->toBe('success');

    // Le client est maintenant au plafond.
    $this->client->update(['balance' => 1_000_000]);

    expect($this->postJson('/api/v1/sync/push', ['operations' => [$op]])->json('results.0.status'))
        ->toBe('already_synced')
        ->and(Sale::withoutGlobalScopes()->count())->toBe(1);
});

test('VALIDATION : une vente qui ferait dépasser le plafond est refusée', function () {
    // Le moment où la créance naît et où la marchandise sort.
    $this->actingAs($this->managerUser);
    $this->client->update(['balance' => 900_000]);

    $vente = venteBrouillon($this->client->id, 500_000);

    expect(fn () => app(ValidateSale::class)->execute($vente))
        ->toThrow(Exception::class, 'Plafond crédit dépassé');

    expect($vente->fresh()->status)->toBe('brouillon')
        ->and((float) $this->article->fresh()->current_quantity)->toBe(1000.0);
});

test('VALIDATION : une vente créée AVANT la suspension du client est refusée', function () {
    // Le cas que la garde de création ne pouvait pas voir : le client bascule
    // entre la saisie et la livraison.
    $this->actingAs($this->managerUser);
    $vente = venteBrouillon($this->client->id, 100_000);

    $this->client->update(['status' => 'blackliste']);

    expect(fn () => app(ValidateSale::class)->execute($vente))
        ->toThrow(Exception::class, 'blackliste');
});

test('VALIDATION : une vente sous le plafond se valide et déstocke', function () {
    // Non-régression : le chemin normal reste intact.
    $this->actingAs($this->managerUser);
    $vente = venteBrouillon($this->client->id, 200_000);

    app(ValidateSale::class)->execute($vente);

    expect($vente->fresh()->status)->toBe('valide')
        ->and((float) $this->article->fresh()->current_quantity)->toBe(999.0);
});

test('VALIDATION : les règlements déjà encaissés réduisent l’encours examiné', function () {
    // Ce qui compte est ce qui restera dû sur CETTE vente, pas son montant brut.
    $this->actingAs($this->managerUser);
    $this->client->update(['balance' => 900_000]);

    $vente = venteBrouillon($this->client->id, 500_000);

    \App\Models\Payment::create([
        'sale_id' => $vente->id, 'amount' => 450_000, 'received_by' => $this->managerUser->id,
        'payment_date' => now()->toDateString(), 'method' => 'especes',
    ]);

    app(ValidateSale::class)->execute($vente);

    expect($vente->fresh()->status)->toBe('valide');
});

test('un plafond à ZÉRO reste « pas de plafond »', function () {
    // Convention historique du champ, conservée : la changer transformerait
    // chaque client sans plafond défini en client bloqué.
    $this->client->update(['credit_limit' => 0, 'balance' => 9_000_000]);

    expect($this->client->fresh()->creditRefusalReason(5_000_000))->toBeNull();
});

test('les trois chemins appellent la MÊME règle', function () {
    // La garde de forme : c'est la duplication qui avait laissé le terrain sans
    // plafond. Un quatrième chemin qui réécrirait la règle à la main rouvrirait
    // exactement le même trou.
    $fichiers = [
        'Http/Requests/Sale/StoreSaleRequest.php',
        'Services/Sync/SyncService.php',
        'Actions/Sale/ValidateSale.php',
    ];

    foreach ($fichiers as $fichier) {
        $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path($fichier)));
        expect($code)->toContain('creditRefusalReason');
    }
});
