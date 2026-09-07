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

    /*
     * 40 morts À L'ARRIVAGE, donc un lot déjà à 3,85 % — sous le seuil.
     *
     * Ce décor portait `current_quantity => 960` et rien d'autre, en appelant
     * cela « 40 morts ». Il n'y en avait aucun : c'était une baisse d'effectif,
     * exactement la grandeur que l'alerte confondait avec la mortalité. Le
     * décor exprimait donc le défaut, et les tests qui s'appuyaient dessus le
     * validaient. Les 40 morts sont désormais écrits là où le modèle les compte.
     */
    $this->batch = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'status'           => Batch::STATUS_ACTIF,
        'initial_quantity' => 1000,
        'current_quantity' => 960,
        'qty_dead'         => 40,
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

test('le chemin Eloquent alerte sur les MORTS À L’ARRIVAGE', function () {
    /*
     * L'ancien chemin ne doit pas avoir été perdu en déplaçant la règle — mais
     * il écoutait `current_quantity`, et ce test affirmait qu'une VENTE devait
     * alerter. C'était le défaut érigé en attendu.
     *
     * Côté lot, la mortalité c'est `qty_dead` : les morts constatées à
     * l'arrivage. 40 → 60 sur 1 000 mis en place fait 5,77 %.
     */
    $this->batch->update(['qty_dead' => 60]);

    expect(mortalityAlerts())->toBeGreaterThan(0);
});

test('une VENTE n’est pas une hécatombe', function () {
    /*
     * LE défaut, mesuré : vendre 400 sujets vifs sur 1 000 faisait tomber
     * l'effectif à 600, et l'alerte annonçait « Mortalité critique franchie :
     * 40 % » — sans un seul mort.
     *
     * L'effectif baisse de tout ce qui sort VIVANT : ventes, expéditions,
     * dispatch de poussins, abattoir, transferts entre lots. Aucun de ces
     * mouvements ne tue quoi que ce soit.
     */
    $this->batch->update(['current_quantity' => 600]);

    expect(mortalityAlerts())->toBe(0);
});

test('une vente n’ÉTEINT pas l’alerte de la vraie dérive qui suit', function () {
    /*
     * LA borne qui donne au défaut sa gravité, et le piège auto-refermant que
     * l'en-tête de ce fichier décrit pour le défaut d'origine :
     *
     * la condition exige un FRANCHISSEMENT. La vente ayant porté le taux
     * « précédent » à 40 %, la mortalité réelle qui survenait ensuite ne pouvait
     * plus rien franchir. Une vente allumait une fausse alarme PUIS rendait le
     * lot définitivement muet.
     */
    $this->batch->update(['current_quantity' => 600]);   // 400 vendus

    expect(mortalityAlerts())->toBe(0);

    // Puis la vraie dérive : 40 morts à l'arrivage + 20 pointés = 6 %.
    DailyCheck::create([
        'farm_id'    => $this->farm->id,
        'batch_id'   => $this->batch->id,
        'user_id'    => $this->adminUser->id,
        'check_date' => now()->toDateString(),
        'mortality'  => 20,
    ]);

    expect(mortalityAlerts())->toBeGreaterThan(0);
});

test('les morts EN INFIRMERIE comptent aussi', function () {
    /*
     * L'autre moitié du défaut, et elle était totalement muette.
     *
     * Un sujet mort à l'infirmerie a déjà été retiré de l'effectif à son
     * isolement : sa mort ne fait plus bouger `current_quantity` d'une unité.
     * Sous l'ancienne règle — le taux lu sur la baisse d'effectif — ces morts-là
     * ne pouvaient JAMAIS rien franchir, quel qu'en soit le nombre.
     *
     * `Batch::total_mortality` les compte pourtant explicitement : le modèle
     * savait qu'un sujet mort en infirmerie est un sujet mort.
     */
    DailyCheck::create([
        'farm_id'             => $this->farm->id,
        'batch_id'            => $this->batch->id,
        'user_id'             => $this->adminUser->id,
        'check_date'          => now()->toDateString(),
        'mortality'           => 0,
        'mortality_infirmary' => 20,      // 40 + 20 = 60 morts → 5,77 %
    ]);

    expect(mortalityAlerts())->toBeGreaterThan(0);
});

test('une mise en QUARANTAINE n’alerte pas non plus', function () {
    /*
     * L'appel vivait dans `applyBatchImpact`, qui applique le delta d'effectif
     * d'un pointage — mortalité, quarantaine, retours de quarantaine et tri
     * confondus. Isoler des sujets malades les retire de l'effectif ; ils sont
     * vivants, et c'est même la raison de les isoler.
     */
    DailyCheck::create([
        'farm_id'           => $this->farm->id,
        'batch_id'          => $this->batch->id,
        'user_id'           => $this->adminUser->id,
        'check_date'        => now()->toDateString(),
        'mortality'         => 0,
        'qty_quarantine_in' => 100,
    ]);

    expect(mortalityAlerts())->toBe(0);
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
