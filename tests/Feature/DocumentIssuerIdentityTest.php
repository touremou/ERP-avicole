<?php

use App\Models\Setting;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LES FACTURES PARTAIENT AU NOM D'UNE AUTRE SOCIÉTÉ.
 *
 * Le bloc « Vendeur » de la facture — la seule partie qui identifie légalement
 * l'émetteur — imprimait deux valeurs CODÉES EN DUR :
 *
 *     <div class="party-name">AviSmart SARL</div>
 *     {{ __("Conakry, République de Guinée") }}
 *
 * Aucun réglage ne pouvait les atteindre. Le promoteur renseignait le nom de son
 * exploitation, l'en-tête de la page l'affichait bien, et la mention légale
 * continuait de désigner AviSmart SARL. Ses factures partaient donc chez ses clients
 * au nom et à l'adresse d'une autre société, sans que rien ne le lui dise.
 *
 * C'est le même défaut que celui corrigé sur companyName() plus tôt dans cet audit —
 * le nom saisi qui n'atteignait pas les messages sortants. Il avait survécu ici sous
 * une forme pire : non pas un repli mal choisi, mais une valeur en dur.
 *
 * ─── QUATRE DÉCLARATIONS, QUATRE SOUS-ENSEMBLES ───
 *
 * L'identité de l'émetteur était écrite quatre fois, et chaque document n'en
 * imprimait qu'une partie :
 *
 *   • FACTURE : nom, pays, NIF, RCCM — ni adresse ni téléphone ;
 *   • TICKET de vente : nom, NIF, RCCM ;
 *   • REÇU de caisse : nom, adresse, téléphone — sans NIF ni RCCM ;
 *   • BULLETIN de paie : nom, adresse, téléphone.
 *
 * La facture, celle qui part chez le client et qui compte fiscalement, était donc
 * justement celle qui ne portait pas l'adresse de l'émetteur. Une seule déclaration
 * désormais : Setting::companyIdentity().
 *
 * ─── CE QUI N'EST PAS TOUCHÉ, ET POURQUOI ───
 *
 * Les pieds de page « Document généré par AviSmart » nomment le LOGICIEL, pas le
 * vendeur. C'est une mention d'outil, comparable à un filigrane : elle reste.
 */

/**
 * Vente imprimable. Nom PRÉFIXÉ : les fonctions déclarées dans un fichier Pest sont
 * GLOBALES, et `makeSale()` existe déjà dans SalePrintFormatTest — la collision
 * provoque une erreur fatale qui n'apparaît qu'en jouant la suite entière.
 */
function issuerTestSale(int $farmId, int $userId): \App\Models\Sale
{
    $client = \App\Models\Client::create([
        'farm_id' => $farmId, 'client_id' => 'CLI-IDENT', 'name' => 'Boutique du Marché',
        'type' => 'entreprise', 'category' => 'detaillant',
    ]);

    $sale = \App\Models\Sale::create([
        'farm_id' => $farmId, 'reference' => 'FAC-2026-000123', 'client_id' => $client->id,
        'user_id' => $userId, 'sale_date' => now(), 'type' => 'facture',
        'status' => 'valide', 'subtotal' => 100000, 'tax_rate' => 18, 'tax_amount' => 18000,
        'total_amount' => 118000, 'paid_amount' => 0, 'payment_status' => 'impaye',
    ]);

    \App\Models\SaleItem::create([
        'farm_id' => $farmId, 'sale_id' => $sale->id, 'product_type' => 'oeufs',
        'product_name' => 'Œufs calibre L', 'quantity' => 10, 'unit' => 'Alvéole',
        'unit_price' => 10000, 'total' => 100000,
    ]);

    return $sale;
}

beforeEach(function () {
    $this->setUpRbac();

    // L'exploitation réelle, telle que le promoteur la renseignerait.
    Setting::set('general.company_name', 'Ferme Avicole de Kindia');
    Setting::set('general.company_address', 'Route de Mamou, Kindia');
    Setting::set('general.company_phone', '+224 620 00 00 00');
    Setting::set('general.fiscal_id', 'NIF-123456789');
    Setting::set('general.rccm', 'RCCM/GC-KAL/2026-A-1234');
    Setting::clearCache();
});

test('la FACTURE ne porte plus le nom d’une autre société', function () {
    // LE défaut. Avant correction, cette page affichait « AviSmart SARL » quoi
    // qu'on saisisse.
    $sale = issuerTestSale($this->farm->id, $this->adminUser->id);

    $html = $this->actingAs($this->adminUser)->get(route('sales.print', $sale))->assertOk()->getContent();

    expect($html)->toContain('Ferme Avicole de Kindia')
        ->and($html)->not->toContain('AviSmart SARL')
        ->and($html)->not->toContain('Conakry, République de Guinée');
});

