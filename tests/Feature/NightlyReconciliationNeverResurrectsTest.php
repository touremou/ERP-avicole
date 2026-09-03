<?php

use App\Actions\Sale\CreateSale;
use App\Models\Batch;
use App\Models\Client;
use App\Models\DailyCheck;
use App\Services\BatchQuantityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA RÉCONCILIATION NOCTURNE RESSUSCITAIT LES SUJETS VENDUS.
 *
 * `BatchQuantityService` recalculait l'effectif comme
 * `initial_quantity − (mortalité + quarantaine + tri)`, en ne lisant que les
 * POINTAGES JOURNALIERS — puis écrasait `current_quantity` avec ce nombre, dans
 * les DEUX sens.
 *
 * Or ce calcul ignore tous les autres flux sortants : ventes de sujets vifs
 * (`ValidateSale`), expéditions (`CreateDispatch`), dispatch de poussins,
 * départs à l'abattoir, transferts entre lots.
 *
 * Mesuré : un lot de 500 sujets dont 100 sont vendus tombe à 400 — puis la
 * réconciliation le REMONTE à 500.
 *
 * ─── CE QUI REND CE DÉFAUT GRAVE ───
 *
 *   • la commande tourne SEULE, toutes les nuits
 *     (`Schedule::command('batches:rebuild-quantities --force')->daily()`) ;
 *   • l'effectif est le nombre dont dépendent le taux de mortalité, le
 *     dénominateur du taux de ponte, l'aliment par sujet et la marge de
 *     clôture ;
 *   • et elle est présentée comme une RÉPARATION, donc lancée en confiance.
 *
 * ─── LA RÈGLE, ET POURQUOI ELLE EST SÛRE ───
 *
 * `current_quantity` est décrémenté par chaque sortie légitime. Le calcul des
 * pointages, qui n'en connaît qu'une partie, est donc un MAJORANT :
 *
 *   • effectif > majorant → impossible : dérive réelle, on corrige à la baisse.
 *     C'est ce que ce service existe pour rattraper ;
 *   • effectif < majorant → normal : les ventes l'expliquent. On ne touche à rien.
 *
 * Cette formulation est immunisée contre une énumération incomplète des flux
 * sortants — précisément le défaut qu'elle corrige. Ajouter les ventes au calcul
 * aurait déplacé le problème sur le flux suivant qu'on aurait oublié.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'building_id'      => $this->building->id,
        'initial_quantity' => 500,
        'current_quantity' => 500,
        'status'           => 'Actif',
    ]);
});

/** Vend $tetes sujets vifs du lot — la vente encaissée se valide d'office. */
function vendreDesSujets(int $farmId, Batch $lot, int $tetes, int $userId): void
{
    $client = Client::create([
        'farm_id' => $farmId, 'client_id' => 'CLI-' . Str::random(6),
        'name' => 'Grossiste', 'type' => 'entreprise',
        'category' => 'grossiste', 'status' => 'actif',
    ]);

    (new CreateSale())->execute([
        'client_id' => $client->id,
        'sale_date' => today()->toDateString(),
        'type'      => 'bon_livraison',
        'tax_rate'  => 0,
        'items'     => [[
            'product_type' => 'animal_vif', 'product_name' => 'Poulet vif',
            'batch_id' => $lot->id, 'quantity' => $tetes, 'unit' => 'tete', 'unit_price' => 30_000,
        ]],
        'immediate_payment' => 1_000,
        'payment_method'    => 'especes',
    ]);
}

/** Lance la réconciliation telle que la tâche nocturne l'exécute. */
function reconcilier(Batch $lot): array
{
    return app(BatchQuantityService::class)->rebuildForBatch($lot->fresh(), dryRun: false);
}

test('les sujets VENDUS ne reviennent pas à la vie', function () {
    /*
     * LE défaut, mesuré : 500 − 100 vendus = 400, puis remontés à 500 chaque
     * nuit.
     */
    vendreDesSujets($this->farm->id, $this->lot, 100, $this->adminUser->id);

    expect((int) $this->lot->fresh()->current_quantity)->toBe(400);

    reconcilier($this->lot);

    expect((int) $this->lot->fresh()->current_quantity)->toBe(400);
});

