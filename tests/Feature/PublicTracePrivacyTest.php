<?php

use App\Models\Dispatch;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA PAGE PUBLIQUE PUBLIAIT LE TÉLÉPHONE PERSONNEL DU CHAUFFEUR.
 *
 * Les pages /trace/… sont VOLONTAIREMENT publiques : un client, un inspecteur
 * ou un distributeur doit pouvoir vérifier l'origine d'un lot en scannant le QR
 * d'une étiquette, sans compte. La route le dit, et ajoute : « n'expose que des
 * informations d'origine (aucune donnée financière) ».
 *
 * Aucune donnée financière, c'était vrai. Mais la page d'expédition affichait
 * le NUMÉRO DE TÉLÉPHONE du chauffeur, à côté de son nom, de sa plaque, de sa
 * destination et de son heure de départ. Et l'adresse est un numéro
 * SÉQUENTIEL — EXP-2026-000001, 000002… — donc énumérable par quiconque en
 * scanne un seul. Le lot #245 vient d'ailleurs de rendre cette séquence encore
 * plus régulière.
 *
 * DEUX TORTS DISTINCTS : une donnée personnelle publiée sans nécessité, et une
 * cartographie des tournées (qui roule, dans quoi, vers où, à quelle heure)
 * offerte à qui la demande. Pour une exploitation dont les camions transportent
 * de la marchandise entre deux sites, le second n'est pas théorique.
 *
 * CE QU'ON GARDE : le NOM du chauffeur. C'est ce que le réceptionnaire
 * confronte au document de transport — l'objet même de la page. Le téléphone,
 * lui, ne sert à vérifier aucune origine ; il reste au bureau, sur la fiche
 * d'expédition, derrière le droit logistique.
 *
 * CE QU'ON NE FAIT PAS : rendre les pages non énumérables. Les étiquettes DÉJÀ
 * IMPRIMÉES portent l'adresse par numéro de document ; changer la clé les
 * rendrait toutes muettes. Le défaut corrigé ici est ce qui s'y trouvait, pas
 * l'adressage lui-même.
 */

beforeEach(function () {
    $this->setUpRbac();

    $this->expedition = Dispatch::create([
        'farm_id'         => $this->farm->id,
        'dispatch_number' => 'EXP-2026-000042',
        'destination'     => 'Kérouané',
        'driver_name'     => 'Sékou Camara',
        'driver_phone'    => '628445566',
        'vehicle_plate'   => 'RC-4821-A',
        'dispatch_date'   => now()->toDateString(),
        'dispatched_by'   => $this->adminUser->id,
        'status'          => 'expedie',
    ]);
});

test('la page publique ne publie PAS le téléphone du chauffeur', function () {
    // LE défaut.
    $this->get(route('trace.dispatch', 'EXP-2026-000042'))
        ->assertOk()
        ->assertDontSee('628445566');
});

test('le NOM du chauffeur reste affiché — c’est l’objet de la page', function () {
    // On ne vide pas la page de son sens : le réceptionnaire confronte ce nom
    // au document de transport.
    $this->get(route('trace.dispatch', 'EXP-2026-000042'))
        ->assertOk()
        ->assertSee('Sékou Camara');
});

test('le téléphone reste disponible au BUREAU, sur la fiche d’expédition', function () {
    // La donnée n'est pas supprimée : elle retrouve sa place, derrière un droit.
    $this->actingAs($this->adminUser)
        ->get(route('dispatches.show', $this->expedition->id))
        ->assertOk()
        ->assertSee('628445566');
});

test('AUCUNE page publique de traçabilité n’affiche un contact personnel', function () {
    /*
     * Garde dérivée : on parcourt les pages publiques déclarées dans les routes
     * et on vérifie qu'aucune ne rend un champ de contact. Une liste écrite à
     * la main aurait le défaut qu'elle surveille — c'est ainsi que celle-ci
     * était passée.
     */
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path('Http/Controllers/TraceabilityController.php')));

    // Méthodes servant les routes publiques `trace.*` (sans contrôle d'accès).
    $publiques = ['batch', 'mill', 'crop', 'dispatch', 'harvest'];

    $fuites = [];

    foreach ($publiques as $methode) {
        // Corps de la méthode, jusqu'à la déclaration suivante.
        if (! preg_match('#public function ' . $methode . '\((.*?)\n    public function #s', $code . "\n    public function ", $m)) {
            continue;
        }

        foreach (['driver_phone', 'phone', 'email', 'address'] as $champ) {
            if (str_contains($m[1], $champ)) {
                $fuites[] = "trace.{$methode} expose « {$champ} »";
            }
        }
    }

    expect($fuites)->toBe([]);
});

test('la garde lit bien les méthodes qu’elle prétend inspecter', function () {
    // Un test dérivé qui n'inspecterait rien passerait à vide.
    $code = file_get_contents(app_path('Http/Controllers/TraceabilityController.php'));

    foreach (['batch', 'mill', 'crop', 'dispatch', 'harvest'] as $methode) {
        expect($code)->toContain("public function {$methode}(");
    }
});

test('la page reste publique : le QR d’une étiquette doit fonctionner sans compte', function () {
    // Non-régression du besoin d'origine — on corrige ce qui s'affiche, pas
    // l'accès.
    $this->get(route('trace.dispatch', 'EXP-2026-000042'))
        ->assertOk()
        ->assertSee('EXP-2026-000042')
        ->assertSee('Kérouané');
});
