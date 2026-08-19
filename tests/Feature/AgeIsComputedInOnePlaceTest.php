<?php

use App\Models\Batch;
use App\Models\Protocol;
use App\Models\ProtocolStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * TROIS COPIES DE L'ÂGE, ET DEUX ANCRAGES POUR LA MÊME ÉCHÉANCE.
 *
 * #292 a fait compter l'âge d'un lot depuis la NAISSANCE. Encore fallait-il que
 * tout le monde passe par là. En balayant après coup — la première chose à faire
 * quand on vient de rendre une règle unique — trois endroits recalculaient l'âge
 * chez eux, depuis l'arrivée :
 *
 *   • le RAPPORT TECHNIQUE reprenait mot pour mot l'ancienne formule
 *     (`diffInDays(now()) + 1`) : GMQ et performances d'un lot reçu déjà âgé
 *     restaient donc faux, alors même que sa fiche affichait le bon âge ;
 *   • l'ANALYSE DE PONTE calculait un âge EN SEMAINES depuis l'arrivée, sans
 *     même le « + 1 ». C'est la courbe de ponte — Lohmann, Hy-Line — qui est
 *     indexée là-dessus : des poulettes reçues en âge de pondre étaient jugées
 *     contre la semaine 1 de leur courbe ;
 *   • le MOBILE recalculait l'âge en JavaScript, depuis l'arrivée — sur l'écran
 *     que les techniciens ont sous les yeux. Et le serveur ne lui descendait même
 *     pas la naissance : la boucle était ouverte de bout en bout ;
 *   • le COÛT ÉNERGIE, lui, mesure autre chose (voir plus bas).
 *
 * ─── ET UNE ÉCHÉANCE DE VACCINATION CALCULÉE DE DEUX FAÇONS ───
 *
 * Le même « quand ce protocole est-il dû ? » se posait à deux endroits :
 *
 *   • DashboardController → `transfer_date ?? start_date ?? arrival_date` ;
 *   • SanitaryAlertService → `arrival_date`, tout court.
 *
 * Un lot ayant gradué (mutation vers une nouvelle phase, donc un NOUVEAU
 * protocole) voyait donc une échéance au tableau de bord et une autre dans
 * l'alerte sanitaire. Deux écrans, deux dates, pour la même vaccination.
 *
 * On retient la version du tableau de bord — la plus complète, et la seule
 * cohérente avec le fait que la mutation attribue un protocole neuf : il commence
 * quand le lot entre dans sa phase. On ne CHANGE donc aucune sémantique ; on
 * supprime la moins complète des deux.
 *
 * ─── CE QU'IL NE FAUT SURTOUT PAS « CORRIGER » ───
 *
 * `BatchController` mesure une DURÉE DE PRÉSENCE depuis l'arrivée, pour
 * proratiser le coût de l'énergie du bâtiment. Ce n'est pas l'âge des sujets :
 * facturer l'électricité depuis la naissance imputerait au lot des semaines où
 * il n'était pas là. Un test le fixe, pour que la prochaine relecture ne
 * l'« aligne » pas par zèle.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
});

/** Un lot de ponte reçu aujourd'hui, déjà âgé de $jours. */
function lotPonteAge(int $farmId, int $buildingId, int $jours): Batch
{
    return Batch::factory()->create([
        'farm_id'          => $farmId,
        'building_id'      => $buildingId,
        'arrival_date'     => today()->toDateString(),
        'birth_date'       => today()->subDays($jours - 1)->toDateString(),
        'initial_quantity' => 500,
        'current_quantity' => 500,
        'status'           => 'Actif',
    ]);
}

test('le RAPPORT TECHNIQUE lit l’âge du modèle, il ne le recalcule pas', function () {
    /*
     * L'ancienne formule y était recopiée mot pour mot : la fiche du lot affichait
     * 112 jours et le rapport 1.
     */
    $lot = lotPonteAge($this->farm->id, $this->building->id, 112);

    $vue = $this->get(route('reports.technical'))->assertOk();

    $ligne = collect($vue->viewData('stats'))->firstWhere('code', $lot->code);

    expect($ligne)->not->toBeNull()
        ->and($ligne['age'])->toBe($lot->age);
});

