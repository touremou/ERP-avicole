<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ─── TABLE DES MODULES ERP ───
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('name');                    // "Élevage", "Provenderie", etc.
            $table->string('slug')->unique();          // "elevage", "provenderie"
            $table->string('icon')->nullable();        // "fa-dove", "fa-wheat-awn"
            $table->string('color')->nullable();       // "blue", "lime"
            $table->text('description')->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ─── PIVOT : PERMISSIONS PAR MODULE PAR RÔLE ───
        Schema::create('module_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_read')->default(false);      // L
            $table->boolean('can_create')->default(false);    // C
            $table->boolean('can_modify')->default(false);    // M
            $table->boolean('can_delete')->default(false);    // S
            $table->timestamps();

            $table->unique(['role_id', 'module_id']);
        });

        // ─── SEED DES 12 MODULES AviSmart ───
        $modules = [
            ['name' => 'Dashboard',       'slug' => 'dashboard',    'icon' => 'fa-gauge-high',      'color' => 'slate',   'display_order' => 0],
            ['name' => 'Élevage',         'slug' => 'elevage',      'icon' => 'fa-dove',            'color' => 'blue',    'display_order' => 1],
            ['name' => 'Production',      'slug' => 'production',   'icon' => 'fa-egg',             'color' => 'amber',   'display_order' => 2],
            ['name' => 'Provenderie',     'slug' => 'provenderie',  'icon' => 'fa-wheat-awn',       'color' => 'lime',    'display_order' => 3],
            ['name' => 'Planning',        'slug' => 'planning',     'icon' => 'fa-calendar-days',   'color' => 'indigo',  'display_order' => 4],
            ['name' => 'Abattoir',        'slug' => 'abattoir',     'icon' => 'fa-drumstick-bite',  'color' => 'rose',    'display_order' => 5],
            ['name' => 'Commerce',        'slug' => 'commerce',     'icon' => 'fa-cash-register',   'color' => 'teal',    'display_order' => 6],
            ['name' => 'Logistique',      'slug' => 'logistique',   'icon' => 'fa-truck',           'color' => 'orange',  'display_order' => 7],
            ['name' => 'Ressources',      'slug' => 'ressources',   'icon' => 'fa-bolt',            'color' => 'cyan',    'display_order' => 8],
            ['name' => 'Notifications',   'slug' => 'notifications','icon' => 'fa-bell',            'color' => 'emerald', 'display_order' => 9],
            ['name' => 'Annuaire',        'slug' => 'annuaire',     'icon' => 'fa-users',           'color' => 'slate',   'display_order' => 10],
            ['name' => 'Administration',  'slug' => 'admin',        'icon' => 'fa-shield-halved',   'color' => 'purple',  'display_order' => 11],
        ];

        $now = now();
        foreach ($modules as $m) {
            DB::table('modules')->insert(array_merge($m, [
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('module_permissions');
        Schema::dropIfExists('modules');
    }
};
