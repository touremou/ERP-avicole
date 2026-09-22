<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE BUREAU JETAIT L'ALERTE SANITAIRE QUE LE TECHNICIEN VENAIT DE DÉCLARER.
 *
 * L'écran de pointage rend un choix explicite, et le rend OBLIGATOIRE :
 *
 *     <select name="health_status" required>
 *         🟢 Normal (RAS) · 🟡 Alerte (Surveillance) · 🔴 Critique (Urgence)
 *
 * `StoreDailyCheckRequest` ne portait aucune règle pour ce champ.
 * `$request->validated()` ne le rendait donc jamais, `RecordDailyCheck` ne le
 * voyait pas, et la colonne retombait sur son défaut de migration : « Normal ».
 *
 * ─── MESURÉ ───
 *
 * Un technicien déclare « Critique » sur un lot de 100 sujets, douze morts dans
 * la journée :
 *
 *   • par le BUREAU  : pointage créé, `health_status` = « NORMAL », aucun
 *     message, aucune erreur. L'alarme est effacée en silence ;
 *   • par le TERRAIN : `health_status` = « Critique ».
 *
 * Le même geste, deux portes, deux réponses contraires — et c'est le bureau,
 * celui qui voit les chiffres de tous les lots, qui perd l'alarme.
 *
 * ─── UNE SUPPOSITION ÉCRITE, ET FAUSSE ───
 *
 * `SyncService` porte ce commentaire, juste avant de compléter la valeur :
 *
 *     // health_status est obligatoire côté web : on garantit une valeur par
 *     // défaut [pour le terrain]
 *
 * Le terrain se montrait prudent en s'appuyant sur une garantie que le web ne
 * tenait pas. C'est le motif qui revient dans tout cet audit : une règle
 * déclarée à un endroit, supposée tenue à un autre, et vraie nulle part.
 *
 * ─── POURQUOI `nullable` ET NON `required` ───
 *
 * La colonne porte son propre défaut (« Normal »), et plusieurs chemins de
 * service créent un pointage sans se prononcer sur l'état sanitaire. Ce qui
 * manquait n'était pas une EXIGENCE — c'était d'ÉCOUTER la réponse quand elle
 * est donnée. Le `required` du formulaire suffit à ce que l'opérateur soit
 * toujours interrogé.
 *
 * ─── ET LA CORRECTION, QUI N'EXISTAIT PAS ───
 *
 * L'écran de modification ne rendait pas le champ, et
 * `UpdateDailyCheckRequest` ne le validait pas davantage. Or le pointage du
 * jour est UNIQUE par lot : une alerte mal saisie le matin ne pouvait donc plus
 * être rectifiée du tout. Corriger le stockage sans ouvrir la correction aurait
 * rendu les valeurs fausses plus durables qu'avant.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();

    /*
     * Le contexte de ferme de l'API se résout depuis le rattachement du compte
     * (`SetApiFarmContext`). Sans cette ligne, la synchro refuse le lot et rend
     * « batch id invalide » — un refus qui n'a rien à voir avec la règle
     * éprouvée ici.
     */
    DB::table('farm_user')->updateOrInsert(
        ['farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id],
        ['is_default' => true, 'is_owner' => true, 'created_at' => now(), 'updated_at' => now()],
    );
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'status' => 'Actif', 'initial_quantity' => 100, 'current_quantity' => 100,
    ]);
});

/** Le geste du bureau : la grille de pointage du jour. */
function pointageWeb(object $test, int $batchId, array $champs = [])
{
    return $test->post(route('daily-checks.store'), array_merge([
        'batch_id'      => $batchId,
        'check_date'    => now()->toDateString(),
        'mortality'     => 12,
        'feed_consumed' => 0,
        'feed_type'     => 'Démarrage',
    ], $champs));
}

/** Le même geste, poussé par le téléphone. */
function pointageTerrain(object $test, \App\Models\User $agent, int $batchId, array $champs = [])
{
    Sanctum::actingAs($agent);

    return $test->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(),
        'type'    => 'daily_check.create',
        'payload' => array_merge([
            'uuid'          => (string) Str::uuid(),
            'batch_id'      => $batchId,
            'check_date'    => now()->toDateString(),
            'mortality'     => 12,
            'feed_consumed' => 0,
            'feed_type'     => 'Démarrage',
        ], $champs),
    ]]]);
}

