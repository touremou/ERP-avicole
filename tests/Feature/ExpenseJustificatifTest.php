<?php

use App\Models\Expense;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    Storage::fake('public');
});

test('une dépense peut être créée avec un justificatif PDF', function () {
    $file = UploadedFile::fake()->create('facture.pdf', 200, 'application/pdf');

    $this->actingAs($this->managerUser)
        ->post(route('expenses.store'), [
            'category'       => 'carburant',
            'label'          => 'Plein moto livraison',
            'amount'         => 50000,
            'expense_date'   => now()->toDateString(),
            'payment_method' => 'especes',
            'justificatif'   => $file,
        ])
        ->assertRedirect(route('expenses.index'));

    $expense = Expense::first();
    expect($expense)->not->toBeNull();
    expect($expense->justificatif_path)->not->toBeNull();
    Storage::disk('public')->assertExists($expense->justificatif_path);
});

test('une dépense reste valide sans justificatif (champ optionnel)', function () {
    $this->actingAs($this->managerUser)
        ->post(route('expenses.store'), [
            'category'       => 'divers',
            'label'          => 'Achat divers',
            'amount'         => 10000,
            'expense_date'   => now()->toDateString(),
            'payment_method' => 'especes',
        ])
        ->assertRedirect(route('expenses.index'));

    expect(Expense::first()->justificatif_path)->toBeNull();
});

test('un justificatif d\'un type interdit est refusé', function () {
    $file = UploadedFile::fake()->create('virus.exe', 100, 'application/x-msdownload');

    $this->actingAs($this->managerUser)
        ->post(route('expenses.store'), [
            'category'       => 'divers',
            'label'          => 'Tentative',
            'amount'         => 10000,
            'expense_date'   => now()->toDateString(),
            'payment_method' => 'especes',
            'justificatif'   => $file,
        ])
        ->assertSessionHasErrors('justificatif');

    expect(Expense::count())->toBe(0);
});

test('le justificatif d\'une dépense est téléchargeable', function () {
    $expense = Expense::create([
        'farm_id'           => $this->farm->id,
        'reference'         => 'DEP-00001',
        'user_id'           => $this->managerUser->id,
        'category'          => 'carburant',
        'label'             => 'Test',
        'amount'            => 50000,
        'expense_date'      => now()->toDateString(),
        'payment_method'    => 'especes',
        'status'            => 'en_attente',
        'justificatif_path' => UploadedFile::fake()->create('recu.pdf', 50)->store('expenses/justificatifs', 'public'),
    ]);

    $this->actingAs($this->managerUser)
        ->get(route('expenses.justificatif', $expense))
        ->assertOk()
        ->assertDownload("justificatif-{$expense->reference}.pdf");
});

test('la mise à jour remplace l\'ancien justificatif et supprime le fichier orphelin', function () {
    $oldPath = UploadedFile::fake()->create('ancien.pdf', 50)->store('expenses/justificatifs', 'public');

    $expense = Expense::create([
        'farm_id'           => $this->farm->id,
        'reference'         => 'DEP-00002',
        'user_id'           => $this->managerUser->id,
        'category'          => 'carburant',
        'label'             => 'Test',
        'amount'            => 50000,
        'expense_date'      => now()->toDateString(),
        'payment_method'    => 'especes',
        'status'            => 'en_attente',
        'justificatif_path' => $oldPath,
    ]);

    Storage::disk('public')->assertExists($oldPath);

    $this->actingAs($this->managerUser)
        ->put(route('expenses.update', $expense), [
            'category'       => 'carburant',
            'label'          => 'Test modifié',
            'amount'         => 50000,
            'expense_date'   => now()->toDateString(),
            'payment_method' => 'especes',
            'justificatif'   => UploadedFile::fake()->create('nouveau.pdf', 60, 'application/pdf'),
        ])
        ->assertRedirect(route('expenses.show', $expense));

    $expense->refresh();
    expect($expense->justificatif_path)->not->toBe($oldPath);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($expense->justificatif_path);
});
