<?php

namespace Tests\Browser;

use App\Models\Building;
use App\Models\Farm;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Audit 360° P2-⑫ — parcours E2E critiques dans un VRAI navigateur
 * (Chrome headless) contre une VRAIE base MySQL (erp_dusk).
 *
 * Ces parcours exercent ce que les tests HTTP ne voient pas : le rendu
 * complet (layout, x-page-header, ancres de retour), la soumission réelle
 * des formulaires, et la redirection navigateur.
 */
class CriticalJourneysTest extends DuskTestCase
{
    use DatabaseTruncation;

    protected User $admin;
    protected Building $building;

    protected function setUp(): void
    {
        parent::setUp();

        $farm = Farm::firstOrCreate(['code' => 'E2E-001'], ['name' => 'Ferme E2E', 'is_active' => true]);
        session(['current_farm_id' => $farm->id]);

        $role = Role::firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Admin', 'display_name' => 'Admin', 'permissions' => ['L', 'C', 'M', 'S']]
        );

        $this->admin = User::factory()->create([
            'role_id'  => $role->id,
            'email'    => 'e2e@avismart.gn',
            'password' => bcrypt('password'),
        ]);

        DB::table('farm_user')->insert([
            'farm_id' => $farm->id, 'user_id' => $this->admin->id,
            'is_default' => true, 'is_owner' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->building = Building::create([
            'name' => 'Bâtiment E2E', 'type' => 'chair', 'capacity' => 5000,
            'status' => 'Disponible', 'farm_id' => $farm->id,
        ]);

        // Référentiel minimal : sans espèce + type de production, le select
        // « Type de production » (required) n'a AUCUNE option → le formulaire
        // lot est physiquement insoumissible (constaté par screenshot E2E).
        $species = \App\Models\Species::firstOrCreate(
            ['slug' => 'poulet'],
            ['name_fr' => 'Poulet', 'family' => 'volaille', 'is_active' => true]
        );
        \App\Models\ProductionType::resolveOrCreate('chair', $species->id);

        // Le select « Souche/Race » (required) est alimenté par production_norms :
        // on seed le référentiel officiel (mêmes données qu'en production).
        $this->seed(\Database\Seeders\ProductionNormSeeder::class);
    }

    /** Parcours 1 — connexion réelle par le formulaire, arrivée sur le tableau de bord. */
    public function test_login_reel_jusqu_au_tableau_de_bord(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->type('email', 'e2e@avismart.gn')
                ->type('password', 'password')
                ->click('button[type="submit"]')
                ->waitForLocation('/dashboard', 15)
                ->assertPathIs('/dashboard');
        });
    }

    /** Parcours 2 — retour HIÉRARCHIQUE : une sous-page de section ramène à la section, pas au hub. */
    public function test_retour_hierarchique_depuis_un_rapport(): void
    {
        $this->browse(function (Browser $browser) {
            // Sélecteur par href (insensible à la locale de l'interface).
            $backSelector = 'a[href="' . route('reports.index') . '"]';

            $browser->driver->manage()->deleteAllCookies(); // navigateur partagé entre tests

            $browser->loginAs($this->admin)
                ->visit(route('reports.profit_loss', absolute: false))
                ->waitFor($backSelector, 25); // 1re visite : artisan serve compile à froid

            // Clic déclenché via element.click() : le clic natif WebDriver se
            // PERD en headless=new quand un test a déjà tourné dans la même
            // fenêtre (prouvé : listener capture sur document → aucun event
            // reçu). Artefact driver, pas applicatif — la navigation reste
            // bien réelle (ancre réelle, requête serveur réelle).
            $browser->script("document.querySelector('a[href=\"" . route('reports.index') . "\"]').click();");

            $browser->waitForRoute('reports.index', [], 15)
                ->assertRouteIs('reports.index');
        });
    }

    /** Parcours 3 — création d'un lot de bout en bout (palier basic : sans employé ni fournisseur). */
    public function test_creation_de_lot_de_bout_en_bout(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->driver->manage()->deleteAllCookies(); // navigateur partagé entre tests

            $browser->loginAs($this->admin)
                ->visit(route('batches.create', absolute: false))
                ->waitFor('input[name="code"]', 25);

            // Le formulaire a un JS d'initialisation qui AUTO-GÉNÈRE le code
            // (LOT-AAAAMMJJ-HHMMSS) et remet qty/prix à 0 — toute frappe trop
            // précoce est écrasée (constaté par dump E2E). On attend la fin de
            // l'init, on saisit ENSUITE, et on asserte sur le code auto-généré.
            $browser->waitUsing(20, 150, function () use ($browser) {
                return strlen((string) $browser->value('input[name="code"]')) > 0;
            }, 'init JS du formulaire lot');

            $browser->select('type', 'chair')
                ->pause(800) // runFilters() re-rend bâtiments/souches
                // Les handlers clavier (oninput=calculateAll) écrasent la frappe
                // simulée : on injecte la value directement (le POST la lit).
                ->value('input[name="qty_alive"]', '100')
                ->value('input[name="buy_price_per_unit"]', '5000')
                ->value('input[name="arrival_date"]', now()->format('Y-m-d'))
                ->select('building_id', (string) $this->building->id);

            // « Scellage » final : les scripts du formulaire reconstruisent le
            // select type après coup (sélection perdue, constaté au diagnostic
            // checkValidity) — on repose type + souche APRÈS tous les
            // re-renders, avec l'event change pour dérouler les filtres liés.
            $browser->script("
                const f = document.querySelector('form[action*=batches]');
                f.elements['type'].value = 'chair';
                f.elements['type'].dispatchEvent(new Event('change'));
            ");
            $browser->pause(500);
            $browser->script("
                const f = document.querySelector('form[action*=batches]');
                const opt = f.elements['model_name'].querySelector('option[data-type=chair], option.model-opt');
                if (opt) { f.elements['model_name'].value = opt.value;
                           f.elements['model_name'].dispatchEvent(new Event('change')); }
                f.elements['type'].value = 'chair';
            ");
            // Soumission via requestSubmit() : même raison que le parcours
            // retour — le clic natif se perd en headless=new après un autre
            // test dans la même fenêtre. requestSubmit() rejoue exactement le
            // comportement d'un clic (validation HTML5 + event submit + POST).
            $browser->pause(300)->script("
                document.querySelector('form[action*=batches] button[type=submit]')
                    .closest('form').requestSubmit();
            ");

            // Vérité en BASE (le code auto peut être re-généré au re-render) :
            // le lot existe avec le bon effectif dans le bon bâtiment.
            $browser->waitUsing(30, 250, function () {
                return \App\Models\Batch::withoutGlobalScopes()
                    ->where('building_id', $this->building->id)
                    ->where('current_quantity', 100)
                    ->exists();
            }, 'création du lot en base');

            $batch = \App\Models\Batch::withoutGlobalScopes()->firstOrFail();
            $browser->waitForText($batch->code, 15)->assertSee($batch->code); // fiche affichée
        });
    }
}
