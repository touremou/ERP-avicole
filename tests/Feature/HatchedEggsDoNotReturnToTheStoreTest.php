<?php

use App\Actions\Incubation\AbortIncubation;
use App\Actions\Incubation\RecordHatching;
use App\Actions\Incubation\RecordMirage;
use App\Actions\Incubation\StartIncubation;
use App\Models\Batch;
use App\Models\Incubation;
use App\Models\Incubator;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * SUPPRIMER L'ARCHIVE D'UNE ÉCLOSION RENDAIT AU MAGASIN DES ŒUFS DEVENUS
 * POUSSINS.
 *
 * `AbortIncubation` restitue les œufs d'un cycle abandonné, et son commentaire
 * énonce la condition mot pour mot : « les œufs n'ont pas été couvés, ils
 * retournent d'où ils viennent ».
 *
 * Le code, lui, ne posait aucun test sur le STATUT. Il ne regardait que la
 * provenance — `source_type === 'internal'` et un calibre renseigné — deux
 * conditions qui restent vraies à vie. La garde était dans le commentaire, pas
 * dans le code.
 *
 * ─── LA MÊME ROUTE SERT DEUX GESTES ───
 *
 * `incubations.destroy` est atteinte par deux corbeilles :
 *
 *   • « Annuler définitivement ce cycle ? », sur un cycle EN COURS — le geste
 *     pour lequel l'action a été écrite ;
 *   • « Supprimer cette archive ? », dans l'HISTORIQUE — qui ne liste que des
 *     cycles CLOS (IncubationController::index).
 *
 * Mesuré : cycle de 900 œufs calibre L (30 alvéoles), miré à 800 fertiles,
 * éclos à 700 poussins. Magasin à 70 alvéoles. Supprimer l'archive le remonte à
 * 100. Trente alvéoles d'œufs qui sont physiquement devenus des poussins — et
 * qui, depuis #305, sont VENDABLES : une vente les déstockerait sans broncher.
 *
 * ─── POURQUOI LE STATUT, ET PAS LE NOMBRE DE POUSSINS ───
 *
 * Une éclosion enregistrée CONSOMME les œufs, quel qu'en soit le résultat. Un
 * cycle qui n'éclôt rien a perdu ses œufs ; il ne les rend pas non plus.
 * Trancher sur `hatched_chicks > 0` aurait fait revenir au magasin les œufs
 * d'un cycle entièrement raté.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
    ]);

    $this->couveuse = Incubator::create([
        'farm_id' => $this->farm->id, 'name' => 'Couveuse A',
        'capacity' => 10_000, 'status' => 'Disponible',
    ]);

    // 100 alvéoles de calibre L au magasin, soit 3 000 œufs.
    $this->magasin = Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'L', 'category' => Stock::CAT_OEUFS,
        'unit' => 'Alvéole', 'current_quantity' => 100, 'alert_threshold' => 0,
    ]);
});

/** Met 900 œufs (30 alvéoles) de calibre L à couver, depuis le magasin. */
function couverNeufCentsOeufs(int $lotId, int $couveuseId): Incubation
{
    return (new StartIncubation())->execute([
        'incubator_id' => $couveuseId,
        'batch_id'     => $lotId,
        'provider_id'  => null,
        'start_date'   => today()->toDateString(),
        'eggs_count'   => 900,
        'source_type'  => 'internal',
        'egg_grade'    => 'L',
        'duration'     => 21,
    ]);
}

test('supprimer l’archive d’un cycle ÉCLOS ne rend aucun œuf', function () {
    /*
     * LE défaut : 700 poussins sont nés, et les 900 œufs revenaient au magasin.
     */
    $cycle = couverNeufCentsOeufs($this->lot->id, $this->couveuse->id);

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(70.0);

    app(RecordMirage::class)->execute($cycle->fresh(), ['fertile_eggs' => 800]);
    app(RecordHatching::class)->execute($cycle->fresh(), ['hatched_chicks' => 700]);

    expect($cycle->fresh()->status)->toBe('clos');

    app(AbortIncubation::class)->execute($cycle->fresh());

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(70.0);
});

test('un cycle éclos à ZÉRO poussin ne rend rien non plus', function () {
    /*
     * LA borne qui écarte le mauvais critère. Trancher sur `hatched_chicks > 0`
     * aurait fait revenir au magasin les œufs d'un cycle entièrement raté — or
     * ces œufs sont morts en couveuse, pas conservés.
     */
    $cycle = couverNeufCentsOeufs($this->lot->id, $this->couveuse->id);

    app(RecordMirage::class)->execute($cycle->fresh(), ['fertile_eggs' => 800]);
    app(RecordHatching::class)->execute($cycle->fresh(), ['hatched_chicks' => 0]);

    app(AbortIncubation::class)->execute($cycle->fresh());

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(70.0);
});

test('abandonner un cycle EN COURS rend bien les œufs — non-régression', function () {
    /*
     * LE geste pour lequel l'action a été écrite, et qu'il ne faut surtout pas
     * casser : les œufs n'ont pas été couvés, ils rentrent.
     */
    $cycle = couverNeufCentsOeufs($this->lot->id, $this->couveuse->id);

    app(AbortIncubation::class)->execute($cycle->fresh());

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(100.0);
});

test('abandonner APRÈS LE MIRAGE rend encore les œufs — non-régression', function () {
    /*
     * Le mirage écarte les clairs mais ne casse rien : les œufs sont toujours
     * des œufs. La borne est l'ÉCLOSION, pas le mirage.
     */
    $cycle = couverNeufCentsOeufs($this->lot->id, $this->couveuse->id);

    app(RecordMirage::class)->execute($cycle->fresh(), ['fertile_eggs' => 800]);

    expect($cycle->fresh()->status)->toBe('mirage_fait');

    app(AbortIncubation::class)->execute($cycle->fresh());

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(100.0);
});

test('la machine est libérée dans les deux cas — non-régression', function () {
    // La suppression fait aussi le ménage matériel : elle ne doit pas laisser
    // une couveuse marquée occupée par un cycle qui n'existe plus.
    $cycle = couverNeufCentsOeufs($this->lot->id, $this->couveuse->id);

    app(RecordMirage::class)->execute($cycle->fresh(), ['fertile_eggs' => 800]);
    app(RecordHatching::class)->execute($cycle->fresh(), ['hatched_chicks' => 700]);
    app(AbortIncubation::class)->execute($cycle->fresh());

    expect($this->couveuse->fresh()->status)->toBe('Disponible')
        ->and(Incubation::count())->toBe(0);
});
