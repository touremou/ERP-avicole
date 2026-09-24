<?php

use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA FACTURE NE DISAIT PAS L'ÉCHÉANCE QUE LE SYSTÈME ALLAIT FAIRE RESPECTER.
 *
 * `ventes.payment_delay_days` avait TROIS lecteurs et DEUX défauts :
 *
 *   • `Sale::scopeOverdue()`        → setting(…, 30)
 *   • `Sale::getDueDateAttribute()` → setting(…, 30)
 *   • `sales/print.blade.php`       → setting(…, 0)
 *
 * Tant que le réglage est renseigné, les trois s'accordent. Quand il ne l'est
 * pas — installation fraîche, ou case VIDÉE à l'écran, cas désormais traité
 * comme une absence depuis la correction de `Setting::get` — ils divergent de
 * trente jours.
 *
 * ─── MESURÉ ───
 *
 * Une facture du 1er juin 2026, 500 000 GNF, impayée, réglage absent :
 *
 *   • la facture REMISE AU CLIENT ne porte AUCUNE échéance. Le bloc est
 *     conditionné à `$delai > 0`, et le défaut local valait zéro ;
 *   • le système fixe l'échéance au 1er juillet, compte « 1 jour de retard »
 *     dès le 2, et fait entrer la vente dans `scopeOverdue`.
 *
 * Or les ventes en retard sont relancées : une commande planifiée écrit aux
 * clients chaque matin. Le client est donc relancé pour un retard mesuré contre
 * une échéance qu'on ne lui a JAMAIS communiquée — et il n'a, sur son document,
 * rien à opposer.
 *
 * ─── LA RÈGLE POSÉE ───
 *
 * Une seule déclaration, `Sale::paymentDelayDays()`, que les trois lecteurs
 * appellent. Même remède que `Building::sanitaryBreakDays()`, pour la même
 * raison : un réglage lu à plusieurs endroits n'a de sens que s'il n'est
 * DÉCLARÉ qu'à un seul.
 *
 * Le défaut retenu est TRENTE — celui des deux lecteurs qui portent la règle
 * métier. Le zéro de la vue n'était pas un autre délai : c'était sa façon de
 * dire « rien à imprimer », intention que la condition `> 0` sert toujours.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
    $this->travelTo('2026-06-01 09:00:00');
});

/** Une facture impayée de 500 000 GNF, datée d'aujourd'hui. */
function factureImpayee(): Sale
{
    $farm = session('current_farm_id');

    $client = Client::create([
        'farm_id' => $farm, 'client_id' => 'CLI-' . uniqid(), 'name' => 'Boutique',
        'type' => 'entreprise', 'category' => 'detaillant',
    ]);

    $vente = Sale::create([
        'farm_id' => $farm, 'reference' => 'FAC-' . uniqid(), 'client_id' => $client->id,
        'user_id' => \App\Models\User::value('id'), 'sale_date' => now(), 'type' => 'facture',
        'status' => 'valide', 'subtotal' => 500_000, 'total_amount' => 500_000,
        'paid_amount' => 0, 'payment_status' => 'impaye',
    ]);

    SaleItem::create([
        'farm_id' => $farm, 'sale_id' => $vente->id, 'product_type' => 'oeufs',
        'product_name' => 'Œufs L', 'quantity' => 10, 'unit' => 'Alvéole',
        'unit_price' => 50_000, 'total' => 500_000,
    ]);

    return $vente;
}

/** Efface le réglage : installation fraîche, ou case vidée à l'écran. */
function sansReglageDeDelai(): void
{
    Setting::where('key', 'payment_delay_days')->delete();
    Cache::flush();
}

/** Renseigne le délai, comme le ferait l'écran des Réglages. */
function reglerLeDelai(int $jours): void
{
    Setting::updateOrCreate(
        ['group' => 'ventes', 'key' => 'payment_delay_days'],
        ['value' => (string) $jours, 'type' => 'number', 'label' => 'Délai de paiement', 'unit' => 'j'],
    );

    Cache::flush();
}

