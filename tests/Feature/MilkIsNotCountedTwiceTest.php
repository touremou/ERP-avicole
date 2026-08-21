<?php

use App\Models\MilkProduction;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Accounting\PeriodRevenue;
use App\Services\DashboardInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LES MÊMES LITRES ÉTAIENT COMPTÉS DEUX FOIS : UNE FOIS TRAITS, UNE FOIS VENDUS.
 *
 * Trois écrans ajoutaient la COLLECTE DE LAIT VALORISÉE (litres × prix) au
 * chiffre d'affaires des ventes :
 *
 *   • le COMPTE DE RÉSULTAT, sous « Lait (collecte valorisée) » ;
 *   • le TABLEAU DE BORD, dans son CA du mois ;
 *   • la RENTABILITÉ PAR ESPÈCE, qui gonflait donc l'espèce laitière.
 *
 * Or la boucle est fermée depuis longtemps :
 *
 *   1. la collecte alimente l'article « Lait » du magasin
 *      (MilkProductionController::syncStock, Stock::CAT_LAIT) ;
 *   2. `lait` est un type de vente ADOSSÉ AU STOCK (SaleItem::STOCK_TYPES),
 *      proposé par le formulaire de vente et accepté à la validation.
 *
 * Traire puis vendre inscrivait donc DEUX FOIS la même recette. Le commentaire
 * d'origine disait « pas de flux de vente dédié à ce stade » : c'était vrai
 * quand il a été écrit, ça ne l'est plus, et personne n'est revenu le relire.
 *
 * ─── LA RÈGLE RETENUE ───
 *
 * Le revenu naît de la VENTE, pas de la traite. Le lait collecté est un STOCK.
 *
 * ─── CE QU'ON NE FAIT PAS DISPARAÎTRE ───
 *
 * Une traite non encore vendue est un stock réel : la taire serait aussi faux
 * que de l'appeler chiffre d'affaires. Le chiffre reste AFFICHÉ sur les deux
 * écrans, hors du total des recettes et nommé pour ce qu'il est.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
});

/** Une traite valorisée : $litres × $prix, sur un lot laitier. */
function traite(int $farmId, int $buildingId, float $litres, float $prix): MilkProduction
{
    $lot = \App\Models\Batch::factory()->create([
        'farm_id'     => $farmId,
        'building_id' => $buildingId,
        'status'      => 'Actif',
    ]);

    return MilkProduction::create([
        'farm_id'         => $farmId,
        'batch_id'        => $lot->id,
        'production_date' => today()->toDateString(),
        'morning_liters'  => $litres / 2,
        'evening_liters'  => $litres / 2,
        'unit_price'      => $prix,
        'recorded_by'     => auth()->id(),
    ]);
}

/** Une vente de lait validée, adossée au stock. */
function venteDeLait(int $farmId, float $montant): Sale
{
    $client = \App\Models\Client::create([
        'farm_id'   => $farmId,
        'client_id' => 'CLI-' . \Illuminate\Support\Str::random(6),
        'name'      => 'Laiterie du Fouta',
        'type'      => 'entreprise',
        'category'  => 'grossiste',
        'status'    => 'actif',
    ]);

    $vente = Sale::create([
        'farm_id'        => $farmId,
        'uuid'           => (string) \Illuminate\Support\Str::uuid(),
        'reference'      => 'V-LAIT-' . \Illuminate\Support\Str::random(6),
        'client_id'      => $client->id,
        'sale_date'      => today()->toDateString(),
        'type'           => 'bon_livraison',
        'status'         => 'valide',
        'subtotal'       => $montant,
        'total_amount'   => $montant,
        'paid_amount'    => $montant,
        'payment_status' => 'solde',
        'user_id'        => auth()->id(),
    ]);

    SaleItem::create([
        'sale_id'      => $vente->id,
        'product_type' => 'lait',
        'product_name' => 'Lait frais',
        'quantity'     => 1,
        'unit_price'   => $montant,
        'total'        => $montant,
    ]);

    return $vente;
}

