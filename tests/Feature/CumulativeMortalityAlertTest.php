<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\Setting;
use App\Services\CumulativeMortalityAlert;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'ALERTE DE MORTALITÉ CUMULÉE ÉTAIT MUETTE SUR LE GESTE QUOTIDIEN.
 *
 * L'alerte vivait sur l'événement Eloquent `updated` d'un lot. Mais le pointage
 * journalier — LE chemin par lequel la mortalité entre dans le système, tous les
 * jours, sur les deux sites — écrit l'effectif par requête directe pour éviter
 * une boucle d'observateurs. Aucun événement, donc aucune alerte.
 *
 * Et le défaut se refermait sur lui-même : la condition exige un FRANCHISSEMENT
 * (taux précédent sous le seuil, taux actuel au-dessus). Une fois la ligne rouge
 * passée en silence par accumulation de pointages, plus aucun événement ultérieur
 * ne pouvait alerter — le taux « précédent » était déjà au-dessus.
 *
 * Ce qui marchait : le pic quotidien. Une hécatombe brutale était signalée ; une
 * dérive lente — deux morts par jour pendant un mois — ne l'était jamais. C'est
 * l'inverse de ce qu'il faut pour piloter à distance : le pic se voit à l'œil nu
 * au bâtiment, la dérive ne se voit que dans les chiffres.
 */

beforeEach(function () {
    $this->setUpRbac();

    Setting::set('elevage.cumulative_mortality_alert_pct', 5);
    // On neutralise l'alerte de PIC quotidien, qui a sa propre règle : sans cela
    // on ne saurait pas laquelle des deux a parlé.
    Setting::set('elevage.daily_mortality_alert_min', 100000);

    $this->batch = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'status'           => Batch::STATUS_ACTIF,
        'initial_quantity' => 1000,
        'current_quantity' => 960,      // 4 % : sous le seuil
    ]);
});

/** Alertes de surmortalité présentes en base, tous canaux « database » confondus. */
function mortalityAlerts(): int
{
    return DB::table('notifications')
        ->where('data', 'like', '%high_mortality%')
        ->count();
}

test('un POINTAGE QUOTIDIEN qui franchit le seuil déclenche l’alerte', function () {
    // LE test de régression. Avant, ce geste — le plus banal de l'exploitation —
    // ne produisait rien du tout.
    expect(mortalityAlerts())->toBe(0);

    DailyCheck::create([
        'farm_id'    => $this->farm->id,
        'batch_id'   => $this->batch->id,
        'user_id'    => $this->adminUser->id,
        'check_date' => now()->toDateString(),
        'mortality'  => 15,          // 40 + 15 = 55 morts → 5,5 % > 5 %
    ]);

    expect($this->batch->fresh()->current_quantity)->toBe(945)
        ->and(mortalityAlerts())->toBeGreaterThan(0);
});

test('un pointage qui reste SOUS le seuil n’alerte pas', function () {
    DailyCheck::create([
        'farm_id'    => $this->farm->id,
        'batch_id'   => $this->batch->id,
        'user_id'    => $this->adminUser->id,
        'check_date' => now()->toDateString(),
        'mortality'  => 5,           // 45 morts → 4,5 %
    ]);

    expect(mortalityAlerts())->toBe(0);
});

test('l’alerte ne se répète pas à chaque pointage suivant', function () {
    // Sans la condition de franchissement, l'éleveur recevrait la même alerte
    // tous les jours jusqu'à la clôture du lot — et cesserait de les lire.
    foreach ([15, 3, 3] as $i => $deaths) {
        DailyCheck::create([
            'farm_id'    => $this->farm->id,
            'batch_id'   => $this->batch->id,
            'user_id'    => $this->adminUser->id,
            'check_date' => now()->addDays($i)->toDateString(),
            'mortality'  => $deaths,
        ]);
    }

    $first = DB::table('notifications')->where('data', 'like', '%high_mortality%')->count();

    // Une seule salve : celle du franchissement.
    expect($first)->toBeGreaterThan(0);

    $perAdmin = DB::table('notifications')
        ->where('data', 'like', '%high_mortality%')
        ->distinct()->count('notifiable_id');

    expect($first)->toBe($perAdmin, 'Une alerte par administrateur, et une seule.');
});

test('le chemin Eloquent alerte toujours (vente, transfert, correction)', function () {
    // L'ancien chemin ne doit pas avoir été perdu en déplaçant la règle.
    $this->batch->update(['current_quantity' => 940]);   // 6 %

    expect(mortalityAlerts())->toBeGreaterThan(0);
});

test('la règle est déclarée UNE fois, et les deux chemins l’appellent', function () {
    // Garde-fou de structure : c'est la dispersion qui a produit le défaut. Si
    // l'un des deux appels disparaît, ce test le dit avant le terrain.
    $observer = file_get_contents(app_path('Observers/BatchObserver.php'));
    $model    = file_get_contents(app_path('Models/DailyCheck.php'));

    expect($observer)->toContain('CumulativeMortalityAlert')
        ->and($model)->toContain('CumulativeMortalityAlert');

    // Et l'ancienne logique ne doit pas subsister en double dans l'observateur.
    expect($observer)->not->toContain('cumulativeMortalityThreshold');
});

test('un lot clôturé n’alerte plus', function () {
    $this->batch->update(['status' => 'Terminé']);

    app(CumulativeMortalityAlert::class)->evaluate($this->batch->fresh(), 960);

    expect(mortalityAlerts())->toBe(0);
});

test('un effectif initial nul ne provoque pas de division par zéro', function () {
    $odd = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'status' => Batch::STATUS_ACTIF,
        'initial_quantity' => 0, 'current_quantity' => 0,
    ]);

    app(CumulativeMortalityAlert::class)->evaluate($odd, 0);

    expect(mortalityAlerts())->toBe(0);
});

test('corriger un pointage à la hausse peut franchir le seuil', function () {
    // Le diff passe par le même chemin d'écriture directe : la correction d'une
    // saisie doit alerter comme la saisie initiale.
    $check = DailyCheck::create([
        'farm_id'    => $this->farm->id,
        'batch_id'   => $this->batch->id,
        'user_id'    => $this->adminUser->id,
        'check_date' => now()->toDateString(),
        'mortality'  => 5,           // 4,5 % : pas d'alerte
    ]);

    expect(mortalityAlerts())->toBe(0);

    $check->update(['mortality' => 20]);   // 60 morts → 6 %

    expect($this->batch->fresh()->current_quantity)->toBe(940)
        ->and(mortalityAlerts())->toBeGreaterThan(0);
});
