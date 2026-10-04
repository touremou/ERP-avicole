<?php

use App\Console\Commands\ResetTestData;
use App\Models\Batch;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\TreasuryAccount;
use App\Services\DocumentNumberingService;
use App\Support\DataReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * REMISE À ZÉRO DES DONNÉES DE TEST — demandée par l'exploitant (2026-10-04).
 *
 * Une installation sur laquelle on s'est formé doit repartir propre le jour où
 * elle passe en service. L'application ne peut pas distinguer une vente de
 * test d'une vraie : on vide donc les MOUVEMENTS et on garde la configuration
 * et les référentiels choisis par l'exploitant — bâtiments, employés, clients,
 * fournisseurs, articles de stock.
 *
 * Le piège, c'est celui que tout cet audit poursuit : une valeur DÉRIVÉE gardée
 * alors que ce qui l'expliquait est parti. Un stock sans mouvements qui garde sa
 * quantité, un client sans ventes qui garde son solde, un employé dont les
 * congés de test ont disparu mais pas les jours qu'ils avaient prélevés. Chaque
 * règle de remise d'aplomb est éprouvée ici.
 */

beforeEach(function () {
    $this->setUpRbac();
    Setting::set('general.company_name', 'Ferme du Patron');

    // Sauvegarde préalable : réussie par défaut, remplacée dans le test d'échec.
    app()->instance(ResetTestData::SAUVEGARDE, fn (): bool => true);
});

