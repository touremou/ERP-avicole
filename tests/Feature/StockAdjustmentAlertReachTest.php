<?php

use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * TROIS CHEMINS ÉCRIVAIENT UN AJUSTEMENT, UN SEUL ÉTAIT SURVEILLÉ.
 *
 * L'ajustement d'inventaire est le vecteur classique de dissimulation : on compte
 * moins que le réel, on ajuste à la baisse, et l'écart disparaît des comptes. C'est
 * pourquoi l'écran d'ajustement du magasin ALERTE (CreateStockAdjustment →
 * alertStockAdjustment), avec la même sévérité selon le sens.
 *
 * Deux autres chemins produisaient exactement le même effet — le stock change, un
 * mouvement `adjustment` est écrit — SANS alerte :
 *
 *   • modifier la QUANTITÉ depuis la fiche d'un article (UpdateStockAction) ;
 *   • l'INVENTAIRE PHYSIQUE des œufs (EggProductionController).
 *
 * Il suffisait donc d'éditer la fiche au lieu d'ouvrir l'écran prévu pour que la
 * démarque passe inaperçue. Le contrôle existait ; on pouvait le contourner en
 * changeant de porte.
 *
 * ─── CE QU'ON NE FAIT PAS ───
 *
 * On n'entrave aucun de ces gestes. L'inventaire physique est l'outil LÉGITIME pour
 * corriger un niveau — c'est vers lui que le lot #215 renvoie explicitement, faute
 * de pouvoir trancher depuis le registre. On ne le bride pas : on le rend visible.
 *
 * Et l'alerte n'est jamais bloquante : un canal muet ne doit pas empêcher la
 * correction d'une fiche ou d'un inventaire.
 */

beforeEach(function () {
    $this->setUpRbac();

    // L'état réel de l'exploitation : le WhatsApp ne sort pas. L'alerte doit donc
    // exister sur les autres canaux (cf. #216).
    Setting::set('whatsapp.driver', 'log');
});

/** Article de stock ordinaire. */
function adjustableStock(int $farmId, float $qty = 100): Stock
{
    return Stock::withoutGlobalScopes()->create([
        'farm_id'          => $farmId,
        'item_name'        => 'Maïs concassé',
        'category'         => Stock::CAT_CONSO,
        'unit'             => 'KG',
        'current_quantity' => $qty,
        'alert_threshold'  => 10,
        'unit_price'       => 5000,
    ]);
}

/** Contenu lisible de la dernière notification (le JSON échappe les accents). */
function adjustmentAlertText(int $userId): string
{
    $raw = DB::table('notifications')->where('notifiable_id', $userId)->latest('created_at')->value('data');

    return json_encode(json_decode((string) $raw, true), JSON_UNESCAPED_UNICODE) ?: '';
}

test('modifier la QUANTITÉ depuis la fiche d’un article alerte', function () {
    // LE contournement : même effet que l'écran d'ajustement, sans sa surveillance.
    $stock = adjustableStock($this->farm->id, 100);

    app(\App\Actions\Stock\UpdateStockAction::class)->execute($stock, [
        'item_name'        => $stock->item_name,
        'unit'             => 'KG',
        'alert_threshold'  => 10,
        'current_quantity' => 60,          // 40 kg disparaissent
        'unit_price'       => 5000,
    ], $this->adminUser->id);

    expect(adjustmentAlertText($this->adminUser->id))->toContain('AJUSTEMENT STOCK')
        ->and(adjustmentAlertText($this->adminUser->id))->toContain('Maïs concassé');
});

test('modifier une fiche SANS toucher à la quantité n’alerte pas', function () {
    // Le pendant : renommer un article ou corriger son prix n'est pas un ajustement.
    // Alerter là-dessus userait l'attention qu'on veut garder pour les démarques.
    $stock = adjustableStock($this->farm->id, 100);

    app(\App\Actions\Stock\UpdateStockAction::class)->execute($stock, [
        'item_name'        => 'Maïs concassé (sac 50)',
        'unit'             => 'KG',
        'alert_threshold'  => 15,
        'current_quantity' => 100,        // inchangée
        'unit_price'       => 6000,
    ], $this->adminUser->id);

    expect(DB::table('notifications')->where('notifiable_id', $this->adminUser->id)->count())->toBe(0);
});

test('l’INVENTAIRE PHYSIQUE des œufs alerte', function () {
    $stock = Stock::withoutGlobalScopes()->create([
        'farm_id' => $this->farm->id, 'item_name' => 'M', 'category' => Stock::CAT_OEUFS,
        'unit' => 'Alvéole', 'current_quantity' => 200, 'alert_threshold' => 0, 'unit_price' => 1000,
    ]);

    // La route réelle : `stocks.rebase` (« inventaire physique » des calibres).
    $this->actingAs($this->adminUser)
        ->post(route('stocks.rebase'), ['stocks' => [$stock->id => 150]])
        ->assertRedirect();

    expect(adjustmentAlertText($this->adminUser->id))->toContain('AJUSTEMENT STOCK');
});

test('une BAISSE est signalée plus fort qu’une hausse', function () {
    // Une démarque est le signal ; un excédent est presque toujours une erreur de
    // saisie. L'alerte porte déjà cette distinction (émoji et sens) : on vérifie
    // qu'elle survit aux deux nouveaux chemins.
    $stock = adjustableStock($this->farm->id, 100);

    app(\App\Actions\Stock\UpdateStockAction::class)->execute($stock, [
        'item_name' => $stock->item_name, 'unit' => 'KG', 'alert_threshold' => 10,
        'current_quantity' => 60, 'unit_price' => 5000,
    ], $this->adminUser->id);

    expect(adjustmentAlertText($this->adminUser->id))->toContain('🚨');
});

test('les TROIS chemins d’ajustement alertent désormais', function () {
    // Le garde-fou de forme : un quatrième chemin qui écrirait un mouvement
    // `adjustment` sans alerter rouvrirait exactement le même trou.
    $sources = [
        'app/Actions/Stock/CreateStockAdjustment.php',
        'app/Actions/Stock/UpdateStockAction.php',
        'app/Http/Controllers/EggProductionController.php',
    ];

    $muets = [];

    foreach ($sources as $relative) {
        $source = file_get_contents(base_path($relative));

        if (str_contains($source, "'type'     => 'adjustment'") && ! str_contains($source, 'alertStockAdjustment')) {
            $muets[] = basename($relative);
        }
    }

    expect($muets)->toBe([], "Chemin(s) écrivant un ajustement SANS alerter :\n  " . implode("\n  ", $muets));
});

test('une alerte en échec n’empêche PAS la correction', function () {
    // On n'entrave pas le geste : l'inventaire physique est l'outil légitime pour
    // corriger un niveau, et un canal muet ne doit pas le bloquer.
    app()->bind(\App\Services\NotificationHub::class, fn () => throw new \RuntimeException('canal mort'));

    $stock = adjustableStock($this->farm->id, 100);

    app(\App\Actions\Stock\UpdateStockAction::class)->execute($stock, [
        'item_name' => $stock->item_name, 'unit' => 'KG', 'alert_threshold' => 10,
        'current_quantity' => 60, 'unit_price' => 5000,
    ], $this->adminUser->id);

    expect((float) $stock->fresh()->current_quantity)->toBe(60.0)
        // Et la traçabilité est conservée, elle aussi.
        ->and(StockMovement::withoutGlobalScopes()->where('stock_id', $stock->id)->where('type', 'adjustment')->count())
        ->toBe(1);
});
