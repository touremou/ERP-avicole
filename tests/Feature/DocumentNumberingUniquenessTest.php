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

test('AUCUNE numérotation de document ne vit hors du service', function () {
    /*
     * LA GARDE QUI MANQUAIT.
     *
     * Celle de l'unicité DÉRIVE des schémas déclarés au service : elle ne
     * pouvait donc rien voir de ce qui numérotait AILLEURS. Trois générateurs
     * vivaient hors de lui — l'expédition, la réception, et l'achat d'aliment
     * qui écrivait `supplier_invoices.reference` à partir du MAX(id) quand
     * l'écran d'achat la tire de la séquence des références. Deux autorités sur
     * une même colonne unique.
     *
     * On cherche donc la SIGNATURE exacte : une colonne de numéro de document
     * REMPLIE par un `sprintf` fabriqué sur place. Le service lui-même est exclu
     * — c'est son métier.
     *
     * La formulation compte. Une première version se contentait de « un sprintf
     * zéro-paddé quelque part dans un fichier qui mentionne une colonne
     * numérotée » : elle dénonçait le contrôleur des clients, qui fabrique un
     * CODE CLIENT (CLI-0001) et n'a rien à voir avec la numérotation des
     * documents. Une garde qui crie à tort finit ignorée, donc désactivée.
     *
     * SA PORTÉE, DITE FRANCHEMENT : elle dérive des colonnes DÉCLARÉES au
     * service. Un document d'un type entièrement nouveau, qui n'y serait jamais
     * inscrit, resterait hors de sa vue — c'est exactement ce qui protégeait
     * l'expédition et la réception, dont les colonnes n'y figuraient pas. Le
     * test suivant les nomme donc explicitement, en complément.
     */
    $colonnes = collect(DocumentNumberingService::schemes())->pluck('column')->unique();

    $coupables = [];

    foreach (array_merge(
        glob(app_path('Actions/*/*.php')),
        glob(app_path('Services/*.php')),
        glob(app_path('Http/Controllers/*.php')),
        glob(app_path('Models/*.php')),
    ) as $fichier) {
        if (basename($fichier) === 'DocumentNumberingService.php') {
            continue;
        }

        $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents($fichier));

        foreach ($colonnes as $colonne) {
            // La colonne numérotée, remplie par un sprintf fabriqué sur place.
            $motif = '#[\'"]' . preg_quote($colonne, '#') . '[\'"]\s*(=>|\]\s*=)\s*sprintf\(#';

            if (preg_match($motif, $code)) {
                $coupables[] = basename($fichier) . " fabrique un numéro pour « {$colonne} »";
                break;
            }
        }
    }

    expect($coupables)->toBe([]);
});

test('la garde sait reconnaître la signature qu’elle cherche', function () {
    // Un test dérivé qui ne reconnaîtrait plus rien passerait à vide. On vérifie
    // les DEUX sens : il attrape la forme fautive, et laisse tranquille le code
    // client, qui fabrique un code d'identité et non un numéro de document.
    $motif = '#[\'"]reference[\'"]\s*(=>|\]\s*=)\s*sprintf\(#';

    expect(preg_match($motif, "'reference'        => sprintf('ACH-%05d', \$lastId + 1),"))->toBe(1)
        ->and(preg_match($motif, "\$validated['client_id'] = sprintf('CLI-%04d', \$lastId + 1);"))->toBe(0)
        ->and(preg_match($motif, "'reference' => DocumentNumberingService::generate('expense'),"))->toBe(0);
});

test('expédition et réception passent par le service', function () {
    foreach (['Dispatch/CreateDispatch', 'Dispatch/ValidateReception'] as $action) {
        $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path("Actions/{$action}.php")));

        expect($code)->toContain('DocumentNumberingService::generate');
    }
});

test('l’achat d’aliment ne numérote plus par le MAX des identifiants', function () {
    // La règle divergente : MAX(id) + 1 au lieu de la séquence des références.
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path('Actions/FeedPurchase/CreateFeedPurchase.php')));

    expect($code)->not->toContain("max('id')")
        ->and($code)->toContain("DocumentNumberingService::generate('supplier_invoice')");
});

test('les préfixes des deux nouveaux documents sont RÉGLABLES', function () {
    // Un préfixe lu par le service mais absent des Réglages serait configurable
    // en théorie seulement — le défaut « lecteur sans écrivain » de cet audit.
    foreach (['numbering.dispatch_prefix', 'numbering.reception_prefix'] as $cle) {
        [$groupe, $clef] = explode('.', $cle);

        expect(DB::table('settings')->where('group', $groupe)->where('key', $clef)->exists())
            ->toBeTrue("Réglage absent : {$cle}");
    }
});