test('« Critique » déclaré au bureau est enfin enregistré', function () {
    /*
     * LE défaut : le pointage était créé avec « Normal », sans le moindre
     * message. L'alarme disparaissait entre l'écran et la base.
     */
    pointageWeb($this, $this->lot->id, ['health_status' => 'Critique']);

    expect(DailyCheck::where('batch_id', $this->lot->id)->value('health_status'))->toBe('Critique');
});

test('« Alerte » aussi', function () {
    // Le degré intermédiaire, celui qui sert à surveiller sans affoler.
    pointageWeb($this, $this->lot->id, ['health_status' => 'Alerte']);

    expect(DailyCheck::where('batch_id', $this->lot->id)->value('health_status'))->toBe('Alerte');
});

test('les deux portes disent enfin la même chose', function () {
    /*
     * L'INVARIANT. La divergence était la maladie : le bureau perdait ce que le
     * terrain conservait. On confronte les deux réponses au même geste, sur deux
     * lots distincts — le pointage du jour étant unique par lot.
     */
    $autreLot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'status' => 'Actif', 'initial_quantity' => 100, 'current_quantity' => 100,
    ]);

    pointageWeb($this, $this->lot->id, ['health_status' => 'Critique']);
    pointageTerrain($this, $this->adminUser, $autreLot->id, ['health_status' => 'Critique']);

    expect(DailyCheck::where('batch_id', $this->lot->id)->value('health_status'))
        ->toBe(DailyCheck::where('batch_id', $autreLot->id)->value('health_status'))
        ->and(DailyCheck::where('batch_id', $this->lot->id)->value('health_status'))->toBe('Critique');
});

test('un état non prévu est refusé', function () {
    // La même énumération qu'au terrain : trois valeurs, pas une de plus.
    pointageWeb($this, $this->lot->id, ['health_status' => 'Catastrophique'])
        ->assertSessionHasErrors('health_status');

    expect(DailyCheck::count())->toBe(0);
});

test('sans état déclaré, le défaut « Normal » s’applique — non-régression', function () {
    /*
     * Plusieurs chemins de service créent un pointage sans se prononcer. La
     * règle est `nullable` pour cela : on écoute la réponse, on ne l'exige pas.
     */
    pointageWeb($this, $this->lot->id);

    expect(DailyCheck::where('batch_id', $this->lot->id)->value('health_status'))->toBe('Normal');
});

test('corriger un pointage permet de rectifier l’état sanitaire', function () {
    /*
     * Le pointage du jour est UNIQUE par lot : sans ce chemin, une alerte mal
     * saisie le matin ne pouvait plus être rectifiée du tout. Corriger le
     * stockage sans ouvrir la correction aurait rendu les valeurs fausses plus
     * durables qu'avant.
     */
    pointageWeb($this, $this->lot->id, ['health_status' => 'Normal']);
    $check = DailyCheck::where('batch_id', $this->lot->id)->firstOrFail();

    $this->put(route('daily-checks.update', $check), [
        'mortality'     => 12,
        'feed_consumed' => 0,
        'feed_type'     => 'Démarrage',
        'health_status' => 'Critique',
    ]);

    expect($check->fresh()->health_status)->toBe('Critique');
});

test('et l’écran de correction PROPOSE l’état enregistré', function () {
    /*
     * Sans pré-sélection, rouvrir la fiche et enregistrer sans y toucher
     * renverrait « Normal » — et effacerait l'alerte, exactement le défaut
     * qu'on vient de corriger, par l'autre bout.
     */
    pointageWeb($this, $this->lot->id, ['health_status' => 'Critique']);
    $check = DailyCheck::where('batch_id', $this->lot->id)->firstOrFail();

    $this->get(route('daily-checks.edit', $check))
        ->assertOk()
        ->assertSee('value="Critique" selected', false);
});

test('le terrain garde son comportement — non-régression', function () {
    // On corrige le bureau, on ne touche pas au terrain qui, lui, marchait.
    pointageTerrain($this, $this->adminUser, $this->lot->id, ['health_status' => 'Alerte']);

    expect(DailyCheck::where('batch_id', $this->lot->id)->value('health_status'))->toBe('Alerte');
});
