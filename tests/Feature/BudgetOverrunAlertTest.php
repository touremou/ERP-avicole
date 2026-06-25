<?php

use App\Models\Budget;
use App\Models\Expense;
use App\Services\NotificationHub;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

/** Crée une dépense VALIDE (déclenche l'observer budgétaire). */
function validExpense(string $category, float $amount, int $userId): Expense
{
    static $seq = 0;
    $seq++;

    return Expense::create([
        'reference'    => 'DEP-T' . $seq,
        'category'     => $category,
        'label'        => 'Test ' . $category,
        'amount'       => $amount,
        'expense_date' => now()->toDateString(),
        'status'       => 'valide',
        'user_id'      => $userId,
    ]);
}

test('aucune alerte tant que le budget n\'est pas franchi', function () {
    Budget::create(['category' => 'divers', 'year' => now()->year, 'month' => now()->month, 'amount' => 10000]);

    $this->mock(NotificationHub::class)->shouldReceive('alertBudgetOverrun')->never();

    validExpense('divers', 8000, $this->adminUser->id); // 8 000 ≤ 10 000
});

test('le franchissement du budget déclenche une alerte unique', function () {
    Budget::create(['category' => 'divers', 'year' => now()->year, 'month' => now()->month, 'amount' => 10000]);

    $this->mock(NotificationHub::class)
        ->shouldReceive('alertBudgetOverrun')
        ->once()
        ->with('divers', now()->year, now()->month, 13000.0, 10000.0);

    validExpense('divers', 8000, $this->adminUser->id);  // cumul 8 000 → pas d'alerte
    validExpense('divers', 5000, $this->adminUser->id);  // cumul 13 000 > 10 000 → franchissement → 1 alerte
});

test('une fois en dépassement, les dépenses suivantes ne ré-alertent pas', function () {
    Budget::create(['category' => 'divers', 'year' => now()->year, 'month' => now()->month, 'amount' => 10000]);

    $this->mock(NotificationHub::class)
        ->shouldReceive('alertBudgetOverrun')
        ->once(); // une seule fois malgré 3 dépenses

    validExpense('divers', 8000, $this->adminUser->id);
    validExpense('divers', 5000, $this->adminUser->id); // franchit (13 000)
    validExpense('divers', 3000, $this->adminUser->id); // déjà au-delà (16 000) → pas de nouvelle alerte
});

test('sans budget défini, aucun dépassement n\'est possible', function () {
    $this->mock(NotificationHub::class)->shouldReceive('alertBudgetOverrun')->never();

    validExpense('divers', 50000, $this->adminUser->id); // gros montant mais aucun budget alloué
});
