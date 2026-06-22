<?php

namespace Database\Seeders;

use App\Models\Building;
use App\Models\Role;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ModuleSeeder::class);

        // Rôles de base (alignés sur ceux garantis par la migration roles).
        $admin = Role::firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Administrateur', 'label' => 'Administrateur', 'icon' => '👑', 'permissions' => ['L', 'C', 'M', 'S']]
        );
        $ouvrier = Role::firstOrCreate(
            ['name' => 'ouvrier'],
            ['display_name' => 'Ouvrier', 'label' => 'Ouvrier', 'icon' => '👷', 'permissions' => ['L']]
        );

        // Comptes par défaut
        User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            ['name' => 'Admin AviSmart', 'password' => Hash::make('password'), 'role_id' => $admin->id]
        );

        User::firstOrCreate(
            ['email' => 'user@users.com'],
            ['name' => 'User AviSmart', 'password' => Hash::make('password'), 'role_id' => $ouvrier->id]
        );

        // Bâtiment de démonstration
        Building::firstOrCreate(
            ['name' => 'Bâtiment A'],
            ['capacity' => 3000, 'type' => 'chair', 'status' => 'Vide']
        );

        // Articles de stock de base
        $items = [
            ['category' => 'oeufs', 'item_name' => 'Calibre XL', 'unit' => 'unité', 'current_quantity' => 0, 'alert_threshold' => 300],
            ['category' => 'oeufs', 'item_name' => 'Calibre L', 'unit' => 'unité', 'current_quantity' => 0, 'alert_threshold' => 300],
            ['category' => 'litieres', 'item_name' => 'Copeaux de bois', 'unit' => 'sac', 'current_quantity' => 0, 'alert_threshold' => 10],
            ['category' => 'materiels', 'item_name' => 'Alvéoles vides (30)', 'unit' => 'unité', 'current_quantity' => 0, 'alert_threshold' => 100],
        ];

        foreach ($items as $item) {
            Stock::updateOrCreate(['item_name' => $item['item_name']], $item);
        }

        $this->call(SpeciesSeeder::class);
        $this->call(ProductionNormSeeder::class);
        $this->call(ReferentialSeeder::class);
        $this->call(CropCatalogueSeeder::class);
        $this->call(CropProtocolSeeder::class);
        $this->call(CropRecipeSeeder::class);
    }
}