/** Une ferme qui s'est formée : des référentiels réels, des mouvements de test. */
function fermeApresFormation(int $farm, int $adminId): array
{
    $poulailler = Building::factory()->create(['farm_id' => $farm, 'name' => 'Poulailler 1', 'status' => 'Occupé', 'capacity' => 2000]);
    $atelier    = Building::factory()->create(['farm_id' => $farm, 'name' => 'Atelier', 'status' => 'Maintenance', 'capacity' => 10]);

    Batch::factory()->create(['farm_id' => $farm, 'building_id' => $poulailler->id, 'status' => 'Actif']);

    $stock = Stock::factory()->create(['farm_id' => $farm, 'item_name' => 'Aliment ponte', 'current_quantity' => 120]);
    $matiere = \App\Models\RawMaterial::factory()->create(['stock_qty' => 850]);
    $desinfecte = Building::factory()->create(['farm_id' => $farm, 'name' => 'Poulailler 2', 'status' => 'En désinfection',
        'capacity' => 2000, 'disinfection_started_at' => now()->subDays(3)]);

    $client = DB::table('clients')->insertGetId([
        'farm_id' => $farm, 'client_id' => 'CLI-1', 'name' => 'Boutique Kindia',
        'type' => 'entreprise', 'category' => 'detaillant', 'balance' => 500_000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $caisse = TreasuryAccount::create([
        'farm_id' => $farm, 'name' => 'Caisse', 'type' => 'caisse',
        'opening_balance' => 100_000, 'current_balance' => 340_000, 'is_active' => true,
    ]);

    Expense::factory()->create(['farm_id' => $farm, 'reference' => 'DEP-00007', 'user_id' => $adminId]);

    // Un employé à 20 jours, qui a pris 5 jours de congé annuel APPROUVÉ (donc
    // prélevés : 15), plus une DEMANDE de 3 jours jamais approuvée (rien de
    // prélevé), et qui est « en congé » à cause du premier.
    $employe = Employee::factory()->create(['farm_id' => $farm, 'status' => 'Congé', 'annual_leave_balance' => 15]);
    foreach ([['approuve', 5], ['demande', 3]] as [$statut, $jours]) {
        DB::table('employee_leaves')->insert([
            'farm_id' => $farm, 'employee_id' => $employe->id, 'type' => 'conge_annuel',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays($jours)->toDateString(),
            'days_count' => $jours, 'status' => $statut, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $parcelle = DB::table('plots')->insertGetId(['farm_id' => $farm, 'name' => 'Champ Nord', 'status' => 'en_culture', 'created_at' => now(), 'updated_at' => now()]);
    $couveuse = DB::table('incubators')->insertGetId(['farm_id' => $farm, 'name' => 'Couveuse 1', 'status' => 'Occupé', 'created_at' => now(), 'updated_at' => now()]);

    return compact('poulailler', 'atelier', 'stock', 'matiere', 'desinfecte', 'client', 'caisse', 'employe', 'parcelle', 'couveuse');
}

/** Toutes les tables, ligne par ligne : la preuve qu'une simulation n'a rien écrit. */
function releveDeLaBase(): array
{
    return collect(DataReset::tables())->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
}

function remiseAZero(object $test, string $nom = 'Ferme du Patron')
{
    return $test->artisan('avismart:reset-test-data', ['--force' => true, '--confirmer' => $nom]);
}

test('la SIMULATION n’écrit rien — et dit ce qui partirait', function () {
    fermeApresFormation($this->farm->id, $this->adminUser->id);
    $avant = releveDeLaBase();

    $this->artisan('avismart:reset-test-data')
        ->expectsOutputToContain('Simulation — rien n’a été modifié')
        ->assertSuccessful();

    expect(releveDeLaBase())->toBe($avant);
});

test('un NOM d’entreprise faux est refusé, et rien ne part', function () {
    fermeApresFormation($this->farm->id, $this->adminUser->id);
    $avant = releveDeLaBase();

    remiseAZero($this, 'Une autre ferme')->assertFailed();

    expect(releveDeLaBase())->toBe($avant);
});

test('une SAUVEGARDE en échec arrête tout, et rien ne part', function () {
    /*
     * LE garde-fou d'un geste irréversible : sans sauvegarde prise juste avant,
     * il n'y a pas de retour possible.
     */
    fermeApresFormation($this->farm->id, $this->adminUser->id);
    app()->instance(ResetTestData::SAUVEGARDE, fn (): bool => false);
    $avant = releveDeLaBase();

    remiseAZero($this)->assertFailed();

    expect(releveDeLaBase())->toBe($avant);
});

test('une table NON CLASSÉE fait refuser la commande', function () {
    /*
     * Une table ajoutée demain et oubliée dans le classement ne doit être ni
     * effacée par surprise, ni gardée en silence : la commande s'arrête.
     */
    fermeApresFormation($this->farm->id, $this->adminUser->id);
    Schema::create('zz_table_oubliee', fn ($t) => $t->id());
    $avant = releveDeLaBase();

    remiseAZero($this)->expectsOutputToContain('zz_table_oubliee')->assertFailed();

    expect(releveDeLaBase())->toBe($avant);
});

test('les MOUVEMENTS sont vidés', function () {
    fermeApresFormation($this->farm->id, $this->adminUser->id);

    remiseAZero($this)->assertSuccessful();

    foreach (['batches', 'expenses', 'employee_leaves'] as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} n’a pas été vidée");
    }
});

test('les RÉFÉRENTIELS et la configuration sont gardés', function () {
    $f = fermeApresFormation($this->farm->id, $this->adminUser->id);
    $comptes = DB::table('users')->count();
    $reglages = DB::table('settings')->count();

    remiseAZero($this)->assertSuccessful();

    expect(Building::whereKey([$f['poulailler']->id, $f['atelier']->id])->count())->toBe(2)
        ->and(Employee::whereKey($f['employe']->id)->exists())->toBeTrue()
        ->and(DB::table('clients')->where('id', $f['client'])->exists())->toBeTrue()
        ->and(Stock::whereKey($f['stock']->id)->exists())->toBeTrue()
        ->and(DB::table('users')->count())->toBe($comptes)
        ->and(DB::table('settings')->count())->toBe($reglages);
});

test('ce qui DÉCOULAIT des mouvements est remis d’aplomb', function () {
    /*
     * Le cœur. Chaque valeur ci-dessous était le produit des mouvements de
     * test ; les garder sans eux, c'est garder un chiffre que plus rien
     * n'explique.
     */
    $f = fermeApresFormation($this->farm->id, $this->adminUser->id);

    remiseAZero($this)->assertSuccessful();

    expect((float) $f['stock']->fresh()->current_quantity)->toBe(0.0)                       // inventaire d'ouverture à saisir
        ->and((float) DB::table('clients')->where('id', $f['client'])->value('balance'))->toBe(0.0)
        ->and((float) $f['caisse']->fresh()->current_balance)->toBe(100_000.0)             // retour à l'ouverture
        ->and($f['poulailler']->fresh()->status)->toBe('Vide')                              // plus aucun lot
        ->and($f['atelier']->fresh()->status)->toBe('Maintenance')                          // décidé par un humain : gardé
        ->and((float) $f['matiere']->fresh()->stock_qty)->toBe(0.0)                         // matières premières aussi
        ->and($f['desinfecte']->fresh()->status)->toBe('Vide')
        ->and($f['desinfecte']->fresh()->disinfection_started_at)->toBeNull()
        ->and(DB::table('plots')->where('id', $f['parcelle'])->value('status'))->toBe('disponible')
        ->and(DB::table('incubators')->where('id', $f['couveuse'])->value('status'))->toBe('Disponible');
});

test('les jours de congé prélevés sont RENDUS au jour près', function () {
    /*
     * 15 jours restants après un congé APPROUVÉ de 5 jours → 20. La DEMANDE de
     * 3 jours, jamais approuvée, n'avait rien prélevé : elle ne rend rien. La
     * rendre aussi aurait fabriqué 3 jours de congé.
     */
    $f = fermeApresFormation($this->farm->id, $this->adminUser->id);

    remiseAZero($this)->assertSuccessful();

    expect((float) $f['employe']->fresh()->annual_leave_balance)->toBe(20.0)
        ->and($f['employe']->fresh()->status)->toBe('Actif');
});

test('la NUMÉROTATION repart de 1', function () {
    /*
     * Voulu avant la mise en service : la première vraie dépense est la n° 1.
     * C'est aussi pourquoi la commande ne se lance jamais après avoir émis de
     * vrais documents — et le dit.
     */
    fermeApresFormation($this->farm->id, $this->adminUser->id);
    expect(DocumentNumberingService::generate('expense'))->toEndWith('00008');

    remiseAZero($this)->assertSuccessful();

    expect(DocumentNumberingService::generate('expense'))->toEndWith('00001');
});

test('chaque table du schéma est classée UNE fois — dérivé, pas listé', function () {
    /*
     * La garde du classement. Elle lit le schéma réel : une table ajoutée par
     * une migration future la fera échouer tant qu'elle n'est pas classée.
     */
    $classees = array_merge(DataReset::CONFIGURATION, DataReset::REFERENTIELS, DataReset::MOUVEMENTS);

    expect(DataReset::nonClassees())->toBe([])
        ->and(array_values(array_diff($classees, DataReset::tables())))->toBe([])
        ->and(count($classees))->toBe(count(array_unique($classees)));
});

test('aucune table GARDÉE ne pointe vers une table VIDÉE', function () {
    /*
     * Sinon, une ligne gardée garderait une référence vers une ligne effacée.
     * Lu sur les clés étrangères réelles du schéma.
     */
    $gardees = array_merge(DataReset::CONFIGURATION, DataReset::REFERENTIELS);
    $piegees = [];

    foreach ($gardees as $table) {
        foreach (Schema::getForeignKeys($table) as $fk) {
            if (in_array($fk['foreign_table'], DataReset::MOUVEMENTS, true)) {
                $piegees[] = "{$table}." . implode(',', $fk['columns']) . " → {$fk['foreign_table']}";
            }
        }
    }

    expect($piegees)->toBe([]);
});
