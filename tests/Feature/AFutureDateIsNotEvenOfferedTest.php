<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * UNE DATE FUTURE NE DEVAIT MÊME PAS POUVOIR SE CHOISIR.
 *
 * Signalé par l'exploitation, capture à l'appui : la création d'une bande
 * datée de demain partait, et le serveur la renvoyait — « La date d'arrivée ne
 * peut pas être dans le futur ». L'alerte est juste ; mais le calendrier
 * offrait la date qu'on allait refuser. Même défaut que les précédents : une
 * règle (pas de date future pour un fait constaté) tenue à une porte — le
 * serveur — et ignorée à l'autre — l'écran.
 *
 * En vérifiant chaque sélecteur, la même divergence est apparue côté serveur :
 * la synchronisation terrain refusait une récolte, un apport, un relevé météo,
 * une session d'abattage ou une réception datés de demain ; le formulaire web
 * les acceptait. La modification d'une bande, d'un achat d'aliment ou d'un
 * soin ne bornait pas non plus ce que la création bornait.
 *
 * Le serveur reste l'autorité ; l'écran ne fait que ne plus proposer ce qu'il
 * refuserait.
 */

/** Formulaires qui enregistrent un FAIT constaté : la date ne peut pas être future. */
const SAISIES_DATEES_DU_PASSE = [
    'batches/close' => ['closing_date'],
    'batches/create' => ['arrival_date', 'birth_date'],
    'batches/edit' => ['arrival_date', 'birth_date'],
    'cultures/cycles/harvests/create' => ['harvest_date'],
    'cultures/cycles/harvests/edit' => ['harvest_date'],
    'cultures/cycles/inputs/create' => ['input_date'],
    'cultures/cycles/inputs/edit' => ['input_date'],
    'cultures/dashboard' => ['reading_date'],
    'cultures/transformations/create' => ['production_date'],
    'cultures/transformations/edit' => ['production_date'],
    'cultures/weather/edit' => ['reading_date'],
    'cultures/weather/index' => ['reading_date'],
    'daily-checks/create' => ['check_date'],
    'dispatches/reception' => ['reception_date'],
    'expenses/create' => ['expense_date'],
    'expenses/edit' => ['expense_date'],
    'feed-purchases/edit' => ['purchase_date'],
    'health/create' => ['intervention_date'],
    'health/edit' => ['intervention_date'],
    'incubations/index' => ['start_date'],
    'incubators/index' => ['maintenance_date'],
    'milk-productions/create' => ['production_date'],
    'milk-productions/edit' => ['production_date'],
    'purchases/show' => ['payment_date'],
    'sales/create' => ['sale_date'],
    'sales/show' => ['payment_date'],
    'slaughter/cutting' => ['session_date'],
    'slaughter/execute' => ['execution_date'],
    'slaughter/receptions/create' => ['reception_date'],
    'slaughter/transform' => ['production_date'],
    'stock-adjustments/create' => ['adjustment_date'],
    'treasury/index' => ['date'],
    'treasury/show' => ['date'],
    'utilities/edit-energy' => ['purchase_date'],
    'utilities/edit-fuel' => ['purchase_date'],
    'utilities/energy-sources' => ['reading_date'],
    'utilities/fuel-purchases' => ['purchase_date'],
    'utilities/water-sources' => ['refill_date', 'reading_date'],
];

test('aucun sélecteur de date d’un fait constaté ne propose le futur', function () {
    $manquants = [];

    foreach (SAISIES_DATEES_DU_PASSE as $vue => $champs) {
        $source = file_get_contents(resource_path("views/{$vue}.blade.php"));
        // Une balise peut contenir « {{ today()->… }} » : son « > » n'en est pas la fin.
        preg_match_all('/<input\b(?:\{\{.*?\}\}|[^>])*>/s', $source, $balises);

        foreach ($champs as $champ) {
            $trouve = false;
            foreach ($balises[0] as $balise) {
                if (! preg_match('/type=["\']date["\']/', $balise)
                    || ! preg_match('/name=["\']' . $champ . '["\']/', $balise)) {
                    continue;
                }
                $trouve = true;
                if (! preg_match('/\bmax=/', $balise)) {
                    $manquants[] = "{$vue} : {$champ}";
                }
            }
            if (! $trouve) {
                $manquants[] = "{$vue} : {$champ} (champ introuvable)";
            }
        }
    }

    expect($manquants)->toBe([]);
});

