<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * LA DATE DE NAISSANCE ÉTAIT SAISIE, VALIDÉE, PUIS JETÉE.
 *
 * Signalé par l'exploitation, capture à l'appui : « un lot arrivé, avec la date
 * de naissance renseignée, mais les paramètres sont toujours basés sur la date
 * de démarrage au lieu de l'âge ». La fiche affichait J-1, phase Démarrage,
 * poids cible S-1 — pour des sujets nés bien avant.
 *
 * ─── QUATRE MAILLONS SUR CINQ ÉTAIENT EN PLACE ───
 *
 * Le champ existait au formulaire (create/edit), la Request le validait
 * (`before_or_equal:arrival_date`), $fillable l'autorisait, et la fiche lisait
 * bien `$batch->age`. Tout, sauf l'écriture :
 *
 *   • CreateBatch construit un tableau EXPLICITE, colonne par colonne — et
 *     `birth_date` n'y était pas. Le champ validé n'atteignait jamais Batch ;
 *   • UpdateBatch filtre par liste blanche (ALLOWED_FIELDS, protection B-03) —
 *     `birth_date` n'y était pas non plus. La correction a posteriori depuis la
 *     fiche était donc impossible, elle aussi.
 *
 * Un formulaire qui accepte une valeur, la valide, affiche un message d'erreur
 * si elle est incohérente… puis la jette en silence. L'utilisateur n'a aucun
 * moyen de savoir que sa saisie n'a servi à rien.
 *
 * ─── POURQUOI #292 NE L'A PAS VU ───
 *
 * Son test « le FORMULAIRE web peut enfin l'écrire » appelait `$lot->update()`
 * SUR LE MODÈLE, court-circuitant l'Action. Il prouvait que $fillable était bon,
 * pas que le formulaire écrivait. C'est exactement l'erreur déjà commise en
 * #284 : mesurer le modèle quand le défaut est en aval.
 *
 * Les tests ci-dessous passent donc par les ROUTES, et l'un d'eux va jusqu'à
 * l'écran — la seule preuve qui vaille pour un défaut vu sur un écran.
 */

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    $role = Role::firstOrCreate(
        ['name' => 'manager'],
        ['label' => 'Manager', 'display_name' => 'Manager', 'permissions' => ['L', 'C', 'M', 'S']]
    );

    $now = now();
    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true,
             'can_delete' => true, 'updated_at' => $now, 'created_at' => $now]
        );
    }

    $this->manager  = User::factory()->create(['role_id' => $role->id]);
    // Bâtiment mixte : la compatibilité espèce/bâtiment n'est pas le sujet ici,
    // et l'écarter garde le test centré sur l'écriture de la naissance.
    $this->building = Building::factory()->create(['type' => 'mixte']);
    $this->employee = Employee::factory()->create();
    $this->provider = Provider::factory()->create();
});

/** Le corps de formulaire minimal accepté par batches.store. */
function formulaireLot(array $extra = []): array
{
    return array_merge([
        'code'               => 'LOT-NAISSANCE',
        'building_id'        => test()->building->id,
        'type'               => 'engraissement',
        'employee_id'        => test()->employee->id,
        'provider_id'        => test()->provider->id,
        'arrival_date'       => today()->toDateString(),
        'buy_price_per_unit' => 5000,
        'qty_alive'          => 250,
    ], $extra);
}

test('la CRÉATION par le formulaire enregistre la naissance', function () {
    /*
     * LE défaut signalé, dans sa forme la plus directe : la valeur postée doit
     * se retrouver en base.
     */
    $this->actingAs($this->manager)
        ->post(route('batches.store'), formulaireLot([
            'birth_date' => today()->subDays(111)->toDateString(),
        ]))
        ->assertSessionDoesntHaveErrors();

    $lot = Batch::where('code', 'LOT-NAISSANCE')->first();

    expect($lot)->not->toBeNull()
        ->and($lot->birth_date?->toDateString())->toBe(today()->subDays(111)->toDateString());
});

test('un lot créé par le formulaire a l’ÂGE de ses sujets, pas J-1', function () {
    /*
     * La conséquence telle qu'elle a été vue : la fiche annonçait J-1 pour des
     * poulettes de 16 semaines, donc phase Démarrage, seuils de mortalité de
     * démarrage et poids cible de la semaine 1.
     */
    $this->actingAs($this->manager)
        ->post(route('batches.store'), formulaireLot([
            'birth_date' => today()->subDays(111)->toDateString(),
        ]))
        ->assertSessionDoesntHaveErrors();

    $lot = Batch::where('code', 'LOT-NAISSANCE')->first();

    expect($lot->age)->toBe(112)
        ->and($lot->current_phase)->not->toBe('Démarrage');
});

test('la MODIFICATION peut corriger la naissance après coup', function () {
    /*
     * L'autre moitié, et celle dont dépend la reprise des lots déjà en
     * production : ALLOWED_FIELDS les écartait, donc aucune correction n'était
     * possible depuis la fiche.
     */
    $lot = Batch::factory()->create([
        'building_id'  => $this->building->id,
        'arrival_date' => today()->toDateString(),
        'birth_date'   => today()->toDateString(),
        'status'       => 'Actif',
    ]);

    $this->actingAs($this->manager)
        ->put(route('batches.update', $lot), [
            'code'               => $lot->code,
            'building_id'        => $this->building->id,
            'type'               => 'engraissement',
            'buy_price_per_unit' => 5000,
            'arrival_date'       => today()->toDateString(),
            'birth_date'         => today()->subDays(111)->toDateString(),
            'status'             => 'Actif',
        ])
        ->assertSessionDoesntHaveErrors();

    expect($lot->fresh()->age)->toBe(112);
});

test('L’ÉCRAN affiche l’âge réel — la preuve de bout en bout', function () {
    /*
     * Le défaut a été constaté sur un écran : c'est donc sur l'écran qu'il faut
     * le clore. La fiche lit `$batch->age` et l'affiche « J-<âge> ».
     */
    $this->actingAs($this->manager)
        ->post(route('batches.store'), formulaireLot([
            'birth_date' => today()->subDays(111)->toDateString(),
        ]));

    $lot = Batch::where('code', 'LOT-NAISSANCE')->first();

    $rendu = $this->actingAs($this->manager)->get(route('batches.show', $lot))->assertOk();

    expect(str_contains($rendu->getContent(), 'J-112'))
        ->toBeTrue('La fiche doit annoncer l’âge réel des sujets.')
        ->and(str_contains($rendu->getContent(), 'J-1 '))
        ->toBeFalse('La fiche ne doit plus annoncer J-1 pour un lot déjà âgé.');
});

test('SANS naissance saisie, la création reste possible', function () {
    /*
     * La borne de non-régression : le champ est facultatif, et son absence ne
     * doit rien casser pour ceux qui ne le renseignent pas.
     */
    $this->actingAs($this->manager)
        ->post(route('batches.store'), formulaireLot(['code' => 'LOT-SANS-NAISSANCE']))
        ->assertSessionDoesntHaveErrors();

    $lot = Batch::where('code', 'LOT-SANS-NAISSANCE')->first();

    expect($lot)->not->toBeNull()
        ->and($lot->age)->toBe(1);
});