test('traire PUIS vendre ne compte la recette qu’UNE fois', function () {
    /*
     * LE défaut, dans sa forme la plus simple : 100 L valorisés 5 000, vendus
     * 500 000. Le compte de résultat annonçait 1 000 000.
     */
    traite($this->farm->id, $this->building->id, 100, 5000);
    venteDeLait($this->farm->id, 500_000);

    $stats = $this->get(route('reports.profit_loss'))->assertOk()->viewData('totalRevenue');

    expect((float) $stats)->toBe(500_000.0);
});

test('le TABLEAU DE BORD compte comme le compte de résultat', function () {
    // Les deux écrans répondaient à « combien ai-je gagné » avec deux chiffres.
    traite($this->farm->id, $this->building->id, 100, 5000);
    venteDeLait($this->farm->id, 500_000);

    $financier = app(DashboardInsightsService::class)
        ->financial(now()->startOfMonth(), now()->endOfMonth());

    expect($financier['ca_total'])->toBe(500_000.0);
});

test('le lait collecté reste AFFICHÉ, hors des recettes', function () {
    /*
     * La borne qui empêche la correction de devenir une perte d'information :
     * une traite non vendue est un stock réel, et doit rester lisible.
     */
    traite($this->farm->id, $this->building->id, 100, 5000);

    $reponse = $this->get(route('reports.profit_loss'))->assertOk();

    expect((float) $reponse->viewData('totalRevenue'))->toBe(0.0)
        ->and((float) $reponse->viewData('milkCollected'))->toBe(500_000.0);

    expect(str_contains($reponse->getContent(), 'Lait collecté'))
        ->toBeTrue('Le lait collecté doit rester visible au compte de résultat.');
});

test('une traite SANS prix ne vaut rien — et ne casse rien', function () {
    // Le prix unitaire est facultatif : sans lui, il n'y a pas de valorisation.
    traite($this->farm->id, $this->building->id, 100, 0);

    expect(PeriodRevenue::milkCollectedValued(now()->startOfMonth(), now()->endOfMonth()))
        ->toBe(0.0);
});

test('la RENTABILITÉ PAR ESPÈCE ne gonfle plus l’espèce laitière', function () {
    /*
     * Le troisième écran. La collecte y était ajoutée au CA des lots de
     * l'espèce, par-dessus les ventes déjà comptées.
     */
    $lot = \App\Models\Batch::factory()->create([
        'farm_id'     => $this->farm->id,
        'building_id' => $this->building->id,
        'status'      => 'Actif',
    ]);

    MilkProduction::create([
        'farm_id'         => $this->farm->id,
        'batch_id'        => $lot->id,
        'production_date' => today()->toDateString(),
        'morning_liters'  => 50,
        'evening_liters'  => 50,
        'unit_price'      => 5000,
        'recorded_by'     => $this->adminUser->id,
    ]);

    $marges = $this->get(route('reports.profit_loss'))->viewData('speciesMargin');

    $ligne = collect($marges)->firstWhere('species_id', $lot->species_id);

    // Aucune vente : la collecte seule ne crée pas de recette.
    expect($ligne === null || (float) $ligne['revenue'] === 0.0)->toBeTrue();
});

test('la déclaration du lait collecté est UNIQUE', function () {
    /*
     * La garde contre le retour de la divergence : aucun des trois écrans ne
     * doit reconstruire « litres × prix » chez lui.
     */
    $fichiers = [
        'app/Http/Controllers/ReportController.php',
        'app/Services/DashboardInsightsService.php',
    ];

    foreach ($fichiers as $fichier) {
        $code = file_get_contents(base_path($fichier));

        expect(str_contains($code, 'total_liters * unit_price'))
            ->toBeFalse("Valorisation du lait reconstruite dans {$fichier}");
    }
});
