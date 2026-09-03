<?php

use App\Models\EggProduction;
use App\Models\Farm;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA SYNCHRO NOCTURNE DES ŒUFS REMETTAIT EN STOCK CE QUI ÉTAIT DÉJÀ VENDU.
 *
 * `stocks:sync` est planifiée chaque nuit (Schedule::command('stocks:sync')->daily()).
 * Sa partie « œufs » ÉCRASE stocks.current_quantity avec la somme de TOUTE la
 * production enregistrée :
 *
 *     $stock->update(['current_quantity' => EggProduction::sum('grade_m')]);
 *
 * Or c'est la MÊME colonne que ValidateSale décrémente à chaque vente. Toute sortie
 * — vente, casse, mouvement de sortie — était donc effacée du niveau de stock la
 * nuit suivante, et le magasin affichait des œufs partis depuis des semaines. Chaque
 * nuit, l'écart se reconstituait tout seul.
 *
 * DEUX AGGRAVATIONS, propres au contexte de cette exploitation :
 *
 *   • AUCUNE PORTÉE PAR SITE. Le scope ferme ne s'applique que si une ferme
 *     courante est en session (cf. BelongsToFarm) ; une commande artisan n'en a
 *     pas. `EggProduction::sum()` additionne donc Kindia ET Kérouané, et
 *     `Stock::where('item_name', 'M')->first()` verse ce total au PREMIER site
 *     trouvé — le second n'étant jamais mis à jour du tout.
 *
 *   • TROIS DÉCLARATIONS de la même synchro : cette commande, l'action appelée par
 *     la route d'administration `stocks.syncAll`, et BatchQuantityService pour les
 *     effectifs. Elles ne calculaient pas la même chose : l'action ignorait la
 *     conversion en alvéoles que la commande appliquait, donc les deux chemins
 *     rendaient des quantités différentes pour « Cassé » et « Anomalie ».
 *
 * ─── LA RÈGLE RETENUE ───
 *
 * On ne recalcule plus le niveau des œufs, on le CONSTATE. `current_quantity` est
 * tenue de façon transactionnelle à chaque mouvement (syncMovement, appelé par le
 * calibrage comme par la vente) : c'est elle la source de vérité, et la recalculer
 * depuis une autre source ne peut qu'y introduire de l'erreur.
 *
 * Le registre ne permet pas non plus d'arbitrer : un mouvement `adjustment`
 * enregistre `abs($nouveau - $ancien)`, donc SANS SIGNE — un ajustement de 5 peut
 * valoir +5 ou −5. Additionner les mouvements ne rend pas un chiffre décidable, et
 * le prétendre serait refaire la même faute d'un cran plus loin.
 *
 * La commande constate donc, et renvoie vers l'outil qui sait décider : l'inventaire
 * physique, qui écrit un ajustement en règle.
 */

beforeEach(function () {
    $this->setUpRbac();

    // Lot COHÉRENT au départ (effectif = initial, aucun pointage) : sans cela le
    // code de sortie de la commande serait dicté par un écart d'effectif fortuit
    // de la fabrique, et chaque test éprouverait deux choses à la fois.
    $this->batch = \App\Models\Batch::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'initial_quantity' => 1000, 'current_quantity' => 1000,
    ]);
});

/** Mouvement de stock. Ferme et utilisateur en paramètres : $this est hors de portée ici. */
function movement(Stock $stock, int $farmId, int $userId, string $type, float $qty, string $notes = ''): void
{
    StockMovement::withoutGlobalScopes()->create([
        'stock_id' => $stock->id, 'farm_id' => $farmId, 'type' => $type,
        'quantity' => $qty, 'notes' => $notes, 'user_id' => $userId,
    ]);
}

/** Article d'œufs d'un calibre donné, avec la quantité voulue en magasin. */
function eggStock(int $farmId, string $calibre, float $qty, string $unit = 'Alvéole'): Stock
{
    return Stock::withoutGlobalScopes()->create([
        'farm_id'          => $farmId,
        'item_name'        => $calibre,
        'category'         => Stock::CAT_OEUFS,
        'unit'             => $unit,
        'current_quantity' => $qty,
        'alert_threshold'  => 0,
        'unit_price'       => 1000,
    ]);
}

test('une SORTIE de stock n’est plus effacée par la synchro', function () {
    // LE défaut, dans sa forme exacte. 100 alvéoles calibrées (entrée), 30 vendues
    // (sortie) → il en reste 70. Avant correction, la commande remettait 100,
    // mesuré. Même avec --force, plus rien ne touche ce niveau.
    EggProduction::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->batch->id,
        'production_date' => now()->subDays(3)->toDateString(),
        'total_eggs_collected' => 3000, 'grade_m' => 100, 'is_graded' => true,
    ]);

    $stock = eggStock($this->farm->id, 'M', 70);

    movement($stock, $this->farm->id, $this->adminUser->id, 'in', 100);
    movement($stock, $this->farm->id, $this->adminUser->id, 'out', 30, 'Vente calibre M');

    $this->artisan('stocks:sync --force');

    expect((float) $stock->fresh()->current_quantity)->toBe(70.0);
});