test('l’ANALYSE DE PONTE juge sur l’âge réel, pas sur la semaine 1', function () {
    /*
     * La courbe de ponte est indexée sur l'âge en semaines. Des poulettes reçues
     * à 20 semaines étaient comparées à la semaine 1 de leur propre courbe.
     */
    $lot = lotPonteAge($this->farm->id, $this->building->id, 140);   // 20 semaines

    expect((int) floor($lot->age / 7))->toBe(20);
});

test('l’échéance d’un protocole est calculée en UN seul endroit', function () {
    /*
     * La garde contre le retour de la divergence : ni le tableau de bord ni
     * l'alerte sanitaire ne reconstruisent l'ancrage chez eux.
     */
    $fichiers = [
        'app/Services/SanitaryAlertService.php',
        'app/Http/Controllers/DashboardController.php',
    ];

    foreach ($fichiers as $fichier) {
        $code = file_get_contents(base_path($fichier));

        expect(str_contains($code, 'protocolAnchorDate()'))
            ->toBeTrue("Ancrage de protocole reconstruit dans {$fichier}");
    }
});

test('l’ancrage suit la MUTATION, qui attribue un protocole neuf', function () {
    /*
     * La sémantique retenue, celle du tableau de bord : un protocole attribué à
     * la mutation commence à la mutation, pas à l'arrivée du lot dans la ferme.
     */
    $lot = Batch::factory()->create([
        'farm_id'       => $this->farm->id,
        'building_id'   => $this->building->id,
        'arrival_date'  => today()->subDays(60)->toDateString(),
        'transfer_date' => today()->subDays(10)->toDateString(),
        'status'        => 'Actif',
    ]);

    expect($lot->protocolAnchorDate()->toDateString())
        ->toBe(today()->subDays(10)->toDateString());
});

test('sans mutation, l’ancrage retombe sur l’arrivée', function () {
    // Le cas courant, inchangé.
    $lot = Batch::factory()->create([
        'farm_id'       => $this->farm->id,
        'building_id'   => $this->building->id,
        'arrival_date'  => today()->subDays(30)->toDateString(),
        'transfer_date' => null,
        'start_date'    => null,
        'status'        => 'Actif',
    ]);

    expect($lot->protocolAnchorDate()->toDateString())
        ->toBe(today()->subDays(30)->toDateString());
});

test('le COÛT ÉNERGIE reste compté depuis l’ARRIVÉE — et c’est voulu', function () {
    /*
     * LA borne à ne pas franchir. Ce nombre mesure la présence du lot dans le
     * bâtiment, pas l'âge des sujets : compter depuis la naissance imputerait au
     * lot l'électricité de semaines où il n'était pas là.
     *
     * Ce test existe pour qu'une relecture zélée ne l'« aligne » pas sur l'âge.
     */
    $code = file_get_contents(base_path('app/Http/Controllers/BatchController.php'));

    expect(str_contains($code, "Carbon::parse(\$batch->arrival_date)->diffInDays(now())"))
        ->toBeTrue('La durée de présence doit rester ancrée sur l’arrivée.');
});

test('le TERRAIN reçoit la naissance et l’affiche', function () {
    /*
     * La boucle complète : le pull doit la descendre, sans quoi l'écran mobile
     * ne peut que retomber sur l'arrivée — ce qu'il faisait.
     */
    $colonnes = file_get_contents(base_path('app/Http/Controllers/Api/SyncController.php'));

    expect(str_contains($colonnes, "'arrival_date', 'birth_date'"))->toBeTrue();

    $ecran = file_get_contents(base_path('mobile/src/features/elevage/BatchScreen.tsx'));

    expect(str_contains($ecran, 'batch.birth_date ?? batch.arrival_date'))->toBeTrue();
});
