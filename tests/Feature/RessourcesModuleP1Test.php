<?php

use App\Models\EnergyReading;
use App\Models\EnergySource;
use App\Models\Module;
use App\Models\TaskAssignment;
use App\Models\WaterReading;
use App\Models\WaterSource;
use App\Services\TaskSchedulerService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

test('le module est renommé « Eau & Énergie » (slug ressources inchangé)', function () {
    $module = Module::where('slug', 'ressources')->first();

    expect($module)->not->toBeNull();
    expect($module->name)->toBe('Eau & Énergie');
});

test('les templates de relevé eau/énergie sont semés au niveau ferme', function () {
    foreach (['releve_eau' => 'Relevé eau', 'releve_energie' => 'Relevé énergie'] as $cat => $name) {
        $tpl = \Illuminate\Support\Facades\DB::table('task_templates')->where('category', $cat)->first();
        expect($tpl)->not->toBeNull();
        expect($tpl->name)->toBe($name);
        expect($tpl->target_type)->toBe('farm');
        expect((bool) $tpl->per_building)->toBeFalse();
        expect($tpl->frequency)->toBe('quotidien');
    }
});

test('la génération crée des tâches de relevé au niveau ferme', function () {
    app(TaskSchedulerService::class)->generateForDate(now(), $this->farm->id);

    $eau = TaskAssignment::where('farm_id', $this->farm->id)
        ->where('category', 'releve_eau')
        ->whereDate('scheduled_date', now()->toDateString())
        ->first();

    expect($eau)->not->toBeNull();
    expect($eau->building_id)->toBeNull();
    expect($eau->status)->toBe('a_faire');
});

test('la saisie d\'un relevé eau auto-complète la tâche « Relevé eau » du jour', function () {
    app(TaskSchedulerService::class)->generateForDate(now(), $this->farm->id);

    $source = WaterSource::create([
        'farm_id' => $this->farm->id, 'name' => 'Forage', 'type' => 'forage', 'is_active' => true,
    ]);

    WaterReading::create([
        'farm_id'                => $this->farm->id,
        'water_source_id'        => $source->id,
        'reading_date'           => now()->toDateString(),
        'user_id'                => $this->operatorUser->id,
        'volume_consumed_liters' => 1200,
    ]);

    $eau = TaskAssignment::where('farm_id', $this->farm->id)
        ->where('category', 'releve_eau')->whereDate('scheduled_date', now()->toDateString())->first();
    $energie = TaskAssignment::where('farm_id', $this->farm->id)
        ->where('category', 'releve_energie')->whereDate('scheduled_date', now()->toDateString())->first();

    // Le relevé eau ferme SA tâche, pas celle de l'énergie.
    expect($eau->fresh()->status)->toBe('fait');
    expect($eau->fresh()->completed_at)->not->toBeNull();
    expect($energie->fresh()->status)->toBe('a_faire');
});

test('la saisie d\'un relevé énergie auto-complète la tâche « Relevé énergie »', function () {
    app(TaskSchedulerService::class)->generateForDate(now(), $this->farm->id);

    $source = EnergySource::create([
        'farm_id' => $this->farm->id, 'name' => 'Groupe Perkins', 'type' => 'groupe',
        'fuel_type' => 'gasoil', 'is_active' => true,
    ]);

    EnergyReading::create([
        'farm_id'          => $this->farm->id,
        'energy_source_id' => $source->id,
        'reading_date'     => now()->toDateString(),
        'user_id'          => $this->operatorUser->id,
        'hours_run'        => 6,
    ]);

    $energie = TaskAssignment::where('farm_id', $this->farm->id)
        ->where('category', 'releve_energie')->whereDate('scheduled_date', now()->toDateString())->first();

    expect($energie->fresh()->status)->toBe('fait');
});