test('la FACTURE porte l’adresse et le téléphone de l’émetteur', function () {
    // Elles manquaient, alors que le bulletin de paie et le reçu de caisse les
    // imprimaient déjà.
    $sale = issuerTestSale($this->farm->id, $this->adminUser->id);

    $html = $this->actingAs($this->adminUser)->get(route('sales.print', $sale))->assertOk()->getContent();

    expect($html)->toContain('Route de Mamou, Kindia')
        ->and($html)->toContain('+224 620 00 00 00');
});

test('la FACTURE porte le NIF et le RCCM', function () {
    // Ceux-là y étaient déjà : on vérifie que la réécriture ne les a pas perdus.
    $sale = issuerTestSale($this->farm->id, $this->adminUser->id);

    $html = $this->actingAs($this->adminUser)->get(route('sales.print', $sale))->assertOk()->getContent();

    expect($html)->toContain('NIF-123456789')
        ->and($html)->toContain('RCCM/GC-KAL/2026-A-1234');
});

test('une mention NON renseignée est masquée, pas remplacée par une invention', function () {
    // Sur une mention légale, une supposition ne vaut pas mieux qu'un blanc. Le
    // bulletin de paie imprimait « République de Guinée » à la place de l'adresse
    // absente : un blanc déguisé en renseignement.
    Setting::set('general.company_address', '');
    Setting::set('general.company_phone', '');
    Setting::clearCache();

    $sale = issuerTestSale($this->farm->id, $this->adminUser->id);

    $html = $this->actingAs($this->adminUser)->get(route('sales.print', $sale))->assertOk()->getContent();

    expect($html)->toContain('Ferme Avicole de Kindia')
        // Aucune adresse inventée n'apparaît.
        ->and($html)->not->toContain('République de Guinée<br>');
});

test('les quatre documents lisent la MÊME déclaration', function () {
    // Le fond du défaut n'était pas une valeur manquante : c'était quatre
    // déclarations divergentes. Si un cinquième document relit les réglages un par
    // un, la divergence revient — et ce test doit tomber.
    $documents = [
        'sales/print.blade.php',
        'sales/ticket.blade.php',
        'pos/receipt.blade.php',
        'payroll/print.blade.php',
    ];

    $offenders = [];

    foreach ($documents as $document) {
        $source = file_get_contents(resource_path('views/' . $document));

        if (! str_contains($source, 'companyIdentity()')) {
            $offenders[] = "{$document} ne lit pas Setting::companyIdentity()";
        }

        // Lectures directes des clefs d'identité : c'est ce qui divergeait.
        foreach (['general.company_name', 'general.company_address', 'general.company_phone', 'general.fiscal_id', 'general.rccm'] as $key) {
            if (str_contains($source, "setting('{$key}'")) {
                $offenders[] = "{$document} relit {$key} directement";
            }
        }
    }

    expect($offenders)->toBe([], "Identité de l’émetteur redéclarée :\n  " . implode("\n  ", $offenders));
});

test('aucun document ne code en dur une identité de société', function () {
    // Le garde-fou de forme. « AviSmart » reste légitime dans un pied de page qui
    // nomme le LOGICIEL — « Document généré par AviSmart » — mais pas comme nom ou
    // adresse de l'émetteur.
    $offenders = [];

    foreach (glob(resource_path('views/**/*.blade.php')) as $file) {
        $source = file_get_contents($file);

        foreach (['AviSmart SARL', 'Conakry, République de Guinée'] as $needle) {
            // Une mention DANS un commentaire Blade explique le défaut : elle ne le
            // reproduit pas.
            $withoutComments = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

            if (str_contains((string) $withoutComments, $needle)) {
                $offenders[] = basename($file) . " contient « {$needle} »";
            }
        }
    }

    expect($offenders)->toBe([], "Identité de société codée en dur :\n  " . implode("\n  ", $offenders));
});

test('le diagnostic signale une identité INCOMPLÈTE', function () {
    // Une mention absente ne se voit pas à l'écran : elle se voit sur le document
    // que le client a déjà reçu.
    Setting::set('general.fiscal_id', '');
    Setting::set('general.rccm', '');
    Setting::clearCache();

    \Illuminate\Support\Facades\Artisan::call('avismart:diagnostic');
    $out = \Illuminate\Support\Facades\Artisan::output();

    expect($out)->toContain('NIF')
        ->and($out)->toContain('n’est pas un justificatif recevable');
});

test('le diagnostic est au vert quand l’identité est complète', function () {
    // Le pendant : un diagnostic qui crie toujours ne se lit plus.
    \Illuminate\Support\Facades\Artisan::call('avismart:diagnostic');

    expect(\Illuminate\Support\Facades\Artisan::output())->toContain('une identité complète');
});

test('la page d’erreur 500 ne promet plus un canal qui peut être muet', function () {
    // Elle affirmait « L'admin a été notifié par WhatsApp ». Sur une installation
    // dont le WhatsApp est en mode journal, personne n'était notifié du tout —
    // l'affirmation était donc fausse au moment où elle importait le plus.
    $source = file_get_contents(resource_path('views/errors/500.blade.php'));
    $withoutComments = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

    expect($withoutComments)->not->toContain('notifié par WhatsApp')
        ->and($withoutComments)->toContain('alertée');
});
