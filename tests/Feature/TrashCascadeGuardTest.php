<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\Employee;
use App\Support\DependencyGuard;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA CORBEILLE ANNONÇAIT UNE GARDE QU'ELLE NE FAISAIT PAS.
 *
 * Son commentaire disait, mot pour mot : « Sécurité : On empêche la suppression
 * physique si l'élément a laissé des traces (ex : factures, pointages) ». La
 * ligne suivante avouait le contraire : « c'est ici que l'on POURRAIT vérifier
 * des relations complexes avant le point de non-retour ». Entre les deux,
 * `forceDelete()` partait sans rien contrôler.
 *
 * CE QUE ÇA COÛTAIT N'EST PAS THÉORIQUE : les clés étrangères de cette base sont
 * en CASCADE.
 *
 *   • un LOT archivé emporte ses pointages journaliers, ses interventions
 *     sanitaires, ses achats d'aliment, ses collectes d'œufs, ses reproducteurs
 *     et ses tâches — tout l'historique technique et le coût de revient de la
 *     bande ;
 *   • un EMPLOYÉ archivé emporte ses bulletins de paie, ses présences, ses
 *     congés… ET SES LOTS (`batches.employee_id` est en cascade), qui emportent
 *     à leur tour tout ce qui précède.
 *
 * Un clic sur « Suppression irréversible » pouvait donc effacer des années
 * d'élevage et de paie — en annonçant « Suppression irréversible effectuée ».
 * Et « Vider la corbeille » le faisait pour TOUT d'un coup, en concluant « La
 * base de données a été nettoyée ».
 *
 * LA DÉTECTION EST DÉRIVÉE DU SCHÉMA : on interroge les clés étrangères réelles,
 * pas une liste de relations écrite à la main. Une liste serait incomplète le
 * jour où une table s'ajoute — et une garde incomplète sur un geste
 * IRRÉVERSIBLE vaut à peine mieux que pas de garde.
 *
 * CE QU'ON NE FAIT PAS : empêcher toute suppression. Un élément archivé qui ne
 * porte rien s'efface comme avant. C'est la destruction SILENCIEUSE d'historique
 * qu'on arrête, pas le ménage.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

/** Lot archivé portant un pointage journalier (donc une trace). */
function lotArchiveAvecHistorique(): Batch
{
    $lot = Batch::factory()->create(['code' => 'LOT-HIST']);

    DailyCheck::create([
        'batch_id' => $lot->id, 'check_date' => now()->toDateString(),
        'mortality' => 2, 'feed_consumed' => 50, 'feed_type' => 'Croissance',
        'health_status' => 'Normal',
    ]);

    $lot->delete(); // archivage (suppression logique)

    return $lot;
}

test('un lot archivé qui porte un historique n’est PAS supprimé', function () {
    // LE défaut : ce clic effaçait le pointage, les soins, les collectes…
    $lot = lotArchiveAvecHistorique();

    $this->delete(route('trash.forceDelete', ['type' => 'batch', 'id' => $lot->id]))
        ->assertRedirect();

    expect(Batch::withTrashed()->find($lot->id))->not->toBeNull()
        // `withTrashed` : archiver un lot ARCHIVE aussi ses pointages. Ils
        // existent toujours en base — et c'est bien pour cela qu'une suppression
        // physique les détruirait pour de bon.
        ->and(DailyCheck::withTrashed()->where('batch_id', $lot->id)->count())->toBe(1);
});

test('le refus NOMME ce qui retient', function () {
    // Un refus muet pousse à chercher un autre moyen de supprimer.
    $lot = lotArchiveAvecHistorique();

    $this->delete(route('trash.forceDelete', ['type' => 'batch', 'id' => $lot->id]))
        ->assertSessionHas('error');

    expect(session('error'))->toContain('pointages journaliers');
});

test('un élément archivé SANS trace s’efface comme avant', function () {
    // On arrête la destruction silencieuse d'historique, pas le ménage.
    $batiment = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'Poulailler vide',
        'type' => 'chair', 'capacity' => 500, 'status' => Building::STATUS_VIDE,
    ]);
    $batiment->delete();

    $this->delete(route('trash.forceDelete', ['type' => 'building', 'id' => $batiment->id]))
        ->assertRedirect()->assertSessionHas('success');

    expect(Building::withTrashed()->find($batiment->id))->toBeNull();
});

test('VIDER LA CORBEILLE épargne ce qui porte des traces', function () {
    // Le même geste en masse, donc la même règle — c'était la version la plus
    // destructrice : quatre `forceDelete()` sans un mot.
    $lot = lotArchiveAvecHistorique();

    $batiment = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'Poulailler vide',
        'type' => 'chair', 'capacity' => 500, 'status' => Building::STATUS_VIDE,
    ]);
    $batiment->delete();

    $this->delete(route('trash.clearAll'))->assertRedirect();

    expect(Batch::withTrashed()->find($lot->id))->not->toBeNull()
        ->and(Building::withTrashed()->find($batiment->id))->toBeNull()
        ->and(DailyCheck::withTrashed()->where('batch_id', $lot->id)->count())->toBe(1);
});

test('le vidage RESTITUE ce qu’il a gardé', function () {
    // « La base de données a été nettoyée » ne disait rien de ce qui restait.
    lotArchiveAvecHistorique();

    $this->delete(route('trash.clearAll'))->assertRedirect();

    expect(session('success'))->toContain('conservé');
});

test('la détection voit les dépendances d’un EMPLOYÉ', function () {
    /*
     * Le cas le plus lourd : `batches.employee_id` est en cascade. Supprimer un
     * employé archivé emportait ses lots — et donc l'historique de ses lots.
     */
    $employe = Employee::factory()->create(['farm_id' => $this->farm->id]);
    Batch::factory()->create(['employee_id' => $employe->id]);

    $employe->delete();

    expect(DependencyGuard::blockers($employe))->toHaveKey('batches');
});

test('la détection est DÉRIVÉE du schéma, pas d’une liste écrite à la main', function () {
    /*
     * Le point de conception. Une liste de relations serait incomplète dès
     * qu'une table s'ajoute, et une garde incomplète sur un geste irréversible
     * vaut à peine mieux que pas de garde.
     */
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path('Support/DependencyGuard.php')));

    expect($code)->toContain('Schema::getForeignKeys')
        ->and($code)->toContain('Schema::getTables');
});

test('un élément sans aucune dépendance ne déclare aucun blocage', function () {
    // Contre-épreuve : la garde ne bloque pas tout par principe.
    $batiment = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'Hangar neuf',
        'type' => 'chair', 'capacity' => 100, 'status' => Building::STATUS_VIDE,
    ]);

    expect(DependencyGuard::blockers($batiment))->toBe([]);
});

test('le contrôleur ne promet plus une garde qu’il ne ferait pas', function () {
    // C'est le commentaire d'origine qui a laissé passer le défaut : il DÉCRIVAIT
    // le contrôle absent, ce qui suffit à faire croire qu'il existe.
    $code = file_get_contents(app_path('Http/Controllers/TrashController.php'));

    expect($code)->toContain('DependencyGuard::blockers')
        ->and($code)->not->toContain("l'on pourrait vérifier");
});
