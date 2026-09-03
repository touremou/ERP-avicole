<?php

use App\Actions\Slaughter\RecordSlaughterReception;
use App\Models\Provider;
use App\Models\SupplierInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'EXPLOITATION FACTURAIT DES SUJETS QU'ELLE AVAIT RENDUS À L'ÉLEVEUR.
 *
 * `RecordSlaughterReception` déclenche la facturation dès que
 * `origin === 'achat'` — sans jamais regarder ce que le contrôle ante-mortem a
 * décidé. Et `computePurchaseCost()` retenait `received_quantity`, les sujets
 * ARRIVÉS, sans soustraire `rejected_quantity`.
 *
 * Mesuré, sur un achat à 20 000 GNF le sujet :
 *
 *   • 100 sujets REFUSÉS et renvoyés : facture de 2 000 000 au lieu de zéro ;
 *   • 100 reçus dont 30 rejetés : facture de 2 000 000 au lieu de 1 400 000.
 *
 * ─── LA RÈGLE ÉTAIT DÉJÀ DANS LE MODÈLE ───
 *
 * `SlaughterReception::acceptedQuantity()` — « reçus − écartés à l'ante-mortem »
 * — existait, et `remainingQuantity()` s'en servait déjà pour borner le quota de
 * sujets qu'un ordre d'abattage peut consommer.
 *
 * Le modèle savait donc que les sujets rejetés ne comptent pas. Il le savait pour
 * l'ABATTAGE, et l'ignorait pour la FACTURE, à quelques lignes d'intervalle.
 *
 * ─── CE QUE ÇA VIDE DE SON SENS ───
 *
 * L'inspection ante-mortem sert précisément à REFUSER une marchandise non
 * conforme. La payer quand même ôte au contrôle son seul effet économique : le
 * vétérinaire refuse, la comptabilité paie.
 *
 * ─── LES TROIS BASES D'ACHAT ───
 *
 * `par_sujet` et `par_kg_vif` sont proportionnelles : elles se proratent au
 * nombre RETENU. Le poids vif étant pesé sur le lot entier, faute de pesée
 * individuelle on l'impute au prorata des têtes — la meilleure estimation
 * disponible, pas un chiffre inventé.
 *
 * `forfait` est un prix négocié pour la livraison : un rejet partiel ne le
 * découpe pas, cela se renégocie. Un refus TOTAL l'annule — il n'y a plus de
 * livraison.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->eleveur = Provider::create([
        'farm_id' => $this->farm->id,
        'name'    => 'Éleveur Kindia',
        'type'    => 'fournisseur',
        'phone'   => '+224620000000',
        'status'  => 'actif',
    ]);
});

/** Une réception de sujets vifs achetés, au verdict et à la base voulus. */
function receptionVif(
    int $farmId,
    int $providerId,
    int $controllerId,
    string $decision,
    int $recus,
    int $rejetes,
    string $base = 'par_sujet',
    float $prix = 20_000,
): void {
    app(RecordSlaughterReception::class)->execute([
        'farm_id'              => $farmId,
        'provider_id'          => $providerId,
        'controller_id'        => $controllerId,
        'origin'               => 'achat',
        'reception_date'       => today()->toDateString(),
        'arrived_at'           => now()->toDateTimeString(),
        'announced_quantity'   => $recus,
        'received_quantity'    => $recus,
        'rejected_quantity'    => $rejetes,
        'total_live_weight_kg' => $recus * 2.0,
        'sanitary_state'       => $decision === 'refuse' ? 'non_conforme' : 'conforme',
        'fasting_respected'    => 'oui',
        'decision'             => $decision,
        'purchase_basis'       => $base,
        'purchase_unit_price'  => $prix,
    ]);
}

/** Le montant facturé à l'éleveur, 0 si aucune facture. */
function montantFacture(int $providerId): float
{
    return (float) (SupplierInvoice::where('provider_id', $providerId)
        ->latest('id')->value('total_amount') ?? 0);
}

test('une réception REFUSÉE ne génère AUCUNE facture', function () {
    /*
     * LE défaut : les sujets repartent chez l'éleveur, et on lui devait quand
     * même 2 000 000 GNF.
     */
    receptionVif($this->farm->id, $this->eleveur->id, $this->adminUser->id, 'refuse', 100, 100);

    expect(SupplierInvoice::where('provider_id', $this->eleveur->id)->count())->toBe(0);
});

test('un rejet PARTIEL ne facture que les sujets retenus', function () {
    // 100 reçus, 30 rejetés → 70 × 20 000 = 1 400 000.
    receptionVif($this->farm->id, $this->eleveur->id, $this->adminUser->id, 'accepte', 100, 30);

    expect(montantFacture($this->eleveur->id))->toBe(1_400_000.0);
});

test('au KG VIF, le poids est proraté aux têtes retenues', function () {
    /*
     * 100 sujets pesés 200 kg au total, 30 rejetés. Faute de pesée
     * individuelle, on retient 70/100 du poids : 140 kg.
     */
    receptionVif(
        $this->farm->id, $this->eleveur->id, $this->adminUser->id,
        'accepte', 100, 30, base: 'par_kg_vif', prix: 5_000,
    );

    expect(montantFacture($this->eleveur->id))->toBe(700_000.0);   // 140 kg × 5 000
});

test('un FORFAIT n’est pas découpé par un rejet partiel', function () {
    /*
     * Un prix négocié pour la livraison ne se prorate pas : cela se renégocie
     * entre l'éleveur et l'exploitation. Découper d'office aurait été une
     * décision commerciale prise par le logiciel.
     */
    receptionVif(
        $this->farm->id, $this->eleveur->id, $this->adminUser->id,
        'accepte', 100, 30, base: 'forfait', prix: 1_800_000,
    );

    expect(montantFacture($this->eleveur->id))->toBe(1_800_000.0);
});

test('un forfait sur une réception REFUSÉE tombe bien à zéro', function () {
    // La borne du forfait : plus de livraison, plus de prix.
    receptionVif(
        $this->farm->id, $this->eleveur->id, $this->adminUser->id,
        'refuse', 100, 100, base: 'forfait', prix: 1_800_000,
    );

    expect(SupplierInvoice::where('provider_id', $this->eleveur->id)->count())->toBe(0);
});

test('une réception ENTIÈREMENT acceptée facture tout — non-régression', function () {
    /*
     * LA borne. Le cas courant ne doit pas bouger d'un franc : c'est la
     * livraison ordinaire, conforme, intégralement retenue.
     */
    receptionVif($this->farm->id, $this->eleveur->id, $this->adminUser->id, 'accepte', 100, 0);

    expect(montantFacture($this->eleveur->id))->toBe(2_000_000.0);
});

test('une réception en FAÇON ne facture rien — non-régression', function () {
    // Le façon n'est pas un achat : l'éleveur reste propriétaire de ses sujets.
    app(RecordSlaughterReception::class)->execute([
        'farm_id'              => $this->farm->id,
        'provider_id'          => $this->eleveur->id,
        'controller_id'        => $this->adminUser->id,
        'origin'               => 'facon',
        'reception_date'       => today()->toDateString(),
        'arrived_at'           => now()->toDateTimeString(),
        'announced_quantity'   => 100,
        'received_quantity'    => 100,
        'rejected_quantity'    => 0,
        'total_live_weight_kg' => 200,
        'sanitary_state'       => 'conforme',
        'fasting_respected'    => 'oui',
        'decision'             => 'accepte',
    ]);

    expect(SupplierInvoice::where('provider_id', $this->eleveur->id)->count())->toBe(0);
});
