<?php

use App\Actions\EggProduction\GradeEggProduction;
use App\Models\Batch;
use App\Models\EggProduction;
use App\Models\HealthCheck;
use App\Models\Stock;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE DÉLAI D'ATTENTE S'ARRÊTAIT À LA VIANDE.
 *
 * Après un vaccin ou un traitement, la notice impose un délai avant que les
 * denrées du lot redeviennent consommables. La règle était appliquée durement à
 * l'abattage (SlaughterService : blocage, et refus de planifier avant
 * l'échéance) — et la documentation du modèle l'annonçait pour « la viande/les
 * ŒUFS ».
 *
 * Côté œufs, elle n'avait AUCUN lecteur. Une ponte prélevée en plein traitement
 * passait au calibrage, entrait en stock vendable, et partait à la vente : rien
 * dans la chaîne ne consultait le délai. C'est le motif habituel — une règle
 * déclarée à un endroit, appliquée à un seul de ses deux objets.
 *
 * LA DATE QUI COMPTE EST CELLE DE LA PONTE, pas celle du tri. Pour la viande les
 * deux se confondent (on abat aujourd'hui), pour les œufs non : une ponte du 3
 * triée le 10 reste une ponte du 3. `activeWithdrawal()` (aujourd'hui) devient
 * donc le cas particulier de `withdrawalOn($date)` — une seule règle, un seul
 * endroit, comme pour le reste de cet audit.
 *
 * CE QU'ON N'ENTRAVE PAS : la COLLECTE. Les œufs ont été pondus, le registre
 * doit le dire — le taux de ponte reste juste. Ce qu'on interdit, c'est de les
 * mettre en vente.
 */

beforeEach(function () {
    $this->setUpRbac();
    // Lot en âge de pondre : RecordEggCollection refuse une collecte sur un lot
    // trop jeune (~18 semaines attendues), garde légitime et hors sujet ici.
    $this->batch = Batch::factory()->create([
        'code' => 'PONTE-VAC', 'initial_quantity' => 100, 'current_quantity' => 100, 'qty_alive' => 100,
        'arrival_date' => now()->subDays(200), 'age_at_arrival' => 1, 'production_phase' => 'ponte',
    ]);
    $this->actingAs($this->managerUser);
});

/** Traitement avec délai d'attente sur le lot de ponte. */
function eggWithdrawal(int $batchId, int $days, string $interventionDate, string $product = 'Oxytétracycline'): HealthCheck
{
    return HealthCheck::create([
        'batch_id' => $batchId,
        'intervention_date' => $interventionDate,
        'type' => 'Traitement',
        'product_name' => $product,
        'mode_administration' => 'Eau de boisson',
        'withdrawal_days' => $days,
    ]);
}

/** Collecte brute non triée, à la date voulue. */
function eggCollection(int $batchId, string $date, int $total = 300): EggProduction
{
    return EggProduction::create([
        'batch_id'             => $batchId,
        'production_date'      => $date,
        'total_eggs_collected' => $total,
        'broken_eggs'          => 0,
        'small_eggs'           => 0,
        'is_graded'            => false,
    ]);
}

/** Tri de $total œufs entièrement sur le calibre M. */
function triSurM(int $total): array
{
    $perTray = (int) setting('general.eggs_per_tray', 30);
    $data = ['broken_eggs' => 0, 'small_eggs' => 0];

    foreach (EggProduction::gradeCodes() as $code) {
        $g = strtolower($code);
        $data["grade_{$g}_alv"] = 0;
        $data["grade_{$g}_uni"] = 0;
    }

    $data['grade_m_alv'] = intdiv($total, $perTray);
    $data['grade_m_uni'] = $total % $perTray;

    return $data;
}

test('le tri est REFUSÉ pour une ponte prélevée pendant le délai d’attente', function () {
    // LE défaut : ces œufs entraient en stock vendable sans que rien ne s'y oppose.
    eggWithdrawal($this->batch->id, 7, now()->subDays(2)->toDateString());
    $prod = eggCollection($this->batch->id, now()->subDays(2)->toDateString());

    expect(fn () => app(GradeEggProduction::class)->execute($prod, triSurM(300)))
        ->toThrow(ValidationException::class, "DÉLAI D'ATTENTE");

    expect($prod->fresh()->is_graded)->toBeFalse();
});

test('aucun œuf n’entre en stock quand le tri est refusé', function () {
    // Le test qui porte l'enjeu : ce n'est pas le drapeau is_graded qui compte,
    // c'est que le stock vendable ne bouge pas.
    Stock::create([
        'category' => Stock::CAT_OEUFS, 'item_name' => 'M', 'unit' => 'Alvéole',
        'current_quantity' => 0, 'unit_price' => 0, 'last_unit_price' => 0, 'alert_threshold' => 0,
    ]);

    eggWithdrawal($this->batch->id, 7, now()->subDays(2)->toDateString());
    $prod = eggCollection($this->batch->id, now()->subDays(2)->toDateString());

    try {
        app(GradeEggProduction::class)->execute($prod, triSurM(300));
    } catch (ValidationException $e) {
        // attendu
    }

    expect((float) Stock::where('item_name', 'M')->where('category', Stock::CAT_OEUFS)->first()->current_quantity)
        ->toBe(0.0);
});

test('trier PLUS TARD ne rend pas les œufs consommables', function () {
    // La date qui compte est celle de la PONTE. Traitement de 7 j il y a 6 j,
    // ponte du lendemain de l'intervention : le délai court encore pour elle,
    // et il courra toujours pour elle même trié dans un mois.
    eggWithdrawal($this->batch->id, 7, now()->subDays(6)->toDateString());
    $prod = eggCollection($this->batch->id, now()->subDays(5)->toDateString());

    expect(fn () => app(GradeEggProduction::class)->execute($prod, triSurM(300)))
        ->toThrow(ValidationException::class);

    // Et une fois l'échéance passée pour de bon : toujours refusé, car la ponte
    // elle-même est née pendant la fenêtre.
    $this->travel(30)->days();

    expect($this->batch->fresh()->isUnderWithdrawal())->toBeFalse()
        ->and(fn () => app(GradeEggProduction::class)->execute($prod->fresh(), triSurM(300)))
            ->toThrow(ValidationException::class);
});

test('une ponte ANTÉRIEURE à l’intervention se trie normalement', function () {
    // La fenêtre s'ouvre à l'intervention : ce qui a été produit avant est sain.
    Stock::create([
        'category' => Stock::CAT_OEUFS, 'item_name' => 'M', 'unit' => 'Alvéole',
        'current_quantity' => 0, 'unit_price' => 0, 'last_unit_price' => 0, 'alert_threshold' => 0,
    ]);

    eggWithdrawal($this->batch->id, 7, now()->subDays(2)->toDateString());
    $prod = eggCollection($this->batch->id, now()->subDays(3)->toDateString());

    app(GradeEggProduction::class)->execute($prod, triSurM(300));

    expect($prod->fresh()->is_graded)->toBeTrue()
        ->and((float) Stock::where('item_name', 'M')->where('category', Stock::CAT_OEUFS)->first()->current_quantity)
            ->toBe(10.0);
});

test('une ponte POSTÉRIEURE à l’échéance se trie normalement', function () {
    // Levée automatique, comme pour la viande : juste le temps qui passe.
    Stock::create([
        'category' => Stock::CAT_OEUFS, 'item_name' => 'M', 'unit' => 'Alvéole',
        'current_quantity' => 0, 'unit_price' => 0, 'last_unit_price' => 0, 'alert_threshold' => 0,
    ]);

    eggWithdrawal($this->batch->id, 7, now()->subDays(20)->toDateString());
    $prod = eggCollection($this->batch->id, now()->subDay()->toDateString());

    app(GradeEggProduction::class)->execute($prod, triSurM(300));

    expect($prod->fresh()->is_graded)->toBeTrue();
});

test('une intervention SANS délai (vitamine) ne bloque aucun tri', function () {
    HealthCheck::create([
        'batch_id' => $this->batch->id, 'intervention_date' => now()->toDateString(),
        'type' => 'Vitamine', 'product_name' => 'Complexe AD3E',
        'mode_administration' => 'Eau de boisson', 'withdrawal_days' => null,
    ]);

    $prod = eggCollection($this->batch->id, now()->toDateString());

    app(GradeEggProduction::class)->execute($prod, triSurM(300));

    expect($prod->fresh()->is_graded)->toBeTrue();
});

test('la COLLECTE reste libre — le registre doit dire ce qui a été pondu', function () {
    // On n'entrave pas l'enregistrement : les œufs ont été pondus, et le taux
    // de ponte se calcule sur le total collecté. C'est la mise en VENTE qu'on
    // interdit, pas la mesure.
    eggWithdrawal($this->batch->id, 7, now()->toDateString());

    $prod = app(\App\Actions\EggProduction\RecordEggCollection::class)->execute([
        'batch_id'             => $this->batch->id,
        'production_date'      => now()->toDateString(),
        'total_eggs_collected' => 90,
        'broken_eggs'          => 0,
        'small_eggs'           => 0,
    ]);

    expect($prod->total_eggs_collected)->toBe(90);
});

test('le formulaire de tri prévient AVANT la saisie', function () {
    eggWithdrawal($this->batch->id, 7, now()->subDay()->toDateString());
    $prod = eggCollection($this->batch->id, now()->toDateString());

    $this->get(route('egg-productions.tri', $prod))
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('l’intervention la plus contraignante est celle qui est nommée', function () {
    eggWithdrawal($this->batch->id, 3, now()->toDateString(), 'Court');
    eggWithdrawal($this->batch->id, 15, now()->toDateString(), 'Long');

    $found = $this->batch->fresh()->withdrawalOn(now());

    expect($found->product_name)->toBe('Long');
});

test('activeWithdrawal n’est que le cas « aujourd’hui » de withdrawalOn', function () {
    // Une seule règle, un seul endroit : c'est ce qui garantit que la viande et
    // les œufs ne pourront plus diverger.
    eggWithdrawal($this->batch->id, 7, now()->subDays(2)->toDateString());
    $batch = $this->batch->fresh();

    expect($batch->activeWithdrawal()?->id)->toBe($batch->withdrawalOn(now())?->id);

    $source = file_get_contents(app_path('Models/Batch.php'));
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $source);

    // La fonction ne doit exister qu'en un exemplaire, et déléguer.
    expect(substr_count($code, 'function activeWithdrawal('))->toBe(1)
        ->and($code)->toContain('return $this->withdrawalOn(now());');
});

test('la garde vit dans l’action, pas seulement dans le contrôleur', function () {
    // Le tri n'a aujourd'hui qu'un chemin web, mais la règle doit tenir au
    // point de passage vers le stock — sinon un second appelant rouvre le trou,
    // exactement comme la machine de provenderie côté synchro (#227).
    $source = file_get_contents(app_path('Actions/EggProduction/GradeEggProduction.php'));
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $source);

    expect($code)->toContain('withdrawalOn');
});
