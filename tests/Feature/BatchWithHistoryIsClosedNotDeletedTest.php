<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN LOT QUI A PRODUIT SE CLÔTURE — IL NE SE SUPPRIME PAS.
 *
 * `BatchController::destroy()` ne vérifiait que le droit `elevage.S`. On pouvait
 * donc envoyer à la corbeille une bande entière avec tout son historique :
 * pointages, mortalité, interventions sanitaires, collectes, achats d'aliment.
 *
 * #308 a rendu ce geste NON DESTRUCTEUR — plus rien n'est effacé pour de bon.
 * Il restait déraisonnable : une bande ayant produit porte sa marge, son coût de
 * revient et sa traçabilité. On la CLÔTURE, ce qui fige ces chiffres ; on ne la
 * fait pas disparaître des écrans.
 *
 * ─── LA RÈGLE N'EST PAS NOUVELLE ───
 *
 * C'est celle que la corbeille applique déjà à la suppression DÉFINITIVE, via
 * `DependencyGuard` : on ne fait pas disparaître un enregistrement dont d'autres
 * dépendent. Elle manquait simplement à l'autre bout du parcours. On réutilise la
 * même déclaration plutôt que d'énumérer les tables une seconde fois — une
 * seconde liste divergerait au premier module ajouté.
 *
 * ─── CE QUI RESTE SUPPRIMABLE ───
 *
 * Le lot créé par erreur, avant toute saisie. C'est le seul cas où la suppression
 * est le bon geste, et il continue de fonctionner.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'building_id'      => $this->building->id,
        'initial_quantity' => 1000,
        'current_quantity' => 1000,
        'status'           => 'Actif',
    ]);
});

test('un lot SANS historique reste supprimable', function () {
    /*
     * LA borne. Une bande créée par erreur doit pouvoir partir : sans ce cas,
     * le refus deviendrait un piège.
     */
    $this->delete(route('batches.destroy', $this->lot))
        ->assertRedirect(route('batches.index'));

    expect(Batch::find($this->lot->id))->toBeNull()
        ->and(Batch::withTrashed()->find($this->lot->id))->not->toBeNull();
});

test('un lot AVEC un pointage est refusé, et le refus DIT quoi faire', function () {
    /*
     * Le cas réel. Un seul pointage suffit : c'est de la mortalité et de la
     * consommation, donc du coût de revient.
     */
    DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->lot->id,
        'check_date' => today()->subDay()->toDateString(),
        'mortality' => 40, 'user_id' => $this->adminUser->id,
    ]);

    $this->delete(route('batches.destroy', $this->lot))
        ->assertSessionHas('error');

    // Le lot est toujours là, ni supprimé ni en corbeille.
    expect(Batch::find($this->lot->id))->not->toBeNull();

    $message = session('error');
    expect(str_contains($message, $this->lot->code))->toBeTrue()
        ->and(str_contains($message, 'Clôturez'))
        ->toBeTrue('Le refus doit indiquer le geste correct, pas seulement interdire.');
});

test('le refus NOMME ce qui bloque', function () {
    /*
     * Un refus qui ne dit pas ce qu'il protège se contourne à l'aveugle. Le
     * libellé vient de la même déclaration que la corbeille.
     */
    DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->lot->id,
        'check_date' => today()->subDay()->toDateString(),
        'mortality' => 5, 'user_id' => $this->adminUser->id,
    ]);

    $this->delete(route('batches.destroy', $this->lot));

    expect(str_contains(session('error'), 'pointages journaliers'))->toBeTrue();
});

test('la CLÔTURE, elle, reste possible sur un lot qui a produit', function () {
    /*
     * Le geste que le refus recommande doit exister et fonctionner — sinon on
     * enverrait l'utilisateur dans un mur.
     */
    DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->lot->id,
        'check_date' => today()->subDay()->toDateString(),
        'mortality' => 40, 'user_id' => $this->adminUser->id,
    ]);

    app(\App\Actions\Batch\CloseBatch::class)->execute($this->lot->fresh(), [
        'closing_date'               => today()->toDateString(),
        'actual_sell_price_per_unit' => 25_000,
        'additional_costs'           => 0,
    ]);

    expect($this->lot->fresh()->status)->toBe(Batch::STATUS_TERMINE);
});

test('la règle vient de la MÊME déclaration que la corbeille', function () {
    /*
     * La garde contre la divergence : `destroy()` ne doit pas énumérer les
     * tables chez lui. La corbeille et cet écran répondent à la même question.
     */
    $source = file_get_contents(base_path('app/Http/Controllers/BatchController.php'));

    expect(str_contains($source, 'DependencyGuard::blockers($batch)'))->toBeTrue()
        ->and(str_contains($source, "DB::table('daily_checks')"))
        ->toBeFalse('destroy() ne doit pas recompter les dépendances chez lui.');
});
