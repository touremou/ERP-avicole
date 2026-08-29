<?php

use App\Actions\MillProduction\CompleteMillProduction;
use App\Models\Formula;
use App\Models\FormulaItem;
use App\Models\MillMachine;
use App\Models\MillProduction;
use App\Models\RawMaterial;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE SEULE MATIÈRE SANS PRIX SUFFISAIT À FAUSSER TOUT L'ÉLEVAGE.
 *
 * `CompleteMillProduction` refusait une clôture dont le coût de revient tombait
 * à ZÉRO — donc seulement si TOUTES les matières manquaient de prix. Une formule
 * dont une seule ligne n'était pas tarifée passait sans un mot.
 *
 * Mesuré : maïs 70 % à 3 000 GNF/kg, tourteau de soja 30 % sans prix. L'aliment
 * entrait au silo à 2 100 GNF/kg au lieu de 3 600 — 42 % de sous-évaluation, sur
 * une clôture ACCEPTÉE.
 *
 * ─── POURQUOI C'EST LA RACINE ───
 *
 * Ce coût fixe le CMP du silo d'aliment fini. Il devient donc le
 * `feed_unit_cost` figé à chaque pointage de consommation, donc le `feed_cogs`
 * de chaque bande qui en mange, donc sa marge, sa clôture, le coût de sa
 * campagne et la ligne « Aliment » du compte de résultat.
 *
 * Une erreur ici se propage à tout ce qui chiffre l'élevage — et toujours dans
 * le même sens : elle flatte.
 *
 * ─── ON REFUSE, ON N'ESTIME PAS ───
 *
 * Règle industrielle : un mouvement d'inventaire ne se poste pas à un coût qu'on
 * sait faux. Inventer un prix de repli rendrait le total « complet », donc
 * invisible, donc jamais corrigé. Le message nomme les matières à tarifer et
 * l'écran où le faire — le refus oriente, il ne bloque pas.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->machine = MillMachine::create([
        'farm_id' => $this->farm->id, 'name' => 'Broyeur A', 'type' => 'Broyeur',
        'capacity_per_hour' => 500, 'maintenance_interval_hours' => 100,
        'total_hours_run' => 0, 'status' => 'Opérationnel',
    ]);

    $this->formule = Formula::create([
        'farm_id' => $this->farm->id, 'code' => 'F-TEST', 'name' => 'Ponte Production',
        'target_type' => 'Ponte', 'poultry_type' => 'Ponte',
        'total_batch_weight' => 1000, 'is_locked' => false, 'is_active' => true,
    ]);

    Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'Ponte Production',
        'category' => 'conso', 'unit' => 'KG',
        'current_quantity' => 0, 'alert_threshold' => 0, 'unit_price' => 0,
    ]);
});

/** Une matière première au prix voulu, en stock abondant. */
function matiere(int $farmId, string $nom, float $prix): RawMaterial
{
    return RawMaterial::create([
        'farm_id'   => $farmId,
        'name'      => $nom,
        'unit'      => 'KG',
        'stock_qty' => 100_000,
        'unit_cost' => $prix,
    ]);
}

/** L'ordre de production, prêt à clôturer. */
function ordreDeProduction(int $farmId, Formula $formule, MillMachine $machine, int $operateurId): MillProduction
{
    $op = MillProduction::create([
        'farm_id'           => $farmId,
        'batch_number'      => 'OP-' . uniqid(),
        'formula_id'        => $formule->id,
        'machine_id'        => $machine->id,
        'quantity_produced' => 1000,
        'operator_id'       => $operateurId,
        'status'            => 'Planifié',
    ]);

    $op->machines()->attach([$machine->id => ['snapshot_capacity_per_hour' => 500]]);

    return $op;
}

test('UNE SEULE matière sans prix fait refuser la clôture', function () {
    /*
     * LE défaut. La formule est majoritairement tarifée : le coût total est
     * positif, donc l'ancien garde-fou se taisait.
     */
    $mais = matiere($this->farm->id, 'Maïs', 3_000);
    $soja = matiere($this->farm->id, 'Tourteau de soja', 0);      // ← sans prix

    FormulaItem::create(['formula_id' => $this->formule->id, 'raw_material_id' => $mais->id, 'percentage' => 70]);
    FormulaItem::create(['formula_id' => $this->formule->id, 'raw_material_id' => $soja->id, 'percentage' => 30]);

    $op = ordreDeProduction($this->farm->id, $this->formule, $this->machine, $this->adminUser->id);

    expect(fn () => app(CompleteMillProduction::class)->execute($op, 1000, 'Ponte Production'))
        ->toThrow(\DomainException::class, 'Tourteau de soja');
});

