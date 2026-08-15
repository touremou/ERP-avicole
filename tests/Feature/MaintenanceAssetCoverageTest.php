<?php

use App\Contracts\MaintainableAsset;
use App\Models\EnergySource;
use App\Models\MillMachine;
use App\Models\TaskAssignment;
use Illuminate\Support\Facades\Artisan;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA MAINTENANCE PRÉVENTIVE NE COUVRAIT QU'UNE FAMILLE D'ACTIFS SUR DEUX.
 *
 * `maintenance:check` engendre une tâche pour les actifs dont l'entretien est
 * dû. Elle ne parcourait que les GROUPES ÉLECTROGÈNES.
 *
 * Les machines de provenderie portent pourtant le même indicateur
 * `needs_maintenance`, alimenté à chaque clôture d'ordre de fabrication : les
 * heures tournées s'accumulent (#227 l'avait justement réparé), le seuil se
 * franchit… et rien ne partait. Le seul autre lecteur de cet indicateur était
 * un badge de couleur sur un écran du bureau, et une méthode `isAvailable()`
 * que PERSONNE n'appelle. Un indicateur avec des lecteurs et aucun acteur —
 * le motif dominant de cet audit.
 *
 * L'ENJEU EST MÉCANIQUE, pas administratif : un broyeur qui dépasse son
 * intervalle continue de tourner jusqu'à la casse. L'atelier de provenderie est
 * ce qui tourne le plus ici, et le promoteur, à l'étranger, n'a que les alertes
 * pour le savoir.
 *
 * LE GARDE EST DÉRIVÉ : il cherche dans les modèles ceux qui exposent
 * `needs_maintenance` et exige de chacun qu'il implémente le contrat ET soit
 * parcouru par la commande. Une troisième famille d'actifs — un véhicule, une
 * chambre froide — ne pourra plus être oubliée en silence, ce qui est
 * exactement ce qui est arrivé à celle-ci.
 */

beforeEach(function () {
    $this->setUpRbac();
});

/** Machine de provenderie ayant dépassé son intervalle d'entretien. */
function machineEnRetard(int $farmId, float $heures = 150, float $intervalle = 100): MillMachine
{
    return MillMachine::create([
        'farm_id' => $farmId, 'name' => 'Broyeur A', 'type' => 'Broyeur',
        'capacity_per_hour' => 500, 'maintenance_interval_hours' => $intervalle,
        'total_hours_run' => $heures, 'status' => 'Opérationnel',
    ]);
}

test('une machine de provenderie DUE engendre une tâche de maintenance', function () {
    // LE défaut : ce cas ne produisait rien du tout.
    machineEnRetard($this->farm->id);

    Artisan::call('maintenance:check');

    $tache = TaskAssignment::withoutGlobalScopes()
        ->where('category', 'maintenance_preventive')->first();

    expect($tache)->not->toBeNull()
        ->and($tache->title)->toContain('Broyeur A');
});

test('la tâche dit ce qu’il faut savoir : compteur, intervalle, points à vérifier', function () {
    // Une tâche sans contenu se referme sans être faite.
    machineEnRetard($this->farm->id, 150, 100);

    Artisan::call('maintenance:check');

    $tache = TaskAssignment::withoutGlobalScopes()
        ->where('category', 'maintenance_preventive')->first();

    expect($tache->description)->toContain('150')
        ->and($tache->description)->toContain('100')
        ->and($tache->description)->toContain('courroies');
});

test('un dépassement FRANC passe en priorité haute', function () {
    // Atteindre le seuil et le dépasser de moitié ne sont pas la même urgence.
    machineEnRetard($this->farm->id, 101, 100);
    Artisan::call('maintenance:check');
    expect(TaskAssignment::withoutGlobalScopes()->first()->priority)->toBe('normale');

    TaskAssignment::withoutGlobalScopes()->delete();
    MillMachine::withoutGlobalScopes()->update(['total_hours_run' => 150]);

    Artisan::call('maintenance:check');
    expect(TaskAssignment::withoutGlobalScopes()->first()->priority)->toBe('haute');
});

