<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Création des rôles de base
        $admin = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Administrateur', 'icon' => '👑']);
        $worker = Role::firstOrCreate(['name' => 'worker'], ['display_name' => 'Ouvrier', 'icon' => '👷']);
        $manager = Role::firstOrCreate(['name' => 'manager'], ['display_name' => 'Chef de Prod', 'icon' => '📊']);

        // Création des permissions de base
        $p1 = Permission::firstOrCreate(['name' => 'L'], ['description' => 'Lecture']);
        $p2 = Permission::firstOrCreate(['name' => 'C'], ['description' => 'Création']);
        $p3 = Permission::firstOrCreate(['name' => 'M'], ['description' => 'Modification']);
        $p4 = Permission::firstOrCreate(['name' => 'S'], ['description' => 'Suppression']);

        // Attribution des permissions (Matrice)
        $admin->permissions()->sync([$p1->id, $p2->id, $p3->id, $p4->id]);
        $manager->permissions()->sync([$p1->id, $p2->id, $p3->id]);
        $worker->permissions()->sync([$p1->id]);
        // User::factory(10)->create();

        // 1. Créer les permissions
        $perms = [
            ['name' => 'L', 'description' => 'Lecture'],
            ['name' => 'C', 'description' => 'Création'],
            ['name' => 'M', 'description' => 'Modification'],
            ['name' => 'S', 'description' => 'Suppression'],
        ];

        foreach ($perms as $p) {
            \App\Models\Permission::firstOrCreate(['name' => $p['name']], $p);
        }

        // 2. Donner tout à l'admin (si existant)
        $admin = \App\Models\Role::where('name', 'admin')->first();
        if ($admin) {
            $allIds = \App\Models\Permission::pluck('id');
            $admin->permissions()->sync($allIds);
        }

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Création de l'admin par défaut
        \App\Models\User::create([
        'name' => 'Admin AviSmart',
        'email' => 'admin@admin.com',
        'password' => Hash::make('password'),
        'role' => 'admin',
        ]);

        // Création de l'admin par défaut
        \App\Models\User::create([
        'name' => 'User AviSmart',
        'email' => 'user@users.com',
        'password' => Hash::make('password'),
        'role' => 'worker',
        ]);

    // Tu peux aussi pré-créer tes bâtiments
    \App\Models\Building::create(['name' => 'Bâtiment A', 'capacity' => 3000, 'type' => 'Chair']);

    $norms = [
        // CHAIR (Ross 308 - Standard)
        ['batch_type' => 'chair', 'week_number' => 1, 'target_weight' => 190, 'phase_name' => 'Démarrage'],
        ['batch_type' => 'chair', 'week_number' => 2, 'target_weight' => 480, 'phase_name' => 'Démarrage'],
        ['batch_type' => 'chair', 'week_number' => 3, 'target_weight' => 960, 'phase_name' => 'Croissance'],
        ['batch_type' => 'chair', 'week_number' => 4, 'target_weight' => 1550, 'phase_name' => 'Croissance'],
        ['batch_type' => 'chair', 'week_number' => 5, 'target_weight' => 2200, 'phase_name' => 'Finition'],
        ['batch_type' => 'chair', 'week_number' => 6, 'target_weight' => 2800, 'phase_name' => 'Finition'],

        // PONTE (ISA Brown - Standard)
        ['batch_type' => 'ponte', 'week_number' => 1, 'target_weight' => 75, 'phase_name' => 'Démarrage'],
        ['batch_type' => 'ponte', 'week_number' => 18, 'target_weight' => 1550, 'phase_name' => 'Pré-Ponte'],
        ['batch_type' => 'ponte', 'week_number' => 25, 'target_weight' => 1900, 'target_laying_rate' => 94.0, 'phase_name' => 'Ponte'],
    ];

    foreach ($norms as $norm) {
        \App\Models\ProductionNorm::updateOrCreate(
            ['batch_type' => $norm['batch_type'], 'week_number' => $norm['week_number']],
            $norm
        );
    }

    $items = [
        ['category' => 'oeufs', 'item_name' => 'Calibre XL', 'unit' => 'unité', 'alert_threshold' => 300],
        ['category' => 'oeufs', 'item_name' => 'Calibre L', 'unit' => 'unité', 'alert_threshold' => 300],
        ['category' => 'litieres', 'item_name' => 'Copeaux de bois', 'unit' => 'sac', 'alert_threshold' => 10],
        ['category' => 'materiels', 'item_name' => 'Alvéoles vides (30)', 'unit' => 'unité', 'alert_threshold' => 100],
    ];

    foreach ($items as $item) {
        \App\Models\Stock::updateOrCreate(['item_name' => $item['item_name']], $item);
    }

        $this->call(SpeciesSeeder::class);
        $this->call(ProductionNormSeeder::class);
        $this->call(ReferentialSeeder::class);

    }
}
