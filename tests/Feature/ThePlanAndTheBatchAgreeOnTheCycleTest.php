<?php

use App\Models\Batch;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'ÉCRAN DE PLANIFICATION ET LA BANDE NE DISAIENT PAS LA MÊME DURÉE DE CYCLE.
 *
 * `elevage.cycle_chair` était lu à deux endroits avec deux replis :
 *
 *   • `Batch::recalcExpectedEndDate()`   → setting(…, 45)
 *   • `planning/create.blade.php`        → setting(…, 42)
 *
 * Et la migration qui SÈME ce réglage livre 42. Le 45 du modèle ne
 * correspondait donc à rien : ni à la valeur livrée, ni à celle qu'annonce
 * l'écran. C'était un troisième chiffre, pas une sécurité.
 *
 * ─── QUAND CELA MORD ───
 *
 * Tant que le réglage est renseigné — c'est-à-dire sur toute installation
 * fraîche, puisqu'il est semé — les deux s'accordent. Vidé à l'écran des
 * Réglages, cas désormais traité comme une absence (cf. `Setting::get`), les
 * replis divergent : le planning propose l'abattage à J+42 pendant que la bande
 * porte une fin de cycle à J+45.
 *
 * Trois jours d'écart sur la SEULE date que ces deux écrans servent à donner.
 *
 * ─── CE QUE CE DÉFAUT N'EST PAS ───
 *
 * Il ne coûte pas d'argent et ne se déclenche qu'après un geste précis. Il est
 * retenu parce qu'il est OBJECTIF — un repli qui contredit la valeur livrée —
 * et parce que le remède est celui que ce dépôt a déjà posé deux fois :
 * `Building::sanitaryBreakDays()`, `Sale::paymentDelayDays()`.
 *
 * Les replis vivent maintenant dans `Batch::CYCLE_DEFAUTS`, une fois, et
 * reprennent les valeurs semées par les migrations.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

/** Efface le réglage : case vidée à l'écran des Réglages. */
function sansReglageDeCycle(): void
{
    Setting::where('group', 'elevage')->where('key', 'cycle_chair')->delete();
    Cache::flush();
}

/** Renseigne la durée de cycle, comme le ferait l'écran. */
function reglerLeCycleChair(int $jours): void
{
    Setting::updateOrCreate(
        ['group' => 'elevage', 'key' => 'cycle_chair'],
        ['value' => (string) $jours, 'type' => 'number', 'label' => 'Durée cycle chair', 'unit' => 'jours'],
    );

    Cache::flush();
}

test('réglage vidé : le repli du modèle est celui qui est LIVRÉ', function () {
    /*
     * LE défaut : le modèle repliait sur 45, un chiffre qui n'apparaît ni dans
     * la migration qui sème le réglage, ni dans l'écran qui l'annonce.
     */
    sansReglageDeCycle();

    expect(Batch::cycleJours('elevage.cycle_chair'))->toBe(42);
});

test('et la bande porte une fin de cycle à cette même durée', function () {
    /*
     * L'effet réel : `expected_end_date` est la date que le planning, les
     * alertes et les écrans de bande affichent tous.
     */
    sansReglageDeCycle();

    $bande = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'type' => 'chair', 'status' => 'Actif',
        'arrival_date' => '2026-06-01', 'birth_date' => '2026-06-01',
        'production_type_id' => null,
    ]);

    expect($bande->fresh()->expected_end_date->toDateString())->toBe('2026-07-13');   // 1er juin + 42
});

test('l’écran de planification annonce la MÊME durée', function () {
    /*
     * L'invariant : deux lecteurs de la même durée doivent dire le même nombre.
     * Leur désaccord était la maladie.
     */
    sansReglageDeCycle();

    $html = $this->get(route('planning.create'))->assertOk()->getContent();

    expect($html)->toContain('chair: ' . Batch::cycleJours('elevage.cycle_chair'));
});

test('un cycle RÉGLÉ est suivi par les deux — non-régression', function () {
    // Le cas courant : le réglage est renseigné, les deux le lisent.
    reglerLeCycleChair(50);

    $bande = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'type' => 'chair', 'status' => 'Actif',
        'arrival_date' => '2026-06-01', 'birth_date' => '2026-06-01',
        'production_type_id' => null,
    ]);

    $html = $this->get(route('planning.create'))->assertOk()->getContent();

    expect($bande->fresh()->expected_end_date->toDateString())->toBe('2026-07-21')   // +50
        ->and($html)->toContain('chair: 50');
});

test('les replis reprennent les valeurs SEMÉES par les migrations', function () {
    /*
     * La garde qui empêche un repli inventé de revenir. Un chiffre qui
     * contredit ce qui est livré n'est pas une sécurité : c'est une troisième
     * déclaration, et c'est exactement ce défaut.
     */
    $semees = \Illuminate\Support\Facades\DB::table('settings')
        ->where('group', 'elevage')
        ->where('key', 'like', 'cycle_%')
        ->pluck('value', 'key');

    foreach (Batch::CYCLE_DEFAUTS as $cle => $repli) {
        $courte = str_replace('elevage.', '', $cle);

        if (! isset($semees[$courte])) {
            continue;   // clef non semée : pas de référence à confronter
        }

        expect((int) $semees[$courte])->toBe($repli, "repli de {$cle}");
    }
});

test('la déclaration est UNIQUE : plus aucun lecteur ne porte son propre repli', function () {
    /*
     * La garde qui empêche la divergence de renaître. Un troisième lecteur qui
     * relirait le réglage en direct rouvrirait exactement ce défaut — et c'est
     * ainsi qu'il est né.
     */
    $sources = [
        file_get_contents(base_path('app/Models/Batch.php')),
        file_get_contents(resource_path('views/planning/create.blade.php')),
    ];

    $lecturesDirectes = 0;
    foreach ($sources as $src) {
        // Les commentaires CITENT l'ancien code pour l'expliquer : les compter
        // ferait échouer cette garde sur sa propre documentation.
        $sansCommentaires = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#', '#\{\{--.*?--\}\}#s'], '', $src);
        $lecturesDirectes += preg_match_all("/setting\(\s*'elevage\.cycle_/", $sansCommentaires);
    }

    expect($lecturesDirectes)->toBe(0);   // tout passe par cycleJours()
});

test('une clef inconnue retombe sur un repli raisonnable — borne', function () {
    // `cycleJours` ne doit pas rendre zéro sur une clef absente de la table :
    // une durée nulle ferait tomber la fin de cycle le jour de l'arrivée.
    expect(Batch::cycleJours('elevage.cycle_inexistant'))->toBeGreaterThan(0);
});
