<?php

use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Cloisonnement Commerce (Ventes) vs Caisse (POS). Un CAISSIER opère le point
 * de vente et les sessions de caisse sans accéder au back-office ventes
 * (carnet clients, recouvrement, tarifs, annulation de factures). Un VENDEUR
 * gère les ventes/clients sans opérer la caisse physique.
 */

function comCaisseRole(string $name, array $moduleLevels): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => []]);
    foreach ($moduleLevels as $slug => $perms) {
        $mod = Module::where('slug', $slug)->value('id');
        if (! $mod) continue;
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $mod],
            ['can_read' => in_array('L', $perms), 'can_create' => in_array('C', $perms),
             'can_modify' => in_array('M', $perms), 'can_delete' => in_array('S', $perms),
             'created_at' => now(), 'updated_at' => now()]
        );
    }

    return $role;
}

beforeEach(function () {
    $this->setUpRbac();
    session(['current_farm_id' => $this->farm->id]);

    // Caissier : caisse L+C uniquement.
    $this->caissier = User::factory()->create([
        'role_id' => comCaisseRole('caissier', ['caisse' => ['L', 'C']])->id,
    ]);
    // Vendeur back-office : commerce L+C+M uniquement (pas de caisse).
    $this->vendeur = User::factory()->create([
        'role_id' => comCaisseRole('vendeur_ventes', ['commerce' => ['L', 'C', 'M']])->id,
    ]);
});

test('le caissier (caisse) opère le POS et les sessions de caisse', function () {
    $this->actingAs($this->caissier)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('pos.index'))->assertOk();
    $this->actingAs($this->caissier)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('cash-register.index'))->assertOk();
});

test('le caissier ne peut PAS accéder au back-office ventes (clients, recouvrement, tarifs)', function () {
    foreach (['commerce.index', 'sales.index', 'clients.index', 'sales.receivables', 'sales.price-lists'] as $route) {
        $response = $this->actingAs($this->caissier)->withSession(['current_farm_id' => $this->farm->id])
            ->get(route($route));
        expect($response->status())->toBeIn([302, 403], "Route ventes {$route} devrait être refusée au caissier");
    }
});

test('le vendeur (commerce) gère les ventes mais ne peut PAS opérer la caisse (POS)', function () {
    $this->actingAs($this->vendeur)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('sales.index'))->assertOk();
    $this->actingAs($this->vendeur)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('clients.index'))->assertOk();

    foreach (['pos.index', 'cash-register.index'] as $route) {
        $response = $this->actingAs($this->vendeur)->withSession(['current_farm_id' => $this->farm->id])
            ->get(route($route));
        expect($response->status())->toBeIn([302, 403], "Route caisse {$route} devrait être refusée au vendeur");
    }
});

test('les tuiles du lanceur reflètent le cloisonnement Commerce/Caisse', function () {
    $caissierSlugs = $this->caissier->getAccessibleModules()->pluck('slug');
    expect($caissierSlugs->contains('caisse'))->toBeTrue()
        ->and($caissierSlugs->contains('commerce'))->toBeFalse();

    $vendeurSlugs = $this->vendeur->getAccessibleModules()->pluck('slug');
    expect($vendeurSlugs->contains('commerce'))->toBeTrue()
        ->and($vendeurSlugs->contains('caisse'))->toBeFalse();
});
