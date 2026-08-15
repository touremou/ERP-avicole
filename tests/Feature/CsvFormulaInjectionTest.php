<?php

use App\Models\Stock;
use App\Support\CsvExport;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LES EXPORTS CSV RECOPIAIENT DES LIBELLÉS TELS QUELS DANS UN TABLEUR.
 *
 * Sept écrans exportent en CSV, et ces fichiers finissent dans un tableur : le
 * promoteur vit à l'étranger, c'est par là qu'il regarde ses chiffres.
 *
 * Or un tableur INTERPRÈTE comme une FORMULE toute cellule commençant par `=`,
 * `+`, `-`, `@`, une tabulation ou un retour chariot. Les exports recopiaient
 * sans précaution des textes saisis dans l'application — nom d'article, motif
 * d'ajustement, description de mouvement de trésorerie, nom d'employé, note de
 * retour client. Une cellule qui commence par `=` s'exécute à l'ouverture, et
 * `=HYPERLINK(…)` transforme la case en lien piégé sans avertissement visible.
 *
 * CE N'EST MÊME PAS QU'UNE QUESTION DE MALVEILLANCE : un article nommé
 * « -20% remise » est parfaitement légitime et déclenche exactement la même
 * interprétation. L'export devient alors illisible ou faux, ce qui suffit à
 * fausser une décision prise à distance.
 *
 * LA NEUTRALISATION EST STANDARD : préfixer d'une apostrophe, que le tableur
 * consomme en affichant le texte tel quel.
 *
 * ELLE VIT À L'ÉCRITURE, EN UN SEUL ENDROIT. Recopier la règle dans chacune des
 * quinze écritures, c'était garantir qu'elle diverge — la leçon de tous les lots
 * précédents. Un garde de forme vérifie qu'aucun contrôleur n'appelle plus
 * `fputcsv` en direct.
 *
 * CE QU'ON NE TOUCHE PAS : les nombres. Les préfixer en ferait du texte et
 * casserait les totaux de la feuille — une « correction » qui abîmerait le
 * travail de celui qu'elle prétend protéger.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

test('une cellule qui commence par = est neutralisée', function () {
    expect(CsvExport::neutralize('=1+1'))->toBe("'=1+1")
        ->and(CsvExport::neutralize('=HYPERLINK("http://x","cliquez")'))
            ->toBe("'=HYPERLINK(\"http://x\",\"cliquez\")");
});

test('les quatre autres amorces de formule le sont aussi', function () {
    // `-` est le cas le plus courant, et le plus innocent : « -20% remise ».
    expect(CsvExport::neutralize('+42'))->toStartWith("'")
        ->and(CsvExport::neutralize('-20% remise'))->toStartWith("'")
        ->and(CsvExport::neutralize('@SUM(A1)'))->toStartWith("'")
        ->and(CsvExport::neutralize("\tinjection"))->toStartWith("'");
});

test('un texte ordinaire n’est pas modifié', function () {
    // Une neutralisation qui salit les valeurs normales serait vite désactivée.
    expect(CsvExport::neutralize('Maïs concassé'))->toBe('Maïs concassé')
        ->and(CsvExport::neutralize('Poussins d\'un jour'))->toBe('Poussins d\'un jour')
        ->and(CsvExport::neutralize(''))->toBe('');
});

test('les NOMBRES restent des nombres', function () {
    // Le point à ne pas rater : préfixer un nombre casserait les totaux de la
    // feuille de calcul.
    expect(CsvExport::neutralize(1500))->toBe(1500)
        ->and(CsvExport::neutralize(12.5))->toBe(12.5)
        ->and(CsvExport::neutralize(null))->toBeNull();
});

test('l’export d’inventaire neutralise un nom d’article piégé', function () {
    // Le bout concret : le nom d'article est saisi dans l'application, et
    // ressort dans un fichier qu'on ouvre au tableur.
    Stock::create([
        'category' => Stock::CAT_CONSO, 'item_name' => '=HYPERLINK("http://piege","Maïs")',
        'unit' => 'kg', 'current_quantity' => 100,
        'unit_price' => 0, 'last_unit_price' => 0, 'alert_threshold' => 0,
    ]);

    $contenu = $this->get(route('stocks.export', ['category' => Stock::CAT_CONSO]))
        ->assertOk()->streamedContent();

    expect($contenu)->toContain("'=HYPERLINK")
        ->and($contenu)->not->toMatch('#(^|;|")=HYPERLINK#');
});

test('l’export reste lisible : le nom demeure présent', function () {
    // On neutralise l'exécution, pas l'information.
    Stock::create([
        'category' => Stock::CAT_CONSO, 'item_name' => '-20% remise fournisseur',
        'unit' => 'kg', 'current_quantity' => 50,
        'unit_price' => 0, 'last_unit_price' => 0, 'alert_threshold' => 0,
    ]);

    $contenu = $this->get(route('stocks.export', ['category' => Stock::CAT_CONSO]))
        ->assertOk()->streamedContent();

    expect($contenu)->toContain('20% remise fournisseur');
});

test('AUCUN contrôleur n’écrit plus une ligne CSV en direct', function () {
    /*
     * Garde de forme, dérivée : elle cherche l'appel brut plutôt qu'une liste
     * d'écrans. Sept contrôleurs exportent aujourd'hui ; un huitième arrivera,
     * et c'est lui que ce test attend.
     */
    $coupables = [];

    foreach (glob(app_path('Http/Controllers/*.php')) as $fichier) {
        $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents($fichier));

        if (preg_match('#\bfputcsv\s*\(#', $code)) {
            $coupables[] = basename($fichier);
        }
    }

    expect($coupables)->toBe([]);
});

test('la garde reconnaît bien l’appel qu’elle traque', function () {
    // Un test dérivé qui ne reconnaîtrait rien passerait à vide.
    expect(preg_match('#\bfputcsv\s*\(#', 'fputcsv($out, $ligne);'))->toBe(1)
        ->and(preg_match('#\bfputcsv\s*\(#', 'CsvExport::putRow($out, $ligne);'))->toBe(0);
});

test('les sept exports passent par le support commun', function () {
    // L'inverse du test précédent : non seulement plus d'appel brut, mais bien
    // un appel au support — un export qui n'écrirait plus rien passerait sinon.
    $exportateurs = 0;

    foreach (glob(app_path('Http/Controllers/*.php')) as $fichier) {
        if (str_contains(file_get_contents($fichier), 'CsvExport::putRow')) {
            $exportateurs++;
        }
    }

    expect($exportateurs)->toBeGreaterThanOrEqual(7);
});
