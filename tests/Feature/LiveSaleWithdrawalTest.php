<?php

use App\Actions\Sale\ValidateSale;
use App\Models\Batch;
use App\Models\Client;
use App\Models\HealthCheck;
use App\Models\Sale;
use App\Models\SaleItem;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * ON NE POUVAIT PAS ABATTRE CES BÊTES, MAIS ON POUVAIT LES VENDRE VIVANTES.
 *
 * Après un vaccin ou un traitement, la notice interdit la consommation avant
 * l'échéance. La règle bloquait l'abattage interne (SlaughterService), et
 * depuis #235 la mise en vente des œufs. Vendre les animaux SUR PIED restait
 * libre — or l'acheteur les abat, et le résidu part avec eux. La contrainte ne
 * disparaît pas parce que l'animal change de mains : elle quittait
 * l'exploitation sans avoir été levée.
 *
 * CE N'EST PAS UNE POLITIQUE INVENTÉE ICI. Le garde voisin — celui de la
 * quarantaine — annonçait déjà cette interdiction dans son propre commentaire :
 * « vente à la tête interdite (délai d'attente médicamenteux) ». Mais il testait
 * la QUARANTAINE, qui est l'autre règle : cette base les distingue
 * explicitement, la quarantaine se levant sur DÉCISION et le délai d'attente
 * tout seul à l'échéance. Le texte annonçait donc une garde que le code ne
 * faisait pas — le même motif que l'annulation de vente (#238), où le code
 * portait sa propre contre-indication.
 *
 * OÙ LA GARDE VIT : à la VALIDATION, seul point par lequel la marchandise sort,
 * quel que soit le chemin qui a créé la vente — bureau, terrain ou comptoir.
 *
 * CE QU'ON N'ENTRAVE PAS : la vente au POIDS d'une carcasse déjà produite, ni
 * les lignes sans lot. Seule la sortie d'ANIMAUX VIVANTS à la tête est visée,
 * exactement comme le décompte d'effectif qu'elle déclenche.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->managerUser);

    $this->lot = Batch::factory()->create([
        'code' => 'CHAIR-VIF', 'initial_quantity' => 200,
        'current_quantity' => 200, 'qty_alive' => 200, 'status' => 'Actif',
    ]);

    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-VIF',
        'name' => 'Marché de Kindia', 'type' => 'entreprise', 'category' => 'detaillant',
        'status' => 'actif', 'credit_limit' => 0, 'balance' => 0,
    ]);
});

/** Traitement posant un délai d'attente sur le lot. */
function traitementSurLot(int $batchId, int $jours, int $ilYA = 0): HealthCheck
{
    return HealthCheck::create([
        'batch_id' => $batchId,
        'intervention_date' => now()->subDays($ilYA)->toDateString(),
        'type' => 'Traitement', 'product_name' => 'Oxytétracycline',
        'mode_administration' => 'Eau de boisson', 'withdrawal_days' => $jours,
    ]);
}

/** Vente de $tetes sujets vivants, en brouillon. */
function venteSurPied(int $clientId, int $batchId, int $tetes = 20): Sale
{
    $vente = Sale::create([
        'client_id' => $clientId, 'user_id' => auth()->id(),
        'reference' => \App\Services\SaleNumberingService::generate('bon_livraison'),
        'sale_date' => now()->toDateString(), 'type' => 'bon_livraison', 'status' => 'brouillon',
    ]);

    SaleItem::create([
        'sale_id' => $vente->id, 'product_type' => 'volaille_vivante',
        'product_name' => 'Poulet de chair vif', 'batch_id' => $batchId,
        'quantity' => $tetes, 'unit' => 'tete', 'unit_price' => 45000,
        'total' => $tetes * 45000,
    ]);

    $vente->recalculateTotals();

    return $vente->fresh();
}

