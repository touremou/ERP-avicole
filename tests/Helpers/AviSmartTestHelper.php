<?php

namespace Tests\Helpers;

use App\Models\Building;
use App\Models\Employee;
use App\Models\Farm;
use App\Models\Module;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
     * La matrice `module_permissions` (Modules × Rôles) est la SEULE source
     * de vérité des Gates (cf. AppServiceProvider) : chaque rôle reçoit une
     * ligne par module, dérivée ici de `roles.permissions` (LCMS), pour
     * reproduire en test l'état "matrice complète" garanti en production par
     * les migrations 2026_06_10_000004 et 2026_06_14_000001. Le rôle "admin"
     * reste bypassé partout via Gate::before.
     */
    protected function setUpRbac(): void
    {
        $this->farm = Farm::firstOrCreate(
            ['code' => 'FT-001'],
            ['name' => 'Ferme Test', 'is_active' => true]
        );
        session(['current_farm_id' => $this->farm->id]);

        $make = function (string $name, array $perms) {
            $role = Role::firstOrCreate(
                ['name' => $name],
                ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]
            );

            $this->seedModuleMatrix($role, $perms);

            return $role;
        };

        $admin    = $make('admin',    ['L', 'C', 'M', 'S']);
        $manager  = $make('manager',  ['L', 'C', 'M']);
        $operator = $make('operator', ['L', 'C']);
        $viewer   = $make('viewer',   ['L']);

        $this->adminUser    = User::factory()->create(['role_id' => $admin->id]);
        $this->managerUser  = User::factory()->create(['role_id' => $manager->id]);
        $this->operatorUser = User::factory()->create(['role_id' => $operator->id]);
        $this->readonlyUser = User::factory()->create(['role_id' => $viewer->id]);
    }

    /**
     * Donne au rôle une ligne `module_permissions` par module, à partir
     * d'une matrice LCMS (L/C/M/S) appliquée uniformément à tous les modules.
     */
    protected function seedModuleMatrix(Role $role, array $perms): void
    {
        $now = now();

        foreach (Module::pluck('id') as $moduleId) {
            DB::table('module_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'module_id' => $moduleId],
                [
                    'can_read'   => in_array('L', $perms, true),
                    'can_create' => in_array('C', $perms, true),
                    'can_modify' => in_array('M', $perms, true),
                    'can_delete' => in_array('S', $perms, true),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
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
