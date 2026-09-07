<?php

use App\Actions\Hr\RecordAttendance;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Farm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA GRILLE PROPOSAIT L'AGENT PRÊTÉ, ET L'ENREGISTREMENT LE JETAIT.
 *
 * `RecordAttendance` garde le pointage dans le périmètre du site — c'est
 * l'anti-injection, et il est nécessaire. Mais il interrogeait
 * `Employee::whereKey(...)` tel quel, donc sous le scope de ferme : « rattaché à
 * ce site ».
 *
 * Les deux écrans qui remplissent la grille — la grille web et le miroir mobile
 * — listent, eux, `assignableInCurrentFarm()`, qui repose sur l'AFFECTATION
 * datée et inclut donc les agents PRÊTÉS.
 *
 * L'agent prêté était donc proposé à la saisie, coché présent, et sa ligne
 * écartée en silence à l'enregistrement.
 *
 * Mesuré, sur le site d'accueil, grille de deux agents — un local, un prêté :
 * `saved: 1, skipped: 1`, aucune ligne pour le prêté. L'écran annonçait
 * « Présence enregistrée pour 1 employé(s) ».
 *
 * ─── LE TROISIÈME ENDROIT ───
 *
 * Le défaut « agent prêté » avait déjà été corrigé deux fois, et le modèle le
 * raconte : d'abord rendre sa fiche VISIBLE (elle renvoyait 404), puis le rendre
 * DÉSIGNABLE dans les sélecteurs (« visible mais inutilisable »). La règle unique
 * existe — `Employee::visibleInFarm`. Ce garde-ci était le troisième endroit,
 * celui qui ÉCRIT, et il ne l'avait jamais lue.
 *
 * On retient la VISIBILITÉ et non `assignable` (qui exige en plus « Actif ») :
 * le rôle de ce garde est le périmètre, pas le statut. Sinon, corriger le
 * pointage du matin d'un agent suspendu l'après-midi deviendrait impossible.
 *
 * ─── ET LE COMPTEUR D'ÉCARTÉS ÉTAIT JETÉ ───
 *
 * `RecordAttendance` rend `skipped` depuis toujours. Les DEUX appelants le
 * jetaient : la grille web annonçait le seul nombre d'enregistrés, et la synchro
 * renvoyait « success » sans lui — le téléphone retirait donc l'opération de sa
 * file, pointages perdus, personne averti. C'est ce silence qui a rendu le
 * défaut invisible.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->siteAccueil = Farm::create([
        'code' => 'SITE-B', 'name' => 'Site Kindia', 'is_active' => true,
    ]);

    foreach ([$this->farm->id, $this->siteAccueil->id] as $siteId) {
        DB::table('farm_user')->updateOrInsert(
            ['farm_id' => $siteId, 'user_id' => $this->adminUser->id],
            [
                'is_default' => $siteId === $this->farm->id,
                'is_owner'   => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );
    }
});

/** Un agent du site d'origine, mis à disposition du site d'accueil. */
function agentPreteAuSite(int $farmOrigine, int $farmAccueil, object $test): Employee
{
    $agent = Employee::factory()->create(['farm_id' => $farmOrigine, 'status' => 'Actif']);

    $test->post(route('employees.lend', $agent), [
        'farm_id'    => $farmAccueil,
        'start_date' => today()->toDateString(),
        'end_date'   => today()->addDays(30)->toDateString(),
    ]);

    return $agent->fresh();
}

/** Pointe présents les agents donnés, sur le site courant. */
function pointerPresents(array $employeeIds, int $userId): array
{
    return app(RecordAttendance::class)->execute(
        today()->toDateString(),
        array_map(fn ($id) => ['employee_id' => $id, 'status' => 'present'], $employeeIds),
        $userId,
    );
}

test('un agent PRÊTÉ est pointé sur le site qui l’accueille', function () {
    /*
     * LE défaut : proposé par l'écran, jeté par l'enregistrement.
     */
    $prete = agentPreteAuSite($this->farm->id, $this->siteAccueil->id, $this);

    session(['current_farm_id' => $this->siteAccueil->id]);

    expect(Employee::assignableInCurrentFarm()->pluck('id'))->toContain($prete->id);

    $resultat = pointerPresents([$prete->id], $this->adminUser->id);

    expect($resultat['saved'])->toBe(1)
        ->and($resultat['skipped'])->toBe(0)
        ->and(EmployeeAttendance::withoutGlobalScopes()->where('employee_id', $prete->id)->exists())
        ->toBeTrue();
});

test('ce que l’écran propose, l’enregistrement l’accepte', function () {
    /*
     * L'égalité entre lecteurs — le test qui porte réellement la correction.
     * Toute la grille que l'écran a composée doit passer, sans exception.
     */
    $prete = agentPreteAuSite($this->farm->id, $this->siteAccueil->id, $this);

    session(['current_farm_id' => $this->siteAccueil->id]);
    Employee::factory()->create(['farm_id' => $this->siteAccueil->id, 'status' => 'Actif']);

    $proposes = Employee::assignableInCurrentFarm()->pluck('id')->all();

    expect($proposes)->toHaveCount(2);

    $resultat = pointerPresents($proposes, $this->adminUser->id);

    expect($resultat['saved'])->toBe(count($proposes))
        ->and($resultat['skipped'])->toBe(0);

    expect($prete->id)->toBeIn($proposes);
});

