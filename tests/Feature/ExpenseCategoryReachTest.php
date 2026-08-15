<?php

use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE RÉFÉRENTIEL DES DÉPENSES ÉTAIT FERMÉ AU BUREAU, OUVERT SUR LE TERRAIN.
 *
 * Le formulaire du bureau impose la liste `Expense::CATEGORIES` ; le point
 * d'entrée de la synchro validait la même donnée en TEXTE LIBRE. Rien ne
 * pouvait donc signaler qu'un écran terrain avait cessé de refléter la liste du
 * serveur — et c'était le cas (cf. MobileMirrorDriftTest).
 *
 * L'ENJEU N'EST PAS COSMÉTIQUE : BudgetMonitor cherche un budget PAR CATÉGORIE.
 * Une catégorie hors liste ne rencontre aucun budget, donc aucun dépassement
 * n'est possible, donc aucune alerte ne part — pour le promoteur hors site,
 * une dépense invisible à la surveillance.
 */

beforeEach(function () {
    $this->setUpRbac();
    Sanctum::actingAs($this->managerUser);
});

test('SYNCHRO : une catégorie hors référentiel est refusée', function () {
    // Le bout concret : ce chemin acceptait n'importe quelle chaîne, et une
    // catégorie hors liste ne rencontre AUCUN budget — donc aucune alerte de
    // dépassement, jamais.
        $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(),
        'type'    => 'expense.create',
        'payload' => [
            'uuid'         => (string) Str::uuid(),
            'category'     => 'achat_poussins_2026',
            'label'        => 'Achat de poussins',
            'amount'       => 4_000_000,
            'expense_date' => now()->toDateString(),
        ],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('validation_failed')
        ->and(\App\Models\Expense::withoutGlobalScopes()->count())->toBe(0);
});

test('SYNCHRO : la catégorie retrouvée au terrain est acceptée', function () {
    // `achat_animaux` : celle qui manquait à l'écran des dépenses.
        $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(),
        'type'    => 'expense.create',
        'payload' => [
            'uuid'           => (string) Str::uuid(),
            'category'       => 'achat_animaux',
            'label'          => 'Achat de poussins',
            'amount'         => 4_000_000,
            'expense_date'   => now()->toDateString(),
            'payment_method' => 'especes',
        ],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('success')
        ->and(\App\Models\Expense::withoutGlobalScopes()->first()->category)->toBe('achat_animaux');
});

