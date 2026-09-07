<?php

use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\SyncOperation;
use App\Services\Sync\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN COMPTAGE CONFORME N'INSCRIVAIT SON UUID NULLE PART.
 *
 * Toute la synchro terrain est idempotente par `uuid`, et chaque gardien va le
 * chercher dans la ligne que l'opération a créée : `Sale::where('uuid', …)`,
 * `StockAdjustment::where('uuid', …)`…
 *
 * Ce procédé a un angle mort : une opération qui RÉUSSIT SANS RIEN CRÉER
 * n'inscrit son uuid nulle part, et un rejeu la ré-exécute.
 *
 * Le comptage d'inventaire conforme est exactement ce cas. « Aucun écart » est
 * un succès métier — le comptage confirme le stock — mais aucun ajustement n'est
 * écrit, donc aucun uuid.
 *
 * Mesuré :
 *
 *   • stock à 100 kg, comptage conforme à 100 → succès, rien d'écrit ;
 *   • un achat entre ensuite 50 kg au magasin → 150 ;
 *   • le MÊME comptage, rejoué (coupure réseau, relance de la file), trouve
 *     alors un écart de −50 et l'APPLIQUE : le stock retombe à 100.
 *
 * Les 50 kg reçus entre les deux essais sont annulés, par une opération que le
 * terrain croyait déjà passée — et l'ajustement porte le motif « inventaire »,
 * ce qui le rend indiscernable d'un vrai écart de comptage.
 *
 * ─── CE QU'ON NE FAIT PAS ───
 *
 * Écrire un ajustement à ZÉRO aurait aussi inscrit l'uuid. Mais
 * `CreateStockAdjustment` le refuse exprès — « Aucun écart : la quantité comptée
 * est identique au stock (aucun ajustement) » — et un « ajustement » qui
 * n'ajuste rien encombrerait la démarque que ces écrans servent à suivre.
 * Contourner ce refus depuis la synchro aurait fait diverger les deux chemins.
 *
 * On donne donc à ces opérations un endroit où exister : `sync_operations`,
 * registre des opérations sans document. Il ne remplace pas les uuid portés par
 * les documents — il complète, pour les seuls cas où il n'y a pas de document.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->magasin = Stock::create([
        'farm_id'          => $this->farm->id,
        'item_name'        => 'Provende Ponte',
        'category'         => Stock::CAT_CONSO,
        'unit'             => 'KG',
        'current_quantity' => 100,
        'alert_threshold'  => 0,
    ]);
});

/** La charge utile d'un comptage d'inventaire, prête à être rejouée. */
function comptageInventaire(int $stockId, float $compte, ?string $uuid = null): array
{
    return [
        'uuid'             => $uuid ?? (string) Str::uuid(),
        'stock_id'         => $stockId,
        'counted_quantity' => $compte,
        'count_date'       => today()->toDateString(),
    ];
}

/** Envoie l'opération comme le fait la file de synchro du terrain. */
function envoyerComptage(array $payload): array
{
    return app(SyncService::class)->handle('inventory_count.create', $payload);
}

test('rejouer un comptage CONFORME n’annule pas ce qui s’est passé depuis', function () {
    /*
     * LE défaut, mesuré : les 50 kg reçus entre les deux essais disparaissaient.
     */
    $comptage = comptageInventaire($this->magasin->id, 100);

    expect(envoyerComptage($comptage)['status'])->toBe('success');

    // Entre les deux essais, un achat entre au magasin.
    $this->magasin->increment('current_quantity', 50);

    expect(envoyerComptage($comptage)['status'])->toBe('already_synced')
        ->and((float) $this->magasin->fresh()->current_quantity)->toBe(150.0)
        ->and(StockAdjustment::count())->toBe(0);
});

test('un comptage conforme laisse sa trace au registre', function () {
    // La trace est tout ce qui distingue « déjà fait » de « jamais vu ».
    $comptage = comptageInventaire($this->magasin->id, 100);

    envoyerComptage($comptage);

    expect(SyncOperation::alreadyApplied('inventory_count.create', $comptage['uuid']))->toBeTrue();
});