test('la réconciliation corrige toujours une dérive VERS LE HAUT', function () {
    /*
     * LA borne, et elle porte la raison d'être du service : une mortalité
     * pointée mais jamais décomptée laisse un effectif impossible. Ne plus rien
     * corriger aurait été pire que de trop corriger.
     */
    DailyCheck::create([
        'farm_id'    => $this->farm->id,
        'batch_id'   => $this->lot->id,
        'check_date' => today()->toDateString(),
        'mortality'  => 50,
        'user_id'    => $this->adminUser->id,
    ]);

    // L'effectif est resté à 500 alors que 50 sujets sont morts.
    $this->lot->forceFill(['current_quantity' => 500])->save();

    reconcilier($this->lot);

    expect((int) $this->lot->fresh()->current_quantity)->toBe(450);
});

test('vente ET mortalité ensemble : chacune compte une fois', function () {
    /*
     * Le mélange, qui distingue une correction d'une amputation. 500 sujets,
     * 100 vendus (→ 400), puis 50 morts pointés (→ 350). La réconciliation ne
     * doit ni remonter à 450 ni redescendre à 400.
     */
    vendreDesSujets($this->farm->id, $this->lot, 100, $this->adminUser->id);

    DailyCheck::create([
        'farm_id'    => $this->farm->id,
        'batch_id'   => $this->lot->id,
        'check_date' => today()->toDateString(),
        'mortality'  => 50,
        'user_id'    => $this->adminUser->id,
    ]);

    $this->lot->forceFill(['current_quantity' => 350])->save();

    reconcilier($this->lot);

    expect((int) $this->lot->fresh()->current_quantity)->toBe(350);
});

test('un lot SANS mouvement reste intact — non-régression', function () {
    reconcilier($this->lot);

    expect((int) $this->lot->fresh()->current_quantity)->toBe(500);
});

test('la SIMULATION ne modifie rien', function () {
    // La commande simule par défaut : la promesse doit tenir dans les deux sens.
    vendreDesSujets($this->farm->id, $this->lot, 100, $this->adminUser->id);

    app(BatchQuantityService::class)->rebuildForBatch($this->lot->fresh(), dryRun: true);

    expect((int) $this->lot->fresh()->current_quantity)->toBe(400);
});

test('la RÉOUVERTURE d’un lot clos remonte bien l’effectif', function () {
    /*
     * LA borne que la suite complète a révélée — et que mon premier correctif
     * cassait.
     *
     * Ce service a DEUX appelants aux besoins opposés : la réconciliation
     * nocturne ne doit jamais remonter, la réouverture le doit — la clôture
     * ayant mis `current_quantity` à ZÉRO. Confondre les deux est précisément ce
     * qui avait produit le défaut ; ne garder que la moitié « ne jamais
     * remonter » aurait laissé tout lot rouvert vide, donc inutilisable.
     *
     * `ReopenBatch` passe donc `allowRaise: true`, et l'intention est déclarée au
     * point d'appel plutôt que devinée.
     */
    DailyCheck::create([
        'farm_id'    => $this->farm->id,
        'batch_id'   => $this->lot->id,
        'check_date' => today()->toDateString(),
        'mortality'  => 50,
        'user_id'    => $this->adminUser->id,
    ]);

    // La clôture met l'effectif à zéro.
    $this->lot->forceFill([
        'current_quantity' => 0,
        'status'           => 'Terminé',
        'closing_date'     => today()->toDateString(),
    ])->save();

    app(\App\Actions\Batch\ReopenBatch::class)->execute($this->lot->fresh());

    $lot = $this->lot->fresh();

    expect($lot->status)->toBe('Actif')
        ->and((int) $lot->current_quantity)->toBe(450);   // 500 − 50 morts
});
