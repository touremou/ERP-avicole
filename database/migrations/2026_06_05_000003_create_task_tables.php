<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ═══ 1. TEMPLATES DE TÂCHES ROUTINIÈRES ═══
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');                                   // "Alimentation matin"
            $table->string('category', 50);                           // alimentation, collecte, nettoyage, sante, controle, maintenance
            $table->text('description')->nullable();
            $table->string('icon', 50)->nullable();                   // fa-bowl-food
            $table->string('color', 20)->default('slate');

            // Fréquence
            $table->enum('frequency', ['quotidien', 'hebdo', 'mensuel', 'ponctuel', 'protocole']);
            $table->json('days_of_week')->nullable();                 // [1,2,3,4,5,6] = lun-sam
            $table->integer('day_of_month')->nullable();              // Pour mensuel

            // Horaire
            $table->time('scheduled_time')->nullable();               // 06:00
            $table->integer('duration_minutes')->default(30);

            // Cible
            $table->enum('target_type', ['building', 'batch', 'farm', 'equipment'])->default('building');
            $table->boolean('per_building')->default(true);           // Générer une tâche par bâtiment actif
            $table->json('batch_types')->nullable();                  // ["ponte", "chair"] — filtre

            // Priorité
            $table->enum('priority', ['basse', 'normale', 'haute', 'critique'])->default('normale');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ═══ 2. TÂCHES ASSIGNÉES (générées ou manuelles) ═══
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            // Contexte
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 50)->nullable();
            $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

            // Planning
            $table->date('scheduled_date');
            $table->time('scheduled_time')->nullable();
            $table->integer('duration_minutes')->default(30);
            $table->enum('priority', ['basse', 'normale', 'haute', 'critique'])->default('normale');

            // Exécution
            $table->enum('status', ['a_faire', 'en_cours', 'fait', 'annule', 'en_retard'])->default('a_faire');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('completion_notes')->nullable();

            $table->boolean('is_auto_generated')->default(false);
            $table->timestamps();

            $table->index(['scheduled_date', 'status']);
            $table->index(['employee_id', 'scheduled_date']);
        });

        // ═══ 3. SEED DES TEMPLATES PAR DÉFAUT ═══
        $this->seedTemplates();
    }

    private function seedTemplates(): void
    {
        $now = now();
        $weekdays = json_encode([1,2,3,4,5,6]); // Lun-Sam

        $templates = [
            // Alimentation
            ['name' => 'Alimentation matin',     'category' => 'alimentation', 'icon' => 'fa-bowl-food',        'color' => 'amber',   'frequency' => 'quotidien', 'scheduled_time' => '06:00', 'duration_minutes' => 45, 'days_of_week' => $weekdays, 'per_building' => true, 'priority' => 'haute'],
            ['name' => 'Alimentation midi',      'category' => 'alimentation', 'icon' => 'fa-bowl-food',        'color' => 'amber',   'frequency' => 'quotidien', 'scheduled_time' => '12:00', 'duration_minutes' => 30, 'days_of_week' => $weekdays, 'per_building' => true, 'priority' => 'haute'],
            ['name' => 'Alimentation soir',      'category' => 'alimentation', 'icon' => 'fa-bowl-food',        'color' => 'amber',   'frequency' => 'quotidien', 'scheduled_time' => '17:00', 'duration_minutes' => 30, 'days_of_week' => $weekdays, 'per_building' => true, 'priority' => 'haute'],

            // Collecte œufs
            ['name' => 'Collecte œufs P1',       'category' => 'collecte',     'icon' => 'fa-egg',              'color' => 'emerald', 'frequency' => 'quotidien', 'scheduled_time' => '07:30', 'duration_minutes' => 40, 'days_of_week' => $weekdays, 'per_building' => true, 'priority' => 'haute',   'batch_types' => json_encode(['ponte', 'reproducteur'])],
            ['name' => 'Collecte œufs P2',       'category' => 'collecte',     'icon' => 'fa-egg',              'color' => 'emerald', 'frequency' => 'quotidien', 'scheduled_time' => '10:30', 'duration_minutes' => 30, 'days_of_week' => $weekdays, 'per_building' => true, 'priority' => 'normale', 'batch_types' => json_encode(['ponte', 'reproducteur'])],
            ['name' => 'Collecte œufs P3',       'category' => 'collecte',     'icon' => 'fa-egg',              'color' => 'emerald', 'frequency' => 'quotidien', 'scheduled_time' => '14:00', 'duration_minutes' => 30, 'days_of_week' => $weekdays, 'per_building' => true, 'priority' => 'normale', 'batch_types' => json_encode(['ponte', 'reproducteur'])],

            // Contrôles
            ['name' => 'Relevé mortalité',       'category' => 'controle',     'icon' => 'fa-skull',            'color' => 'red',     'frequency' => 'quotidien', 'scheduled_time' => '06:30', 'duration_minutes' => 20, 'days_of_week' => $weekdays, 'per_building' => true, 'priority' => 'critique'],
            ['name' => 'Contrôle eau/abreuvoirs', 'category' => 'controle',    'icon' => 'fa-faucet-drip',      'color' => 'cyan',    'frequency' => 'quotidien', 'scheduled_time' => '07:00', 'duration_minutes' => 15, 'days_of_week' => $weekdays, 'per_building' => true, 'priority' => 'haute'],
            ['name' => 'Relevé température',     'category' => 'controle',     'icon' => 'fa-temperature-half', 'color' => 'orange',  'frequency' => 'quotidien', 'scheduled_time' => '08:00', 'duration_minutes' => 10, 'days_of_week' => $weekdays, 'per_building' => true, 'priority' => 'normale'],

            // Nettoyage
            ['name' => 'Nettoyage litière',      'category' => 'nettoyage',    'icon' => 'fa-broom',            'color' => 'purple',  'frequency' => 'quotidien', 'scheduled_time' => '08:30', 'duration_minutes' => 60, 'days_of_week' => $weekdays, 'per_building' => true, 'priority' => 'normale'],
            ['name' => 'Désinfection complète',  'category' => 'nettoyage',    'icon' => 'fa-spray-can',        'color' => 'blue',    'frequency' => 'hebdo',     'scheduled_time' => '09:00', 'duration_minutes' => 120, 'days_of_week' => json_encode([6]), 'per_building' => true, 'priority' => 'haute'],

            // Santé
            ['name' => 'Pesée échantillon',      'category' => 'sante',        'icon' => 'fa-weight-scale',     'color' => 'indigo',  'frequency' => 'hebdo',     'scheduled_time' => '09:00', 'duration_minutes' => 45, 'days_of_week' => json_encode([3]), 'per_building' => true, 'priority' => 'normale'],

            // Maintenance
            ['name' => 'Contrôle groupe électrogène', 'category' => 'maintenance', 'icon' => 'fa-bolt', 'color' => 'yellow', 'frequency' => 'quotidien', 'scheduled_time' => '06:00', 'duration_minutes' => 15, 'per_building' => false, 'priority' => 'haute'],
            ['name' => 'Inventaire stock aliment', 'category' => 'maintenance', 'icon' => 'fa-clipboard-list', 'color' => 'slate', 'frequency' => 'hebdo', 'scheduled_time' => '16:00', 'duration_minutes' => 60, 'days_of_week' => json_encode([5]), 'per_building' => false, 'priority' => 'normale'],
        ];

        foreach ($templates as $t) {
            DB::table('task_templates')->insert(array_merge([
                'farm_id'        => null,
                'target_type'    => ($t['per_building'] ?? true) ? 'building' : 'farm',
                'batch_types'    => $t['batch_types'] ?? null,
                'is_active'      => true,
                'description'    => null,
                'day_of_month'   => null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ], $t));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignments');
        Schema::dropIfExists('task_templates');
    }
};
