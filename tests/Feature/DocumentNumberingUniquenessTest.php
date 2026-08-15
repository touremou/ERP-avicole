<?php

use App\Models\Transformation;
use App\Services\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE RÉFÉRENCE DE DOCUMENT NE DOIT JAMAIS ÊTRE ÉMISE DEUX FOIS.
 *
 * La numérotation est dérivée du MAX réel de la colonne — « lire le plus grand,
 * ajouter un ». Ce choix est bon (aucune référence n'est réattribuée après une
 * suppression), mais il n'est atomique que si personne ne lit entre les deux
 * gestes. Or deux écritures simultanées sont le quotidien de cette
 * installation : une vente au comptoir pendant qu'une tournée terrain se
 * synchronise. La lecture est désormais VERROUILLÉE sur la plage du préfixe
 * concerné.
 *
 * LE FILET DE SÉCURITÉ MANQUAIT À UN SEUL ENDROIT. Toutes les colonnes
 * numérotées portent un index unique — ventes, dépenses, achats, ordres
 * d'abattage, provenderie, transformations de cultures — sauf
 * `transformations.batch_number`. Ailleurs une collision éclate à l'insertion ;
 * là, elle passait EN SILENCE. Deux lots de transformation pouvaient porter le
 * même numéro, et la traçabilité d'un produit remontait alors à deux origines.
 *
 * La migration qui pose l'index DÉ-DOUBLONNE d'abord : sans cela elle
 * échouerait sur une base déjà atteinte — et avec elle le déploiement, qui part
 * automatiquement à chaque poussée.
 */

beforeEach(function () {
    $this->setUpRbac();
});

/** Lot de transformation minimal (colonnes NON NULL renseignées). */
function transformationAvecNumero(string $numero): Transformation
{
    return Transformation::create([
        'batch_number'        => $numero,
        'product_source'      => 'Poulet entier',
        'transformation_type' => 'fume',
        'input_kg'            => 10,
        'production_date'     => now()->toDateString(),
        'operator_id'         => auth()->id() ?? \App\Models\User::value('id'),
    ]);
}

test('TOUTE colonne numérotée porte un index unique', function () {
    /*
     * Garde DÉRIVÉE du service lui-même : elle parcourt les schémas déclarés et
     * vérifie l'index sur chacun. Une liste de tables écrite à la main aurait
     * exactement le défaut qu'elle surveille — c'est ainsi que celle-ci est
     * passée inaperçue.
     */
    $sansIndex = [];

    foreach (DocumentNumberingService::schemes() as $type => $scheme) {
        $table  = (new $scheme['model'])->getTable();
        $indexes = collect(Schema::getIndexes($table))
            ->filter(fn ($i) => $i['unique'] ?? false)
            ->flatMap(fn ($i) => $i['columns']);

        if (! $indexes->contains($scheme['column'])) {
            $sansIndex[] = "{$type} : {$table}.{$scheme['column']}";
        }
    }

    expect($sansIndex)->toBe([]);
});

test('un numéro de transformation en double est REFUSÉ par la base', function () {
    // Ce que l'index change : la collision cesse d'être silencieuse.
    transformationAvecNumero('TRANS-2026-000001');

    expect(fn () => transformationAvecNumero('TRANS-2026-000001'))
        ->toThrow(Illuminate\Database\QueryException::class);
});

test('la numérotation suit le MAX existant sans le réémettre', function () {
    transformationAvecNumero('TRANS-' . now()->format('Y') . '-000007');

    expect(DocumentNumberingService::generate('transformation'))
        ->toBe('TRANS-' . now()->format('Y') . '-000008');
});

test('la lecture du compteur est VERROUILLÉE', function () {
    // On ne peut pas rejouer une course dans un test sqlite mono-connexion :
    // on vérifie donc la propriété qui l'empêche, et non son symptôme. Un
    // agrégat `max()` ne porte AUCUN verrou de ligne — c'est pour cela que la
    // lecture est ordonnée puis `value()`.
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path('Services/DocumentNumberingService.php')));

    expect($code)->toContain('lockForUpdate()')
        ->and($code)->not->toContain("->max(\$column)");
});

test('deux types partageant une table gardent des compteurs distincts', function () {
    // `expense` et `fuel_expense` écrivent la même colonne avec deux préfixes :
    // le verrou ne doit pas les confondre, ni les sérialiser l'un sur l'autre.
    $a = DocumentNumberingService::generate('expense');
    $b = DocumentNumberingService::generate('fuel_expense');

    expect($a)->toStartWith('DEP-')
        ->and($b)->toStartWith('GAS-')
        ->and($a)->not->toBe($b);
});

test('la migration dé-doublonne AVANT de poser l’index', function () {
    /*
     * Le point qui protège le déploiement : sur une base contenant déjà des
     * doublons, poser l'index d'emblée ferait échouer la migration — et le
     * déploiement part automatiquement à chaque poussée sur main.
     *
     * On rejoue la situation : index retiré, doublons insérés, migration
     * relancée. Le plus ancien garde son numéro.
     */
    Schema::table('transformations', fn ($t) => $t->dropUnique(['batch_number']));

    $premier = transformationAvecNumero('TRANS-2026-000042');
    $second  = transformationAvecNumero('TRANS-2026-000042');

    $migration = require database_path('migrations/2026_08_15_120000_add_unique_index_to_transformations_batch_number.php');
    $migration->up();

    expect($premier->fresh()->batch_number)->toBe('TRANS-2026-000042')
        ->and($second->fresh()->batch_number)->not->toBe('TRANS-2026-000042')
        ->and(DB::table('transformations')->distinct()->count('batch_number'))->toBe(2);
});
