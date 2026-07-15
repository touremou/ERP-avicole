<?php

use App\Models\Expense;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

// ─── Journalisation ───────────────────────────────────────────────────────────

test('une modification de dépense est journalisée avec l\'auteur et le diff', function () {
    $this->actingAs($this->adminUser);

    $expense = Expense::create([
        'farm_id' => $this->farm->id, 'reference' => 'DEP-AUDIT-1',
        'category' => 'divers', 'label' => 'Test', 'amount' => 1000,
        'expense_date' => now(), 'status' => 'en_attente', 'user_id' => $this->adminUser->id,
    ]);

    $expense->update(['amount' => 2500]);

    $log = Activity::where('log_name', 'audit')
        ->where('subject_type', Expense::class)
        ->where('subject_id', $expense->id)
        ->where('event', 'updated')
        ->latest()->first();

    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($this->adminUser->id)
        ->and((float) $log->properties['attributes']['amount'])->toBe(2500.0)
        ->and((float) $log->properties['old']['amount'])->toBe(1000.0);
});

test('le mot de passe d\'un utilisateur n\'est JAMAIS journalisé', function () {
    $this->actingAs($this->adminUser);

    $user = User::factory()->create(['role_id' => $this->adminUser->role_id]);
    $user->update(['password' => bcrypt('nouveau-secret'), 'name' => 'Renommé']);

    $log = Activity::where('subject_type', User::class)->where('subject_id', $user->id)->latest()->first();

    expect($log)->not->toBeNull()
        ->and($log->properties->toArray())->not->toHaveKey('attributes.password')
        // le changement de nom est bien capté, mais pas le mot de passe
        ->and(json_encode($log->properties))->not->toContain('password')
        ->and(json_encode($log->properties))->not->toContain('secret');
});

// ─── Consultation ────────────────────────────────────────────────────────────

test('l\'admin peut consulter le journal d\'audit', function () {
    $this->actingAs($this->adminUser)
        ->get(route('notifications.audit'))
        ->assertOk()
        ->assertSee('Journal d\'audit');
});

test('un non-admin ne peut pas consulter le journal d\'audit', function () {
    // operatorUser n'a pas admin.S → redirigé.
    $this->actingAs($this->operatorUser)
        ->get(route('notifications.audit'))
        ->assertRedirect(route('dashboard'));
});
