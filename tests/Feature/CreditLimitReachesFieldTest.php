<?php

use App\Models\Client;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE TERRAIN NE VOYAIT PAS LA RÈGLE QU'ON VENAIT DE LUI OPPOSER.
 *
 * #237 a étendu le plafond crédit au canal des techniciens : une vente hors
 * ligne qui dépasse le plafond, ou qui vise un client suspendu, est désormais
 * refusée DÉFINITIVEMENT à la synchronisation.
 *
 * Mais le paquet descendu sur le téléphone ne contenait pas `credit_limit`. Le
 * terrain ne pouvait donc pas voir la règle qu'on lui applique : la vente
 * partait, la marchandise avec, le client repartait avec ses sacs — et le refus
 * n'arrivait qu'à la synchronisation suivante, sans recours. Une garde juste,
 * annoncée trop tard, se retourne contre celui qu'elle protège.
 *
 * Le solde descendait déjà ; il manquait la BORNE à laquelle le comparer.
 *
 * CE QUE LE CONTRÔLE LOCAL N'EST PAS : il ne remplace pas celui du serveur. Le
 * solde peut avoir bougé pendant la tournée, et c'est le serveur qui tranche.
 * Il évite seulement d'engager la marchandise sur une vente qu'on sait déjà
 * refusée — c'est la même intention que le contrôle de stock local, qui borne
 * le panier sans prétendre remplacer le déstockage du bureau.
 *
 * CE QU'ON NE DESCEND PAS : rien de plus. Le paquet terrain reste une liste de
 * colonnes autorisées, et l'ajout se limite à la borne nécessaire à la règle.
 */

beforeEach(function () {
    $this->setUpRbac();

    \Illuminate\Support\Facades\DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-TERRAIN',
        'name' => 'Boutique Madina', 'type' => 'entreprise', 'category' => 'detaillant',
        'status' => 'actif', 'credit_limit' => 1_500_000, 'balance' => 400_000,
    ]);

    Sanctum::actingAs($this->managerUser);
});

/** Le client tel que le téléphone le reçoit. */
function clientTelecharge(): array
{
    return test()->getJson('/api/v1/sync/pull')->assertOk()
        ->json('entities.clients.upserts.0') ?? [];
}

test('le plafond crédit descend jusqu’au terrain', function () {
    // LE défaut : la borne manquait, donc la règle était invisible du terrain.
    expect(clientTelecharge())->toHaveKey('credit_limit')
        ->and((float) clientTelecharge()['credit_limit'])->toBe(1500000.0);
});

test('le solde continue de descendre — les deux sont nécessaires', function () {
    // Un plafond sans solde ne dit rien : c'est leur somme qui décide.
    $client = clientTelecharge();

    expect($client)->toHaveKey('balance')
        ->and((float) $client['balance'])->toBe(400000.0);
});

test('le statut descend aussi : un client suspendu est refusé pour une AUTRE raison', function () {
    // La règle serveur a deux volets ; le miroir doit pouvoir les distinguer,
    // sinon il annonce « plafond dépassé » à un client parfaitement solvable.
    expect(clientTelecharge())->toHaveKey('status');
});

test('rien d’autre ne s’est invité dans le paquet client', function () {
    /*
     * Le paquet terrain est une liste de colonnes AUTORISÉES : on vérifie que
     * l'ajout n'a pas élargi la fuite. Un téléphone de terrain se perd.
     */
    $attendu = ['id', 'client_id', 'name', 'category', 'price_list_id', 'phone',
                'balance', 'credit_limit', 'status', 'updated_at'];

    expect(array_keys(clientTelecharge()))->toEqualCanonicalizing($attendu);
});

test('l’écran de vente du terrain applique la MÊME règle', function () {
    /*
     * Garde de forme sur la PWA. Le miroir doit porter les trois volets de
     * `Client::creditRefusalReason` : le statut, la convention « plafond à 0 =
     * pas de plafond », et la comparaison solde + crédit nouveau > plafond.
     *
     * Un miroir qui n'en reprendrait qu'une partie serait pire que pas de
     * miroir : il bloquerait des ventes légitimes, ou en laisserait passer que
     * le serveur refusera — et le technicien cesserait de lui faire confiance.
     */
    $source = file_get_contents(base_path('mobile/src/features/commerce/SaleScreen.tsx'));

    expect($source)->toContain('creditRefusalReason')
        ->and($source)->toContain('credit_limit')
        ->and($source)->toContain("status !== 'actif'")
        ->and($source)->toContain('limit <= 0');
});

test('la vente est BLOQUÉE à la saisie, pas seulement signalée', function () {
    // Un avertissement qu'on peut ignorer laisse partir la marchandise : le
    // bouton doit être fermé tant que la règle n'est pas satisfaite.
    $source = file_get_contents(base_path('mobile/src/features/commerce/SaleScreen.tsx'));

    expect($source)->toContain('creditWarning !== null')
        ->and($source)->toContain('|| creditWarning) return');
});

test('la règle serveur reste la seule qui tranche', function () {
    // Non-régression du garde de #237 : le contrôle local ne l'a pas remplacée.
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path('Services/Sync/SyncService.php')));

    expect($code)->toContain('creditRefusalReason');
});
