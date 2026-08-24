<?php

use App\Actions\Incubation\AbortIncubation;
use App\Actions\Incubation\StartIncubation;
use App\Models\Batch;
use App\Models\Incubator;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * METTRE DES ŒUFS À COUVER NE LES SORTAIT PAS DU MAGASIN.
 *
 * `StartIncubation` enregistrait un nombre d'œufs, leur coût unitaire et les
 * frais du cycle — sans jamais toucher au stock. Des œufs collectés restaient
 * donc comptés VENDABLES pendant qu'ils étaient enfermés dans une couveuse : le
 * magasin était surévalué du contenu des machines.
 *
 * ─── CE N'ÉTAIT PLUS SEULEMENT UN CHIFFRE FAUX ───
 *
 * Depuis #305, une vente déstocke et REFUSE quand le magasin est vide. Mais un
 * stock gonflé par les œufs en incubation ne refuse pas : on pouvait vendre des
 * œufs physiquement dans un incubateur, et la vente passait.
 *
 * `eggs:repair-stock` ne l'aurait pas vu non plus — elle compare les tris aux
 * ENTRÉES de stock, or la dérive est du côté des sorties.
 *
 * ─── POURQUOI L'APPLICATION NE POUVAIT PAS DÉSTOCKER ───
 *
 * `StartIncubationRequest` validait déjà `source_type` (internal/external) et
 * exigeait un lot ou un fournisseur en conséquence. Mais RIEN n'était
 * enregistré : la table n'avait aucun champ de provenance. Faute de savoir d'où
 * venaient les œufs, l'action ne pouvait pas décider s'il fallait les déduire.
 *
 * ─── LA RÈGLE, ET SA MOITIÉ SYMÉTRIQUE ───
 *
 * On ne déduit QUE l'interne. Des œufs achetés à un fournisseur ne sont jamais
 * entrés au magasin : les déduire retirerait un stock qui n'a jamais existé —
 * l'erreur symétrique, aussi coûteuse que celle qu'on corrige.
 *
 * Et abandonner un cycle REND les œufs, comme l'annulation d'une vente restocke.
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

    $this->fournisseur = \App\Models\Provider::factory()->create([
        'farm_id' => $this->farm->id, 'name' => 'Couvoir Kindia', 'status' => 'Actif',
    ]);

    // 100 alvéoles de calibre L au magasin, soit 3 000 œufs.
    $this->stock = Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'L', 'category' => Stock::CAT_OEUFS,
        'unit' => 'Alvéole', 'current_quantity' => 100, 'alert_threshold' => 0,
    ]);
});

/** Les données d'une mise à couver. */
function miseACouver(int $lotId, int $couveuseId, int $oeufs, string $source = 'internal', ?string $calibre = 'L', ?int $fournisseurId = null): array
{
    return [
        'incubator_id' => $couveuseId,
        'batch_id'     => $lotId,
        'provider_id'  => $fournisseurId,
        'start_date'   => today()->toDateString(),
        'eggs_count'   => $oeufs,
        'source_type'  => $source,
        'egg_grade'    => $calibre,
        'duration'     => 21,
    ];
}

test('les œufs mis à couver SORTENT du magasin', function () {
    /*
     * Le défaut, dans sa forme la plus simple. 900 œufs = 30 alvéoles : le
     * magasin doit passer de 100 à 70.
     */
    (new StartIncubation())->execute(miseACouver($this->lot->id, $this->couveuse->id, 900));

    expect((float) $this->stock->fresh()->current_quantity)->toBe(70.0);
});

test('des œufs ACHETÉS ne touchent pas au magasin', function () {
    /*
     * L'erreur symétrique, et elle serait aussi coûteuse : ces œufs ne sont
     * jamais entrés en stock, les déduire retirerait ce qui n'existe pas.
     */
    (new StartIncubation())->execute(
        miseACouver($this->lot->id, $this->couveuse->id, 900, 'external', null, $this->fournisseur->id)
    );

    expect((float) $this->stock->fresh()->current_quantity)->toBe(100.0);
});

test('mettre à couver plus d’œufs qu’on n’en a est REFUSÉ, avec les chiffres', function () {
    /*
     * `syncMovement` plafonne silencieusement une sortie à zéro : sans ce
     * contrôle, le magasin se viderait sans rien dire et la différence
     * disparaîtrait.
     */
    expect(fn () => (new StartIncubation())->execute(
        miseACouver($this->lot->id, $this->couveuse->id, 6_000)   // 200 alvéoles pour 100
    ))->toThrow(ValidationException::class, 'Stock insuffisant');

    expect((float) $this->stock->fresh()->current_quantity)->toBe(100.0)
        ->and(\App\Models\Incubation::count())->toBe(0);
});