test('un niveau qui s’accorde avec son registre est déclaré au vert', function () {
    // Le pendant : la commande doit rester utile. 100 entrées, 30 sorties, niveau
    // 70 — rien à signaler, et le code de sortie le dit.
    $stock = eggStock($this->farm->id, 'L', 70);

    movement($stock, $this->farm->id, $this->adminUser->id, 'in', 100);
    movement($stock, $this->farm->id, $this->adminUser->id, 'out', 30);

    $this->artisan('stocks:sync --farm=' . $this->farm->id)
        ->expectsOutputToContain('s’accorde avec son registre')
        ->assertExitCode(0);
});

test('un écart NET est signalé, et le code de sortie n’est pas nul', function () {
    // 100 entrées, 30 sorties, mais le magasin affiche 12 : l'écart est réel et
    // aucun ajustement d'inventaire ne l'explique. On le nomme.
    $stock = eggStock($this->farm->id, 'XL', 12);

    movement($stock, $this->farm->id, $this->adminUser->id, 'in', 100);
    movement($stock, $this->farm->id, $this->adminUser->id, 'out', 30);

    $this->artisan('stocks:sync --farm=' . $this->farm->id)
        ->expectsOutputToContain('Écart net')
        ->assertExitCode(1);
});

test('un écart explicable par un ajustement est présenté comme NON tranchable', function () {
    // Un ajustement d'inventaire enregistre une valeur SANS SIGNE : le registre ne
    // permet pas de savoir s'il ajoutait ou retirait. Présenter l'écart comme une
    // anomalie certaine serait refaire la même faute — décider à la place de
    // l'exploitation sur une base qui ne le permet pas.
    $stock = eggStock($this->farm->id, 'S', 55);

    movement($stock, $this->farm->id, $this->adminUser->id, 'in', 100);
    movement($stock, $this->farm->id, $this->adminUser->id, 'out', 30);
    movement($stock, $this->farm->id, $this->adminUser->id, 'adjustment', 15, 'Inventaire physique : 70 → 55');

    $this->artisan('stocks:sync --farm=' . $this->farm->id)
        ->expectsOutputToContain('n’est pas enregistré')
        ->assertExitCode(0);
});

test('chaque site est traité séparément', function () {
    // Le scope ferme ne s'applique pas en console faute de session : la somme
    // additionnait Kindia ET Kérouané, puis versait le total au premier article
    // trouvé — le second n'étant jamais touché. Les deux chiffres étaient faux.
    $autre = Farm::create(['code' => 'FT-002', 'name' => 'Kérouané', 'is_active' => true]);

    $ici   = eggStock($this->farm->id, 'M', 40);
    $labas = eggStock($autre->id, 'M', 500);

    movement($ici, $this->farm->id, $this->adminUser->id, 'in', 40);
    movement($labas, $autre->id, $this->adminUser->id, 'in', 500);

    // Sans --farm : les deux sites sont parcourus, chacun avec SA propre portée.
    $this->artisan('stocks:sync')->expectsOutputToContain('Kérouané');

    // Aucun des deux niveaux n'a bougé, et aucun n'a reçu la production de l'autre.
    expect((float) $ici->fresh()->current_quantity)->toBe(40.0)
        ->and((float) $labas->fresh()->current_quantity)->toBe(500.0);
});

test('sans --force, RIEN n’est écrit — pas même les effectifs', function () {
    // Une réconciliation de quantités ne s'applique pas parce qu'on a lancé la
    // commande pour voir. Même règle que feed:recompute-costs.
    $this->batch->update(['initial_quantity' => 1000, 'current_quantity' => 400]);

    \App\Models\DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->batch->id,
        'user_id' => $this->adminUser->id, 'check_date' => now()->subDay()->toDateString(),
        'mortality' => 10,
    ]);

    $this->artisan('stocks:sync');

    // 1000 - 10 = 990 serait la valeur rectifiée ; sans --force elle n'est pas écrite.
    expect((int) $this->batch->fresh()->current_quantity)->not->toBe(990);
});