test('réglage absent : la facture ANNONCE l’échéance que le système appliquera', function () {
    /*
     * LE défaut : la facture ne portait aucune échéance, alors que le système
     * en tenait une — et relançait contre elle.
     */
    sansReglageDeDelai();
    $vente = factureImpayee();

    $html = $this->get(route('sales.print', $vente))->assertOk()->getContent();

    expect($html)->toContain('Échéance')
        ->and($vente->due_date->toDateString())->toBe('2026-07-01');
});

test('et la date imprimée est CELLE que le système fera respecter', function () {
    /*
     * L'invariant. Deux lecteurs du même réglage doivent dire la même date :
     * leur désaccord était la maladie, leur égalité est la guérison.
     */
    sansReglageDeDelai();
    $vente = factureImpayee();

    $html = $this->get(route('sales.print', $vente))->assertOk()->getContent();

    expect($html)->toContain($vente->due_date->translatedFormat('d F Y'));
});

test('le retard se compte à partir de cette même date', function () {
    /*
     * De bout en bout : c'est `scopeOverdue` qui alimente les relances. Une
     * échéance imprimée que le retard ne respecterait pas n'aurait rien réglé.
     */
    sansReglageDeDelai();
    $vente = factureImpayee();

    $this->travelTo('2026-06-30 09:00:00');   // veille de l'échéance
    expect(Sale::overdue()->whereKey($vente->id)->exists())->toBeFalse();

    $this->travelTo('2026-07-02 09:00:00');   // lendemain
    expect(Sale::overdue()->whereKey($vente->id)->exists())->toBeTrue()
        ->and($vente->fresh()->days_overdue)->toBe(1);
});

test('un délai RÉGLÉ est suivi par les deux, comme avant — non-régression', function () {
    // Le cas courant : le réglage est renseigné, rien ne doit changer.
    reglerLeDelai(15);

    $vente = factureImpayee();

    $html = $this->get(route('sales.print', $vente))->assertOk()->getContent();

    expect($vente->due_date->toDateString())->toBe('2026-06-16')
        ->and($html)->toContain($vente->due_date->translatedFormat('d F Y'));
});

test('un délai réglé à ZÉRO n’imprime pas d’échéance — non-régression', function () {
    /*
     * L'intention de la vue est préservée : « zéro » veut dire « payable à la
     * remise », et il n'y a alors pas de date à annoncer. Ce qui change, c'est
     * que ce zéro doit être VOULU, pas hérité d'un défaut local.
     */
    reglerLeDelai(0);

    $vente = factureImpayee();

    $html = $this->get(route('sales.print', $vente))->assertOk()->getContent();

    expect($html)->not->toContain('Échéance')
        ->and($vente->due_date->toDateString())->toBe('2026-06-01');
});

test('la déclaration est UNIQUE : plus aucun lecteur ne porte son propre défaut', function () {
    /*
     * La garde qui empêche la divergence de revenir. Un quatrième lecteur qui
     * relirait le réglage en direct rouvrirait exactement ce défaut — et c'est
     * ainsi qu'il est né.
     */
    $sources = [
        file_get_contents(base_path('app/Models/Sale.php')),
        file_get_contents(resource_path('views/sales/print.blade.php')),
    ];

    $lecturesDirectes = 0;
    foreach ($sources as $src) {
        // Les commentaires CITENT l'ancien code pour l'expliquer : les compter
        // ferait échouer cette garde sur sa propre documentation.
        $sansCommentaires = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#', '#\{\{--.*?--\}\}#s'], '', $src);
        $lecturesDirectes += preg_match_all("/setting\(\s*'ventes\.payment_delay_days'/", $sansCommentaires);
    }

    // La seule lecture directe tolérée est celle de la déclaration elle-même.
    expect($lecturesDirectes)->toBe(1);
});