test('une machine SOUS le seuil ne génère rien', function () {
    // On ne rend pas la commande bavarde : c'est l'échéance qui déclenche.
    machineEnRetard($this->farm->id, 40, 100);

    Artisan::call('maintenance:check');

    expect(TaskAssignment::withoutGlobalScopes()->count())->toBe(0);
});

test('une machine DÉSACTIVÉE est ignorée', function () {
    // Un actif retiré du service ne se révise pas.
    $machine = machineEnRetard($this->farm->id);
    $machine->update(['status' => 'Désactivé']);

    Artisan::call('maintenance:check');

    expect(TaskAssignment::withoutGlobalScopes()->count())->toBe(0);
});

test('l’idempotence tient : deux passages, une seule tâche', function () {
    // Le planificateur passe tous les jours ; empiler une tâche par jour de
    // retard rendrait la liste illisible et l'alerte inutile.
    machineEnRetard($this->farm->id);

    Artisan::call('maintenance:check');
    Artisan::call('maintenance:check');

    expect(TaskAssignment::withoutGlobalScopes()->count())->toBe(1);
});

test('le groupe électrogène reste couvert (non-régression)', function () {
    // La règle du groupe est INCHANGÉE, seulement déplacée sous un nom commun :
    // compteur en alerte, ou échéance calendaire dans les 48 h.
    EnergySource::create([
        'farm_id' => $this->farm->id, 'name' => 'Groupe Perkins', 'type' => 'groupe',
        'total_hours_run' => 495, 'maintenance_interval_hours' => 500,
        'status' => 'operationnel', 'is_active' => true,
    ]);

    Artisan::call('maintenance:check');

    expect(TaskAssignment::withoutGlobalScopes()->where('title', 'like', '%Perkins%')->count())->toBe(1);
});

test('les deux familles sont traitées dans le MÊME passage', function () {
    machineEnRetard($this->farm->id);
    EnergySource::create([
        'farm_id' => $this->farm->id, 'name' => 'Groupe Perkins', 'type' => 'groupe',
        'total_hours_run' => 495, 'maintenance_interval_hours' => 500,
        'status' => 'operationnel', 'is_active' => true,
    ]);

    Artisan::call('maintenance:check');

    expect(TaskAssignment::withoutGlobalScopes()->count())->toBe(2);
});

test('TOUT modèle exposant needs_maintenance est couvert par la commande', function () {
    /*
     * La garde dérivée. Elle cherche l'indicateur dans les modèles plutôt que
     * de s'appuyer sur une liste écrite à la main — une telle liste aurait
     * exactement le défaut qu'elle surveille, et c'est ainsi que la provenderie
     * était passée à travers.
     */
    $commande = file_get_contents(app_path('Console/Commands/CheckMaintenanceAlerts.php'));

    $manquants = [];

    foreach (glob(app_path('Models/*.php')) as $fichier) {
        $source = file_get_contents($fichier);

        if (! str_contains($source, 'function getNeedsMaintenanceAttribute')) {
            continue;
        }

        $classe = 'App\\Models\\' . basename($fichier, '.php');

        if (! in_array(MaintainableAsset::class, class_implements($classe) ?: [], true)) {
            $manquants[] = "{$classe} n'implémente pas MaintainableAsset";
            continue;
        }

        if (! str_contains($commande, basename($fichier, '.php') . '::class')) {
            $manquants[] = "{$classe} n'est pas parcouru par maintenance:check";
        }
    }

    expect($manquants)->toBe([]);
});

test('la garde trouve bien les modèles qu’elle prétend surveiller', function () {
    // Un test dérivé qui ne trouve rien passerait à vide.
    $trouves = 0;

    foreach (glob(app_path('Models/*.php')) as $fichier) {
        if (str_contains(file_get_contents($fichier), 'function getNeedsMaintenanceAttribute')) {
            $trouves++;
        }
    }

    expect($trouves)->toBeGreaterThanOrEqual(2);
});
