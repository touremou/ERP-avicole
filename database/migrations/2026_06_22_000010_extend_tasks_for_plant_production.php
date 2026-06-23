<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Add plot_types JSON column to task_templates.
        if (! Schema::hasColumn('task_templates', 'plot_types')) {
            Schema::table('task_templates', function (Blueprint $table) {
                $table->json('plot_types')->nullable()->after('batch_types');
            });
        }

        // Add plot_id FK to task_assignments.
        if (! Schema::hasColumn('task_assignments', 'plot_id')) {
            Schema::table('task_assignments', function (Blueprint $table) {
                $table->foreignId('plot_id')->nullable()->after('building_id')
                    ->constrained('plots')->nullOnDelete();
            });
        }

        // Extend target_type enum to include 'plot'.
        if (DB::getDriverName() === 'sqlite') {
            // SQLite: disable CHECK constraint enforcement for seeding.
            // The PRAGMA is connection-scoped so it stays active for the seed inserts below.
            DB::statement('PRAGMA ignore_check_constraints = ON');
        } else {
            DB::statement("ALTER TABLE task_templates MODIFY COLUMN target_type ENUM('building','batch','farm','equipment','plot') DEFAULT 'building'");
        }

        // Seed crop task templates.
        $this->seedCropTemplates();

        // Restore check constraints after seeding (SQLite).
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
    }

    private function seedCropTemplates(): void
    {
        $now = now();
        $weekdays = json_encode([1,2,3,4,5,6]);

        $templates = [
            ['name' => 'Arrosage/Irrigation',        'category' => 'irrigation',    'icon' => 'fa-droplet',          'color' => 'cyan',    'frequency' => 'quotidien', 'scheduled_time' => '06:00', 'duration_minutes' => 60,  'days_of_week' => $weekdays,        'priority' => 'haute',    'target_type' => 'plot'],
            ['name' => 'Sarclage & désherbage',       'category' => 'sarclage',      'icon' => 'fa-trowel',           'color' => 'amber',   'frequency' => 'hebdo',     'scheduled_time' => '07:00', 'duration_minutes' => 120, 'days_of_week' => json_encode([2,5]), 'priority' => 'normale',  'target_type' => 'plot'],
            ['name' => 'Traitement phytosanitaire',   'category' => 'traitement',    'icon' => 'fa-spray-can-sparkles','color' => 'rose',    'frequency' => 'hebdo',     'scheduled_time' => '07:00', 'duration_minutes' => 90,  'days_of_week' => json_encode([3]),   'priority' => 'haute',    'target_type' => 'plot'],
            ['name' => 'Apport engrais/fertilisant',  'category' => 'fertilisation', 'icon' => 'fa-flask',            'color' => 'green',   'frequency' => 'mensuel',   'scheduled_time' => '08:00', 'duration_minutes' => 120, 'day_of_month' => 1,                  'priority' => 'normale',  'target_type' => 'plot'],
            ['name' => 'Inspection de culture',       'category' => 'controle',      'icon' => 'fa-magnifying-glass', 'color' => 'indigo',  'frequency' => 'hebdo',     'scheduled_time' => '08:00', 'duration_minutes' => 45,  'days_of_week' => json_encode([1]),   'priority' => 'normale',  'target_type' => 'plot'],
            ['name' => 'Relevé météo parcelle',       'category' => 'controle',      'icon' => 'fa-cloud-sun-rain',   'color' => 'sky',     'frequency' => 'quotidien', 'scheduled_time' => '07:30', 'duration_minutes' => 15,  'days_of_week' => $weekdays,          'priority' => 'normale',  'target_type' => 'farm'],
            ['name' => 'Récolte',                     'category' => 'recolte',       'icon' => 'fa-basket-shopping',  'color' => 'emerald', 'frequency' => 'ponctuel',  'scheduled_time' => '06:00', 'duration_minutes' => 240,                                           'priority' => 'critique', 'target_type' => 'plot'],
        ];

        foreach ($templates as $t) {
            DB::table('task_templates')->insert(array_merge([
                'farm_id'        => null,
                'batch_types'    => null,
                'plot_types'     => null,
                'per_building'   => false,
                'is_active'      => true,
                'description'    => null,
                'days_of_week'   => null,
                'day_of_month'   => null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ], $t));
        }
    }

    public function down(): void
    {
        Schema::table('task_assignments', function (Blueprint $table) {
            $table->dropForeign(['plot_id']);
            $table->dropColumn('plot_id');
        });
        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropColumn('plot_types');
        });
        DB::table('task_templates')->whereIn('category', ['irrigation', 'sarclage', 'traitement', 'fertilisation', 'recolte'])->delete();
    }
};