test('un calibre ABSENT du magasin est refusé, en disant quoi faire', function () {
    /*
     * `syncMovement` rend `false` sans lever quand l'article n'existe pas —
     * c'est ce qui avait rendu tous les tris invisibles (#296). On regarde donc
     * sa valeur de retour plutôt que de la jeter.
     */
    expect(fn () => (new StartIncubation())->execute(
        miseACouver($this->lot->id, $this->couveuse->id, 900, 'internal', 'XL')
    ))->toThrow(ValidationException::class, 'repair-stock');

    expect(\App\Models\Incubation::count())->toBe(0);
});

test('ABANDONNER le cycle rend les œufs au magasin', function () {
    /*
     * La symétrie. Sans elle, abandonner ferait disparaître le stock pour de
     * bon — la même asymétrie que `CancelSale` évite en restockant.
     */
    $incubation = (new StartIncubation())->execute(
        miseACouver($this->lot->id, $this->couveuse->id, 900)
    );

    expect((float) $this->stock->fresh()->current_quantity)->toBe(70.0);

    (new AbortIncubation())->execute($incubation);

    expect((float) $this->stock->fresh()->current_quantity)->toBe(100.0);
});

test('abandonner un cycle ACHETÉ n’invente pas de stock', function () {
    // L'autre bord de la symétrie : on ne rend que ce qu'on a prélevé.
    $incubation = (new StartIncubation())->execute(
        miseACouver($this->lot->id, $this->couveuse->id, 900, 'external', null, $this->fournisseur->id)
    );

    (new AbortIncubation())->execute($incubation);

    expect((float) $this->stock->fresh()->current_quantity)->toBe(100.0);
});

test('la PROVENANCE est enregistrée, pas seulement validée', function () {
    /*
     * `source_type` était validé par la Request et jeté aussitôt. Sans lui en
     * base, l'abandon ne saurait pas s'il doit restituer.
     */
    $incubation = (new StartIncubation())->execute(
        miseACouver($this->lot->id, $this->couveuse->id, 900)
    );

    expect($incubation->fresh()->source_type)->toBe('internal')
        ->and($incubation->fresh()->egg_grade)->toBe('L');
});

test('aucune COPIE MORTE de ces règles ne subsiste dans le module', function () {
    /*
     * CINQ FICHIERS PORTAIENT UNE COPIE COMMENTÉE DE LEUR PROPRE CLASSE.
     *
     * D'anciennes versions laissées en place lors d'une réécriture. Ce n'était
     * pas qu'un désordre : chacune énonçait la même règle EN PLUS FAIBLE.
     *
     *   • la copie morte de `RecordHatching` n'avait ni la garde anti-double
     *     éclosion ni le calcul du taux. Or cette garde existe pour un défaut
     *     mesuré : re-soumettre le formulaire sur un cycle dont 600 poussins sur
     *     800 étaient déjà partis remettait le compteur à 0/800, et les 600
     *     redevenaient « à dispatcher » ;
     *   • celle d'`AbortIncubation` ne libérait la machine que sans condition —
     *     et, depuis le déstockage, ne restituerait pas les œufs au magasin.
     *
     * Le risque n'est pas théorique : un lecteur pressé « restaure » la version
     * commentée en croyant retrouver l'original, et perd silencieusement une
     * garde ajoutée pour un vrai incident.
     *
     * Ce test interdit leur retour. Il vaut pour TOUT le projet, pas seulement
     * pour l'incubation : une classe déclarée deux fois dans un fichier est
     * toujours une version morte qui contredit la vivante.
     */
    $doublons = [];

    $iterateur = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app'), RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterateur as $fichier) {
        if ($fichier->getExtension() !== 'php') {
            continue;
        }

        $lignes = file($fichier->getPathname());
        $classes = array_filter($lignes, fn ($l) => preg_match('/^\s*class [A-Z]/', $l));

        if (count($classes) > 1) {
            $doublons[] = str_replace(base_path() . '/', '', $fichier->getPathname());
        }
    }

    expect($doublons)->toBe([], 'Classe déclarée deux fois — une copie morte contredit la vivante : '
        . implode(', ', $doublons));
});