test('la porte web refuse le futur là où la synchronisation terrain le refuse', function () {
    $portes = [
        'app/Http/Controllers/CropCycleController.php' => ['harvest_date', 'input_date'],
        'app/Http/Controllers/WeatherController.php' => ['reading_date'],
        'app/Http/Controllers/CropTransformationController.php' => ['production_date'],
        'app/Http/Controllers/SlaughterController.php' => ['execution_date', 'session_date', 'production_date'],
        'app/Http/Controllers/DispatchController.php' => ['reception_date'],
        'app/Http/Controllers/SupplierInvoiceController.php' => ['payment_date'],
        'app/Http/Requests/Batch/UpdateBatchRequest.php' => ['arrival_date'],
        'app/Http/Requests/FeedPurchase/UpdateFeedPurchaseRequest.php' => ['purchase_date'],
        'app/Http/Requests/Health/UpdateHealthCheckRequest.php' => ['intervention_date'],
    ];

    $laxistes = [];
    foreach ($portes as $fichier => $champs) {
        $source = file_get_contents(base_path($fichier));
        foreach ($champs as $champ) {
            preg_match_all("/'{$champ}'\s*=>\s*([^\n]+)/", $source, $regles);
            foreach ($regles[1] as $regle) {
                // Les affectations (« 'x' => $data['x'] ») ne sont pas des règles.
                if (! preg_match('/^[\'"\[]/', $regle) || ! str_contains($regle, 'date')) continue;
                if (! str_contains($regle, 'before_or_equal:today')) {
                    $laxistes[] = "{$fichier} : {$champ}";
                }
            }
        }
    }

    expect($laxistes)->toBe([]);
});

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    $role = Role::firstOrCreate(
        ['name' => 'manager'],
        ['label' => 'Manager', 'display_name' => 'Manager', 'permissions' => ['L', 'C', 'M', 'S']]
    );

    $now = now();
    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true,
             'can_delete' => true, 'updated_at' => $now, 'created_at' => $now]
        );
    }

    $this->gerant   = User::factory()->create(['role_id' => $role->id]);
    $this->batiment = Building::factory()->create(['type' => 'mixte']);
});

test('L’ÉCRAN de création borne l’arrivée et la naissance à aujourd’hui', function () {
    $rendu = $this->actingAs($this->gerant)->get(route('batches.create'))->assertOk()->getContent();
    $aujourdhui = today()->toDateString();

    expect($rendu)->toMatch('/<input[^>]*max="' . $aujourdhui . '"[^>]*name="arrival_date"/s')
        ->and($rendu)->toMatch('/<input[^>]*max="' . $aujourdhui . '"[^>]*name="birth_date"/s');
});

test('modifier une bande pour la faire arriver demain est refusé, comme à la création', function () {
    $lot = Batch::factory()->create([
        'building_id'  => $this->batiment->id,
        'arrival_date' => today()->toDateString(),
        'birth_date'   => today()->toDateString(),
        'status'       => 'Actif',
    ]);

    $this->actingAs($this->gerant)
        ->put(route('batches.update', $lot), [
            'code'               => $lot->code,
            'building_id'        => $this->batiment->id,
            'type'               => 'engraissement',
            'buy_price_per_unit' => 5000,
            'arrival_date'       => today()->addDay()->toDateString(),
            'status'             => 'Actif',
        ])
        ->assertSessionHasErrors('arrival_date');

    expect($lot->fresh()->arrival_date->toDateString())->toBe(today()->toDateString());
});
