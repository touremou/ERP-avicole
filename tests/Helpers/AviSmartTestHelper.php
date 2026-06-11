<?php

namespace Tests\Helpers;

use App\Models\Building;
use App\Models\Employee;
use App\Models\Farm;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;

trait AviSmartTestHelper
{
    protected User $adminUser;
    protected User $managerUser;
    protected User $operatorUser;
    protected User $readonlyUser;
    protected Building $building;
    protected Employee $employee;
    protected Provider $provider;
    protected Farm $farm;

    /**
     * Met en place les rôles RBAC sur l'architecture courante.
     *
     * Les permissions sont portées par la colonne JSON `roles.permissions` et,
     * pour les rôles sans matrice `module_permissions`, les Gates retombent sur
     * une correspondance par NOM de rôle (admin/manager/operator/viewer) — d'où
     * l'usage de ces noms exacts. (L'ancien pivot Permission/permission_role
     * n'est plus utilisé.)
     */
    protected function setUpRbac(): void
    {
        $this->farm = Farm::firstOrCreate(
            ['code' => 'FT-001'],
            ['name' => 'Ferme Test', 'is_active' => true]
        );
        session(['current_farm_id' => $this->farm->id]);

        $make = fn (string $name, array $perms) => Role::firstOrCreate(
            ['name' => $name],
            ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]
        );

        $admin    = $make('admin',    ['L', 'C', 'M', 'S']);
        $manager  = $make('manager',  ['L', 'C', 'M']);
        $operator = $make('operator', ['L', 'C']);
        $viewer   = $make('viewer',   ['L']);

        $this->adminUser    = User::factory()->create(['role_id' => $admin->id]);
        $this->managerUser  = User::factory()->create(['role_id' => $manager->id]);
        $this->operatorUser = User::factory()->create(['role_id' => $operator->id]);
        $this->readonlyUser = User::factory()->create(['role_id' => $viewer->id]);
    }

    protected function setUpBaseData(): void
    {
        $this->building = Building::factory()->create([
            'name'     => 'Bâtiment A',
            'type'     => 'chair',
            'capacity' => 5000,
            'status'   => 'Disponible',
        ]);

        $this->employee = Employee::factory()->create([
            'first_name' => 'Moussa',
            'last_name'  => 'Diallo',
            'status'     => 'Actif',
        ]);

        $this->provider = Provider::factory()->create([
            'name'   => 'Avipro Guinée',
            'status' => 'Actif',
        ]);
    }
}
