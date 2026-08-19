<?php

use App\Actions\Batch\TransferBatch;
use App\Models\Batch;
use App\Models\Building;
use App\Models\Protocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA CAPACITÉ D'UN BÂTIMENT ÉTAIT VÉRIFIÉE TROP TÔT, ET COMPTÉE DE TROIS FAÇONS.
 *
 * `BatchTransferController` n'avait AUCUN test. Voici ce qu'on y trouve.
 *
 * ─── 1. LE CONTRÔLE TOMBE HORS DE LA TRANSACTION ───
 *
 * La capacité disponible est vérifiée dans `TransferBatchRequest::withValidator`,
 * donc AVANT la transaction. L'action, elle, prend bien un `lockForUpdate` sur le
 * bâtiment cible — mais ne revérifie rien. Le verrou sérialise deux écritures
 * sans jamais reposer la question à laquelle il devrait servir.
 *
 * Deux techniciens qui mutent deux lots vers le même bâtiment au même moment
 * lisent tous les deux la même occupation, passent tous les deux, et le bâtiment
 * se retrouve en surcharge. Dans un poulailler, ce n'est pas un nombre qui
 * déborde : c'est la densité au m², donc le stress thermique et la mortalité.
 *
 * Et rien ne le rattrape après coup — la capacité n'est contrôlée qu'au moment de
 * la mutation.
 *
 * ─── 2. TROIS FAÇONS DE COMPTER L'OCCUPATION ───
 *
 *   • TransferBatchRequest  → somme des lots ACTIFS, en excluant le lot muté ;
 *   • UpdateBuildingConfig  → somme des lots actifs ;
 *   • Building::occupancy_rate → le PREMIER lot actif seulement.
 *
 * Le troisième est celui qui s'affiche. Deux bandes de 500 dans un bâtiment de
 * 1 000 annonçaient donc 50 % d'occupation quand il est plein — alors que la
 * coexistence de plusieurs lots est un cas explicitement prévu ailleurs (le vide
 * sanitaire ne démarre que « s'il ne reste plus aucun lot actif »).
 *
 * ─── POURQUOI CE TEST APPELLE L'ACTION DIRECTEMENT ───
 *
 * Reproduire deux requêtes SIMULTANÉES demanderait deux connexions et un ordre
 * d'exécution imposé — un test qui mesurerait surtout le moteur de base. On
 * présente donc à l'action exactement l'état qu'une seconde requête concurrente
 * lui présenterait : une mutation dont la validation est passée contre une
 * occupation devenue fausse. C'est le même choix que pour la sortie de matière
 * première (#278), et il est dit plutôt que tu.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->cible = Building::factory()->create([
        'farm_id'  => $this->farm->id,
        'name'     => 'Poulailler B2',
        'type'     => 'chair',
        'capacity' => 1000,
        'status'   => 'Disponible',
    ]);

    $this->protocole = Protocol::create([
        'name' => 'Protocole chair standard',
        'type' => 'chair',
    ]);
});

/** Un lot actif logé dans un bâtiment donné. */
function lotDe(int $farmId, int $buildingId, int $effectif): Batch
{
    return Batch::factory()->create([
        'farm_id'          => $farmId,
        'building_id'      => $buildingId,
        'initial_quantity' => $effectif,
        'current_quantity' => $effectif,
        'status'           => 'Actif',
    ]);
}

test('l’action REFUSE une mutation qui dépasse la capacité', function () {
    /*
     * LE défaut : la validation ayant déjà eu lieu, l'action acceptait sans
     * revérifier — exactement la situation d'une seconde mutation concurrente.
     */
    lotDe($this->farm->id, $this->cible->id, 700);           // déjà logés
    $lot = lotDe($this->farm->id, $this->building->id, 500);  // 700 + 500 > 1 000

    expect(fn () => app(TransferBatch::class)->execute($lot, [
        'target_building_id' => $this->cible->id,
        'new_protocol_id'    => $this->protocole->id,
        'new_phase'          => 'chair',
        'transfer_date'      => today()->toDateString(),
    ]))->toThrow(\Exception::class);

    expect($lot->fresh()->building_id)->toBe($this->building->id);
});

test('une mutation qui TIENT passe normalement', function () {
    /*
     * LA borne : on refuse la surcharge, pas la mutation. 700 + 300 = 1 000,
     * pile la capacité.
     */
    lotDe($this->farm->id, $this->cible->id, 700);
    $lot = lotDe($this->farm->id, $this->building->id, 300);

    app(TransferBatch::class)->execute($lot, [
        'target_building_id' => $this->cible->id,
        'new_protocol_id'    => $this->protocole->id,
        'new_phase'          => 'chair',
        'transfer_date'      => today()->toDateString(),
    ]);

    expect($lot->fresh()->building_id)->toBe($this->cible->id);
});

test('une transformation SUR PLACE ne se compte pas contre elle-même', function () {
    /*
     * La nuance que portait déjà la validation : un lot qui gradue sans changer
     * de bâtiment ne doit pas voir ses propres sujets occuper la place qu'il
     * demande. La déclaration unique doit la conserver.
     */
    $lot = lotDe($this->farm->id, $this->cible->id, 900);

    app(TransferBatch::class)->execute($lot, [
        'target_building_id' => $this->cible->id,
        'new_protocol_id'    => $this->protocole->id,
        'new_phase'          => 'ponte',
        'transfer_date'      => today()->toDateString(),
    ]);

    expect($lot->fresh()->production_phase)->toBe('ponte');
});

test('le taux d’occupation compte TOUS les lots, pas le premier', function () {
    /*
     * Le chiffre affiché contredisait la règle appliquée : deux bandes de 500
     * dans un bâtiment de 1 000 annonçaient 50 % au lieu de 100 %.
     */
    lotDe($this->farm->id, $this->cible->id, 500);
    lotDe($this->farm->id, $this->cible->id, 500);

    expect($this->cible->fresh()->occupancy_rate)->toBe(100.0);
});

test('l’occupation est comptée en un seul endroit', function () {
    /*
     * La garde contre le retour de la divergence : ni la validation ni la
     * reconfiguration ne resomment les lots chez elles.
     */
    $fichiers = [
        'app/Http/Requests/Batch/TransferBatchRequest.php',
        'app/Actions/Building/UpdateBuildingConfig.php',
    ];

    foreach ($fichiers as $fichier) {
        $code = file_get_contents(base_path($fichier));

        expect(str_contains($code, "sum('current_quantity')"))
            ->toBeFalse("Occupation resommée dans {$fichier}");
    }
});

test('un lot CLÔTURÉ n’occupe plus la place', function () {
    // Une bande terminée a quitté le bâtiment : ses sujets ne comptent plus.
    $ancien = lotDe($this->farm->id, $this->cible->id, 900);
    $ancien->update(['status' => 'Terminé']);

    expect($this->cible->fresh()->currentOccupation())->toBe(0);
});
