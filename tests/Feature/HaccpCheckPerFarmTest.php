<?php

use App\Models\Farm;
use App\Models\TemperatureLog;
use App\Services\NotificationHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN SITE QUI TRAVAILLE COUVRAIT TOUS LES AUTRES.
 *
 * Le contrôle de complétude des registres HACCP comptait les relevés de
 * température ainsi :
 *
 *     TemperatureLog::withoutGlobalScopes()->whereDate('releve_at', today())->count()
 *
 * Sans regroupement par ferme — puis comparait ce total à une exigence
 * QUOTIDIENNE PAR SITE (`abattoir.temp_readings_per_day`, réglage global).
 *
 * Sur deux sites, les deux relevés faits à Kindia satisfaisaient donc
 * l'exigence de Kérouané : le registre y restait vide, et l'alerte du soir ne
 * partait jamais. Plus il y a de sites, plus le contrôle est aveugle — il
 * suffit qu'UN seul travaille pour couvrir tous les autres.
 *
 * ─── POURQUOI CELA COMPTE ───
 *
 * L'en-tête de la commande le dit lui-même : « Un registre incomplet découvert
 * le soir se rattrape ; découvert par l'inspecteur, il coûte l'agrément. » Le
 * registre est nominatif par ÉTABLISSEMENT — c'est l'agrément du site qui en
 * dépend, pas une moyenne d'entreprise.
 *
 * ─── LA COMMANDE SŒUR LE FAISAIT DÉJÀ ───
 *
 * `CheckAttendanceGaps`, dans le même dossier, boucle sur `Farm::active()` et
 * explique pourquoi : « Chaque site a son propre pointage : une alerte globale
 * ne dirait pas OÙ la feuille manque, donc à qui la demander. » La règle était
 * écrite, appliquée à côté, et absente ici.
 */

beforeEach(function () {
    $this->setUpRbac();

    // Le décor : EXACTEMENT deux sites actifs, comme chez le promoteur
    // (Kindia et Kérouané). La base de test en porte d'autres.
    Farm::query()->update(['is_active' => false]);
    $this->farm->update(['is_active' => true]);

    // Le second site du promoteur.
    $this->autreFerme = Farm::create([
        'code' => 'FT-002', 'name' => 'Ferme Kérouané', 'is_active' => true,
    ]);

    \App\Models\Setting::set('abattoir.temp_readings_per_day', 2);
});

/** Enregistre un relevé de température sur le site donné. */
function releve(int $farmId, int $operatorId, float $temperature = 2.0): TemperatureLog
{
    return TemperatureLog::create([
        'farm_id' => $farmId,
        'operator_id' => $operatorId,
        'point' => array_key_first(TemperatureLog::POINTS),
        'temperature' => $temperature,
        'conforme' => true,
        'releve_at' => now(),
    ]);
}

/** Lance le contrôle et capte les titres d'alertes émises. */
function alertesHaccp(): array
{
    $titres = [];

    $hub = Mockery::mock(NotificationHub::class);
    $hub->shouldReceive('alertHaccp')
        ->andReturnUsing(function ($message, $title, $severity = 'normal') use (&$titres) {
            $titres[] = $title . ' :: ' . $message;
        });

    app()->instance(NotificationHub::class, $hub);

    Artisan::call('haccp:check-registers');

    return $titres;
}

test('un site sans relevé est alerté même si l’autre a fait les siens', function () {
    /*
     * LE défaut : deux relevés à Kindia couvraient Kérouané, dont le registre
     * était pourtant vide.
     */
    releve($this->farm->id, $this->adminUser->id);
    releve($this->farm->id, $this->adminUser->id);

    $alertes = implode(' | ', alertesHaccp());

    expect($alertes)->toContain('Kérouané');
});

test('le site EN RÈGLE n’est pas alerté', function () {
    // La borne : une alerte qui part toujours ne se lit plus.
    releve($this->farm->id, $this->adminUser->id);
    releve($this->farm->id, $this->adminUser->id);
    releve($this->autreFerme->id, $this->adminUser->id);
    releve($this->autreFerme->id, $this->adminUser->id);

    expect(alertesHaccp())->toBeEmpty();
});

test('l’alerte NOMME le site concerné', function () {
    /*
     * Le promoteur est à l'étranger : « registre incomplet » sans le nom du
     * site ne dit pas à qui téléphoner.
     */
    releve($this->farm->id, $this->adminUser->id);
    releve($this->farm->id, $this->adminUser->id);

    $alertes = implode(' | ', alertesHaccp());

    expect($alertes)->toContain('Ferme Kérouané')
        ->and($alertes)->toContain('0/2');
});

test('chaque site incomplet reçoit SA propre alerte', function () {
    // Aucun relevé nulle part : autant d'alertes que de sites actifs, chacune
    // nommant le sien. Une alerte globale n'en aurait émis qu'une.
    $alertes = alertesHaccp();

    expect($alertes)->toHaveCount(Farm::active()->count())
        ->and(implode(' | ', $alertes))->toContain('Ferme Kérouané');
});

test('un site désactivé n’est plus contrôlé', function () {
    /*
     * Le pendant de la commande sœur, dont le commentaire note que
     * `withoutGlobalScopes()` incluait les sites SUPPRIMÉS, « qui recevaient
     * donc des alertes ». On boucle sur `Farm::active()`, pas sur toutes.
     */
    $this->autreFerme->update(['is_active' => false]);

    releve($this->farm->id, $this->adminUser->id);
    releve($this->farm->id, $this->adminUser->id);

    expect(alertesHaccp())->toBeEmpty();
});