test('rejouer un comptage AVEC ÉCART reste sans effet — non-régression', function () {
    /*
     * L'autre moitié de l'idempotence, celle qui marchait déjà : l'ajustement
     * porte l'uuid, le rejeu le reconnaît. Elle ne doit pas bouger.
     */
    $comptage = comptageInventaire($this->magasin->id, 80);

    expect(envoyerComptage($comptage)['status'])->toBe('success')
        ->and((float) $this->magasin->fresh()->current_quantity)->toBe(80.0);

    $this->magasin->increment('current_quantity', 50);      // 130

    expect(envoyerComptage($comptage)['status'])->toBe('already_synced')
        ->and((float) $this->magasin->fresh()->current_quantity)->toBe(130.0)
        ->and(StockAdjustment::count())->toBe(1);
});

test('un comptage conforme applique toujours son résultat — non-régression', function () {
    /*
     * LA borne : on ferme un rejeu, pas le geste. Un comptage qui confirme le
     * stock reste un succès, et il ne crée toujours aucun ajustement — un
     * « ajustement » à zéro encombrerait la démarque.
     */
    $resultat = envoyerComptage(comptageInventaire($this->magasin->id, 100));

    expect($resultat['status'])->toBe('success')
        ->and($resultat['server_id'])->toBeNull()
        ->and((float) $this->magasin->fresh()->current_quantity)->toBe(100.0)
        ->and(StockAdjustment::count())->toBe(0);
});

test('deux comptages conformes DISTINCTS passent tous les deux', function () {
    /*
     * La borne du registre : il mémorise une OPÉRATION, pas un article. Deux
     * comptages différents du même stock — le matin et le soir — sont deux
     * gestes, et le second ne doit pas être pris pour un rejeu du premier.
     */
    envoyerComptage(comptageInventaire($this->magasin->id, 100));
    $second = envoyerComptage(comptageInventaire($this->magasin->id, 100));

    expect($second['status'])->toBe('success')
        ->and(SyncOperation::count())->toBe(2);
});

test('un comptage AVEC ÉCART n’encombre pas le registre — non-régression', function () {
    // Il a déjà son document pour porter son uuid : rien à inscrire ici.
    envoyerComptage(comptageInventaire($this->magasin->id, 80));

    expect(StockAdjustment::count())->toBe(1)
        ->and(SyncOperation::count())->toBe(0);
});

test('le registre distingue deux opérations de TYPES différents', function () {
    /*
     * Le registre mémorise un COUPLE (type, uuid). Le comptage d'inventaire en
     * est aujourd'hui le seul client, mais il est destiné à toutes les
     * opérations sans document : deux terrains peuvent produire le même uuid
     * pour deux natures d'opération sans se gêner.
     *
     * Ne retenir que l'uuid ferait passer la seconde pour un rejeu de la
     * première, et l'application refuserait un geste jamais accompli.
     */
    $uuid = (string) Str::uuid();

    SyncOperation::remember('inventory_count.create', $uuid);

    expect(SyncOperation::alreadyApplied('inventory_count.create', $uuid))->toBeTrue()
        ->and(SyncOperation::alreadyApplied('task.start', $uuid))->toBeFalse();
});

test('inscrire deux fois la même opération ne lève pas', function () {
    /*
     * `remember()` doit être idempotent : deux rejeux simultanés peuvent
     * l'atteindre tous les deux avant que l'un ait inscrit sa ligne, et l'index
     * unique (type, uuid) ferait alors échouer une opération qui a pourtant
     * réussi. Le geste d'inscription ne doit jamais être ce qui casse.
     */
    $uuid = (string) Str::uuid();

    SyncOperation::remember('inventory_count.create', $uuid);
    SyncOperation::remember('inventory_count.create', $uuid);

    expect(SyncOperation::where('uuid', $uuid)->count())->toBe(1);
});
