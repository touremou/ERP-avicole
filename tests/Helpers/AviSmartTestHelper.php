<?php

namespace Tests\Helpers;

use App\Models\Building;
use App\Models\Employee;
use App\Models\Permission;
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

    protected function setUpRbac(): void
    {
        $permL = Permission::firstOrCreate(['name' => 'L'], ['description' => 'Lecture']);
        $permC = Permission::firstOrCreate(['name' => 'C'], ['description' => 'Création']);
        $permM = Permission::firstOrCreate(['name' => 'M'], ['description' => 'Modification']);
        $permS = Permission::firstOrCreate(['name' => 'S'], ['description' => 'Suppression']);

        $admin = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Administrateur', 'icon' => '👑']);
        $admin->permissions()->syncWithoutDetaching([$permL->id, $permC->id, $permM->id, $permS->id]);

        $manager = Role::firstOrCreate(['name' => 'manager'], ['display_name' => 'Manager', 'icon' => '🛠️']);
        $manager->permissions()->syncWithoutDetaching([$permL->id, $permC->id, $permM->id]);

        $operator = Role::firstOrCreate(['name' => 'operateur'], ['display_name' => 'Opérateur', 'icon' => '📋']);
        $operator->permissions()->syncWithoutDetaching([$permL->id, $permC->id]);

        $readonly = Role::firstOrCreate(['name' => 'visiteur'], ['display_name' => 'Visiteur', 'icon' => '👁️']);
        $readonly->permissions()->syncWithoutDetaching([$permL->id]);

        $this->adminUser = User::factory()->create(['role_id' => $admin->id]);
        $this->managerUser = User::factory()->create(['role_id' => $manager->id]);
        $this->operatorUser = User::factory()->create(['role_id' => $operator->id]);
        $this->readonlyUser = User::factory()->create(['role_id' => $readonly->id]);
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