test('un agent d’un AUTRE site reste refusé — l’anti-injection tient', function () {
    /*
     * LA borne, et c'est la raison d'être du garde : on élargit au périmètre
     * réel, on ne l'ouvre pas. Un agent qui ne travaille pas ici n'y est pas
     * pointable, même en forçant la requête.
     */
    $etranger = Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    session(['current_farm_id' => $this->siteAccueil->id]);

    $resultat = pointerPresents([$etranger->id], $this->adminUser->id);

    expect($resultat['saved'])->toBe(0)
        ->and($resultat['skipped'])->toBe(1)
        ->and(EmployeeAttendance::withoutGlobalScopes()->count())->toBe(0);
});

test('une ligne écartée est DITE à l’écran', function () {
    /*
     * Le silence est ce qui a rendu le défaut invisible : la grille se referme,
     * tout a l'air normal, et la ligne n'existe pas.
     */
    $etranger = Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    session(['current_farm_id' => $this->siteAccueil->id]);

    $this->post(route('attendance.store'), [
        'date'   => today()->toDateString(),
        'status' => [$etranger->id => 'present'],
    ])->assertSessionHas('success', fn ($m) => str_contains($m, 'écartée'));
});

test('la SYNCHRO accepte une feuille contenant un agent PRÊTÉ', function () {
    /*
     * LE défaut du côté terrain, et c'est le plus coûteux des deux.
     *
     * La validation du push contrôlait chaque ligne avec `employeeExists()` —
     * une copie GELÉE de l'ancienne règle « farm_id du dossier OU compte ayant
     * accès au site (farm_user) ». Or prêter un AGENT ne donne aucun accès à son
     * COMPTE : le miroir mobile le proposait par son affectation, et la
     * validation le refusait.
     *
     * Le refus portant sur une ligne, la FEUILLE ENTIÈRE tombait en
     * `validation_failed` — donc au bac « À corriger », non rejouable. Une seule
     * ligne d'agent prêté faisait perdre la présence de toute l'équipe.
     */
    $prete = agentPreteAuSite($this->farm->id, $this->siteAccueil->id, $this);

    session(['current_farm_id' => $this->siteAccueil->id]);
    $local = Employee::factory()->create(['farm_id' => $this->siteAccueil->id, 'status' => 'Actif']);

    $resultat = app(\App\Services\Sync\SyncService::class)->handle('attendance.create', [
        'uuid'            => (string) \Illuminate\Support\Str::uuid(),
        'attendance_date' => today()->toDateString(),
        'rows'            => [
            ['employee_id' => $local->id, 'status' => 'present'],
            ['employee_id' => $prete->id, 'status' => 'present'],
        ],
    ]);

    expect($resultat['status'])->toBe('success')
        ->and($resultat['saved'])->toBe(2)
        ->and($resultat['skipped'])->toBe(0);
});

test('la SYNCHRO refuse EXPLICITEMENT un agent d’un autre site', function () {
    /*
     * L'autre bout : élargir au périmètre réel ne doit pas ouvrir la porte. Un
     * agent qui ne travaille pas ici est refusé — et il l'est À LA VALIDATION,
     * donc avec un motif rendu au terrain, plutôt qu'écarté en silence dans
     * l'enregistrement.
     */
    $etranger = Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    session(['current_farm_id' => $this->siteAccueil->id]);

    $resultat = app(\App\Services\Sync\SyncService::class)->handle('attendance.create', [
        'uuid'            => (string) \Illuminate\Support\Str::uuid(),
        'attendance_date' => today()->toDateString(),
        'rows'            => [['employee_id' => $etranger->id, 'status' => 'present']],
    ]);

    expect($resultat['status'])->toBe('validation_failed')
        ->and(EmployeeAttendance::withoutGlobalScopes()->count())->toBe(0);
});

test('la SYNCHRO rend le compteur d’écartés', function () {
    /*
     * Le terrain recevait « success » avec le seul nombre d'enregistrés. Le
     * compteur existe désormais dans la réponse : si une ligne tombe malgré la
     * validation — une affectation qui se termine entre les deux —, le terrain
     * peut le voir au lieu de croire la feuille complète.
     */
    session(['current_farm_id' => $this->siteAccueil->id]);
    $local = Employee::factory()->create(['farm_id' => $this->siteAccueil->id, 'status' => 'Actif']);

    $resultat = app(\App\Services\Sync\SyncService::class)->handle('attendance.create', [
        'uuid'            => (string) \Illuminate\Support\Str::uuid(),
        'attendance_date' => today()->toDateString(),
        'rows'            => [['employee_id' => $local->id, 'status' => 'present']],
    ]);

    expect($resultat)->toHaveKey('skipped')
        ->and($resultat['skipped'])->toBe(0);
});

test('un agent ORDINAIRE de son propre site est toujours pointé — non-régression', function () {
    // Le cas courant, celui de tous les jours sur les deux sites.
    $local = Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    $resultat = pointerPresents([$local->id], $this->adminUser->id);

    expect($resultat['saved'])->toBe(1)
        ->and($resultat['skipped'])->toBe(0);
});

test('rejouer la grille corrige sans dupliquer — non-régression', function () {
    /*
     * L'idempotence par (employé, jour), qui permet au terrain de corriger le
     * soir. Elle doit valoir pour l'agent prêté comme pour les autres.
     */
    $prete = agentPreteAuSite($this->farm->id, $this->siteAccueil->id, $this);

    session(['current_farm_id' => $this->siteAccueil->id]);

    pointerPresents([$prete->id], $this->adminUser->id);

    app(RecordAttendance::class)->execute(today()->toDateString(), [
        ['employee_id' => $prete->id, 'status' => 'absent'],
    ], $this->adminUser->id);

    $lignes = EmployeeAttendance::withoutGlobalScopes()->where('employee_id', $prete->id)->get();

    expect($lignes)->toHaveCount(1)
        ->and($lignes->first()->status)->toBe('absent');
});