test('la vente SUR PIED est refusée pendant le délai d’attente', function () {
    // LE défaut : ces bêtes ne pouvaient pas être abattues ici, mais pouvaient
    // partir vivantes chez un acheteur qui les abattrait le jour même.
    traitementSurLot($this->lot->id, 10, 2);

    $vente = venteSurPied($this->client->id, $this->lot->id);

    expect(fn () => app(ValidateSale::class)->execute($vente))
        ->toThrow(Exception::class, "DÉLAI D'ATTENTE");

    expect($vente->fresh()->status)->toBe('brouillon')
        ->and($this->lot->fresh()->current_quantity)->toBe(200);
});

test('le refus dit JUSQU’À QUAND il court', function () {
    // La levée est automatique : le message doit donner la date, sinon il
    // envoie l'utilisateur chercher une décision que personne n'a à prendre.
    traitementSurLot($this->lot->id, 10, 2);

    $vente = venteSurPied($this->client->id, $this->lot->id);

    expect(fn () => app(ValidateSale::class)->execute($vente))
        ->toThrow(Exception::class, now()->addDays(8)->format('d/m/Y'));
});

test('le délai purgé libère la vente sur pied', function () {
    // Traitement de 10 j administré il y a 12 j : échéance passée.
    traitementSurLot($this->lot->id, 10, 12);

    $vente = venteSurPied($this->client->id, $this->lot->id);

    app(ValidateSale::class)->execute($vente);

    expect($vente->fresh()->status)->toBe('valide')
        ->and($this->lot->fresh()->current_quantity)->toBe(180);
});

test('une intervention SANS délai (vitamine) ne bloque pas la vente', function () {
    HealthCheck::create([
        'batch_id' => $this->lot->id, 'intervention_date' => now()->toDateString(),
        'type' => 'Vitamine', 'product_name' => 'Complexe AD3E',
        'mode_administration' => 'Eau de boisson', 'withdrawal_days' => null,
    ]);

    $vente = venteSurPied($this->client->id, $this->lot->id);

    app(ValidateSale::class)->execute($vente);

    expect($vente->fresh()->status)->toBe('valide');
});

test('une vente SANS lot n’est pas concernée', function () {
    // Le délai porte sur des animaux identifiés : une ligne libre ne décompte
    // aucun effectif et ne peut pas être rattachée à un traitement.
    traitementSurLot($this->lot->id, 10, 2);

    $vente = Sale::create([
        'client_id' => $this->client->id, 'user_id' => auth()->id(),
        'reference' => \App\Services\SaleNumberingService::generate('bon_livraison'),
        'sale_date' => now()->toDateString(), 'type' => 'bon_livraison', 'status' => 'brouillon',
    ]);
    SaleItem::create([
        'sale_id' => $vente->id, 'product_type' => 'autre',
        'product_name' => 'Fumier', 'quantity' => 10, 'unit' => 'sac',
        'unit_price' => 5000, 'total' => 50000,
    ]);
    $vente->recalculateTotals();

    app(ValidateSale::class)->execute($vente->fresh());

    expect($vente->fresh()->status)->toBe('valide');
});

test('la QUARANTAINE reste une règle distincte, avec son propre message', function () {
    // Les deux gardes coexistent et ne disent pas la même chose : l'une se lève
    // sur décision, l'autre à l'échéance. Le commentaire qui les confondait est
    // à l'origine du trou.
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path('Actions/Sale/ValidateSale.php')));

    expect($code)->toContain('activeQuarantine()')
        ->and($code)->toContain('activeWithdrawal()')
        ->and($code)->toContain('QUARANTAINE')
        ->and($code)->toContain("DÉLAI D'ATTENTE");
});

test('les trois objets de la règle sont désormais couverts', function () {
    // Viande abattue ici, œufs pondus, animaux vendus vivants : une même
    // interdiction, trois sorties possibles de l'exploitation.
    $sources = [
        'Services/SlaughterService.php',                     // abattage interne
        'Actions/EggProduction/GradeEggProduction.php',      // mise en stock des œufs
        'Actions/Sale/ValidateSale.php',                     // vente sur pied
    ];

    foreach ($sources as $source) {
        $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path($source)));

        expect($code)->toMatch('#activeWithdrawal|withdrawalOn#');
    }
});