test('avec --force, les EFFECTIFS sont rectifiés', function () {
    /*
     * ─── CE TEST AFFIRMAIT UN AUTRE DÉFAUT ───
     *
     * Son jeu de données était « effectif 400, dix morts pointées » et attendait
     * une remontée à 990 — la RÉSURRECTION des sujets vendus. Le calcul des
     * pointages ignore les ventes : un effectif de 400 pour 1 000 sujets reçus
     * s'explique normalement par 590 sujets vendus ou expédiés.
     *
     * Deux tests portaient ce même jeu de données fautif — celui-ci et
     * `RewriteCommandSafetyTest`. Tous deux voulaient prouver que `--force`
     * écrit vraiment, et tous deux ont choisi, sans le voir, un cas où la
     * correction se trouve être une remontée. C'est pourquoi le défaut a
     * survécu : la suite le protégeait à deux endroits.
     *
     * On garde l'intention avec la dérive que la commande a le DROIT de
     * corriger : un effectif trop HAUT, une mortalité pointée mais jamais
     * décomptée.
     */
    $this->batch->update(['initial_quantity' => 1000, 'current_quantity' => 1000]);

    \App\Models\DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->batch->id,
        'user_id' => $this->adminUser->id, 'check_date' => now()->subDay()->toDateString(),
        'mortality' => 10,
    ]);

    $this->artisan('stocks:sync --force');

    expect((int) $this->batch->fresh()->current_quantity)->toBe(990);
});

test('la commande n’est PLUS planifiée chaque nuit', function () {
    // La cause de la corruption n'était pas seulement le calcul : c'était de
    // l'appliquer automatiquement, sans que personne ne lise le résultat. Une
    // réconciliation de quantités comptables se lance à la main, après lecture.
    $source = file_get_contents(base_path('routes/console.php'));

    expect($source)->not->toContain("Schedule::command('stocks:sync')");
});

test('des récoltes SANS article de calibre sont signalées, pas annoncées « succès »', function () {
    // La version d'origine écrivait « Stock 'M' introuvable » cinq fois puis
    // concluait « ✅ Synchronisation terminée avec succès ». Si le site récolte des
    // œufs, le calibrage n'a nulle part où entrer sa production : c'est un blocage.
    EggProduction::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->batch->id,
        'production_date' => now()->subDay()->toDateString(),
        'total_eggs_collected' => 1000, 'grade_m' => 30, 'is_graded' => true,
    ]);

    $this->artisan('stocks:sync --farm=' . $this->farm->id)
        ->expectsOutputToContain('nulle part où entrer sa production')
        ->assertExitCode(1);
});

test('un site SANS récolte d’œufs n’est pas signalé pour autant', function () {
    // Un site qui n'élève que du poulet de chair n'a aucune raison d'avoir des
    // articles de calibre. Le signaler apprendrait à ignorer le rapport.
    $this->artisan('stocks:sync --farm=' . $this->farm->id)
        ->expectsOutputToContain('normal')
        ->assertExitCode(0);
});

test('la commande ne porte PLUS sa propre formule d’effectif', function () {
    // Elle reportait la formule de BatchQuantityService pour son compte, avec une
    // différence lourde : elle écrivait par $batch->update(), ce qui réveille le
    // BatchObserver — donc l'alerte de mortalité cumulée, à minuit, pour une simple
    // réconciliation. Le service écrit en direct précisément pour l'éviter.
    $source = file_get_contents(app_path('Console/Commands/SyncBatchStocks.php'));

    expect($source)->toContain('BatchQuantityService')
        ->and($source)->not->toContain('qty_quarantine_in')
        ->and($source)->not->toContain("EggProduction::sum");
});

test('la route d’administration n’écrase plus le niveau des œufs', function () {
    // Elle posait niveau = somme de TOUTE la production, donc remettait en stock ce
    // qui était vendu. Aucun écran ne l'appelle aujourd'hui, mais elle portait le
    // même calcul que la tâche de nuit — et celle-là tournait vraiment.
    EggProduction::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->batch->id,
        'production_date' => now()->subDay()->toDateString(),
        'total_eggs_collected' => 1000, 'grade_m' => 100, 'is_graded' => true,
    ]);

    $stock = eggStock($this->farm->id, 'M', 70);
    movement($stock, $this->farm->id, $this->adminUser->id, 'in', 100);
    movement($stock, $this->farm->id, $this->adminUser->id, 'out', 30);

    $this->actingAs($this->adminUser)->post(route('stocks.syncAll'));

    expect((float) $stock->fresh()->current_quantity)->toBe(70.0);
});

test('la route NOMME l’écart au lieu de le corriger en silence', function () {
    $stock = eggStock($this->farm->id, 'L', 12);
    movement($stock, $this->farm->id, $this->adminUser->id, 'in', 100);
    movement($stock, $this->farm->id, $this->adminUser->id, 'out', 30);

    $this->actingAs($this->adminUser)->post(route('stocks.syncAll'));

    expect((string) session('error'))->toContain('L')
        ->and((string) session('error'))->toContain('inventaire physique');
});