test('le refus ANNULE le déstockage des matières', function () {
    /*
     * La garde est posée APRÈS la consommation, dans la même transaction : le
     * rejet doit tout rendre. Sans cela, un refus coûterait le stock de matières
     * premières d'une production qui n'a jamais eu lieu.
     */
    $mais = matiere($this->farm->id, 'Maïs', 3_000);
    $soja = matiere($this->farm->id, 'Tourteau de soja', 0);

    FormulaItem::create(['formula_id' => $this->formule->id, 'raw_material_id' => $mais->id, 'percentage' => 70]);
    FormulaItem::create(['formula_id' => $this->formule->id, 'raw_material_id' => $soja->id, 'percentage' => 30]);

    $op = ordreDeProduction($this->farm->id, $this->formule, $this->machine, $this->adminUser->id);

    try {
        app(CompleteMillProduction::class)->execute($op, 1000, 'Ponte Production');
    } catch (\DomainException) {
        // attendu
    }

    expect((float) $mais->fresh()->stock_qty)->toBe(100_000.0)
        ->and((float) $soja->fresh()->stock_qty)->toBe(100_000.0);
});

test('un ingrédient INVISIBLE depuis le site fait refuser aussi', function () {
    /*
     * L'orphelin, par le chemin qui le produit RÉELLEMENT.
     *
     * Une matière première SUPPRIMÉE ne peut pas exister : la clé étrangère de
     * `formula_items` l'interdit — vérifié, la base rejette le DELETE. Le seul
     * chemin est donc le CLOISONNEMENT : `RawMaterial` porte `BelongsToFarm` et
     * la relation ne lève pas ce filtre. Une matière rattachée à un autre site
     * rend l'ingrédient invisible depuis celui-ci.
     *
     * `RecordProductionConsumptionAction` le journalise alors en
     * « Ingrédient orphelin » et PASSE : sa part n'est ni déstockée ni valorisée.
     * Le coût de revient est sous-évalué, et le journal est la seule trace.
     */
    $autreSite = \App\Models\Farm::create([
        'code' => 'FT-KER', 'name' => 'Kérouané', 'is_active' => true,
    ]);

    $mais = matiere($this->farm->id, 'Maïs', 3_000);

    $ailleurs = \App\Models\RawMaterial::withoutGlobalScopes()->create([
        'farm_id'   => $autreSite->id,          // ← invisible depuis notre site
        'name'      => 'Prémix',
        'unit'      => 'KG',
        'stock_qty' => 10_000,
        'unit_cost' => 8_000,
    ]);

    FormulaItem::create(['formula_id' => $this->formule->id, 'raw_material_id' => $mais->id, 'percentage' => 90]);
    FormulaItem::create(['formula_id' => $this->formule->id, 'raw_material_id' => $ailleurs->id, 'percentage' => 10]);

    $op = ordreDeProduction($this->farm->id, $this->formule, $this->machine, $this->adminUser->id);

    expect(fn () => app(CompleteMillProduction::class)->execute($op->fresh(), 1000, 'Ponte Production'))
        ->toThrow(\DomainException::class);
});

test('une formule ENTIÈREMENT tarifée se clôture normalement — non-régression', function () {
    /*
     * LA borne. Refuser plus largement ne doit pas empêcher la production
     * ordinaire : c'est le cas courant, et il doit passer au franc près.
     * 70 % × 3 000 + 30 % × 5 000 = 3 600 GNF/kg.
     */
    $mais = matiere($this->farm->id, 'Maïs', 3_000);
    $soja = matiere($this->farm->id, 'Tourteau de soja', 5_000);

    FormulaItem::create(['formula_id' => $this->formule->id, 'raw_material_id' => $mais->id, 'percentage' => 70]);
    FormulaItem::create(['formula_id' => $this->formule->id, 'raw_material_id' => $soja->id, 'percentage' => 30]);

    $op = ordreDeProduction($this->farm->id, $this->formule, $this->machine, $this->adminUser->id);

    app(CompleteMillProduction::class)->execute($op, 1000, 'Ponte Production');

    expect((float) $op->fresh()->real_cost_per_kg)->toBe(3_600.0);
});

test('le coût JUSTE arrive au silo, pas une estimation', function () {
    /*
     * Le bout de la chaîne : c'est ce CMP qui deviendra le feed_unit_cost figé
     * de chaque pointage, donc le feed_cogs de chaque bande.
     */
    $mais = matiere($this->farm->id, 'Maïs', 3_000);
    $soja = matiere($this->farm->id, 'Tourteau de soja', 5_000);

    FormulaItem::create(['formula_id' => $this->formule->id, 'raw_material_id' => $mais->id, 'percentage' => 70]);
    FormulaItem::create(['formula_id' => $this->formule->id, 'raw_material_id' => $soja->id, 'percentage' => 30]);

    $op = ordreDeProduction($this->farm->id, $this->formule, $this->machine, $this->adminUser->id);

    app(CompleteMillProduction::class)->execute($op, 1000, 'Ponte Production');

    $silo = Stock::where('item_name', 'Ponte Production')->first();

    expect((float) $silo->current_quantity)->toBe(1_000.0)
        ->and((float) $silo->last_unit_price)->toBe(3_600.0);
});
