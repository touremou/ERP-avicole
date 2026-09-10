<?php

use App\Models\Batch;
use App\Models\FeedPurchase;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * TROIS GESTES SUR LE MÊME OBJET, DEUX MODULES DIFFÉRENTS.
 *
 * Sur la fiche de bande, un achat direct d'aliment se crée, se rectifie et
 * s'annule. Les trois boutons sont côte à côte dans le MÊME fichier :
 *
 *   • le crayon      → `@can('provenderie.M')`   ✅ le module de sa porte
 *   • la corbeille   → `@can('provenderie.S')`   ✅ le module de sa porte
 *   • « Achat direct aliment » → `@can('elevage.C')`   ❌
 *
 * Or la porte, elle, ne change pas de module : `feed-purchases.store` porte un
 * `can:C` nu, que `Module::routePrefixMap()` résout en `feed-purchases.` →
 * **provenderie**, et `StoreFeedPurchaseRequest::authorize()` redemande le même
 * `Gate::allows('C')`. Deux déclarations pour un seul geste, et elles ne parlent
 * pas du même module.
 *
 * ─── LE DÉFAUT FRAPPE DANS LES DEUX SENS ───
 *
 * • Le chef d'élevage (`elevage.C`, pas `provenderie.C`) VOIT le bouton, ouvre
 *   la modale, saisit fournisseur, quantité, prix et date — et se fait refuser à
 *   l'envoi. Un bouton qui échoue toujours vaut moins que pas de bouton.
 *
 * • Le magasinier provenderie (`provenderie.C`, `elevage.L`) DÉTIENT le droit
 *   qu'exige la porte, et ne voit pas le bouton. Et c'est sans recours : les
 *   routes de ce contrôleur le disent elles-mêmes — « Ni `index` ni `create`
 *   n'ont jamais été écrites […] : un ravitaillement se saisit depuis la fiche
 *   de bande ». Cette modale est l'UNIQUE porte. Son droit était inutilisable.
 *
 * L'écran s'aligne donc sur sa porte, comme le font déjà le crayon et la
 * corbeille deux cents lignes plus bas.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();

    $this->lot = Batch::factory()->create([
        'building_id' => $this->building->id,
        'status'      => 'Actif',
    ]);
});

/**
 * Un compte dont le rôle porte des droits DIFFÉRENTS selon le module — c'est
 * précisément ce que ce défaut confond.
 *
 * @param  array<string, list<string>>  $parModule  slug de module => lettres
 * @param  list<string>                 $defaut     lettres pour les autres modules
 */
function compteParModule(string $nom, array $parModule, array $defaut = []): User
{
    $role = Role::create([
        'name' => $nom, 'display_name' => ucfirst($nom), 'label' => ucfirst($nom),
        'icon' => '🌾', 'permissions' => $defaut,
    ]);

    foreach (Module::pluck('slug', 'id') as $moduleId => $slug) {
        $lettres = $parModule[$slug] ?? $defaut;

        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $moduleId],
            [
                'can_read'   => in_array('L', $lettres, true),
                'can_create' => in_array('C', $lettres, true),
                'can_modify' => in_array('M', $lettres, true),
                'can_delete' => in_array('S', $lettres, true),
                'created_at' => now(), 'updated_at' => now(),
            ],
        );
    }

    Cache::flush();   // les droits sont mémorisés par utilisateur (rbac_perms_*)

    return User::factory()->create(['role_id' => $role->id]);
}

/** La charge d'un achat direct, telle que l'envoie la modale de la fiche. */
function achatDirectAliment(int $batchId, string $fournisseur): array
{
    return [
        'batch_id'      => $batchId,
        'supplier'      => $fournisseur,
        'feed_type'     => 'Démarrage',
        'quantity'      => 20,
        'unit'          => 'Sac',
        'unit_price'    => 250_000,
        'purchase_date' => today()->toDateString(),
    ];
}

test('le magasinier qui DÉTIENT le droit voit le bouton', function () {
    /*
     * LE défaut, dans le sens le plus grave : cette modale est l'unique porte de
     * l'achat direct, et le titulaire du droit ne la voyait pas.
     */
    $magasinier = compteParModule('magasinier_provende', [
        'provenderie' => ['L', 'C'],
        'elevage'     => ['L'],
    ]);

    $this->actingAs($magasinier)
        ->get(route('batches.show', $this->lot->id))
        ->assertOk()
        ->assertSee('Achat direct aliment', false);
});

test('et sa porte l’accepte — l’écran ne mentait pas', function () {
    // Le bouton offert doit mener quelque part : on vérifie la porte elle-même.
    $magasinier = compteParModule('magasinier_provende2', [
        'provenderie' => ['L', 'C'],
        'elevage'     => ['L'],
    ]);

    $this->actingAs($magasinier)
        ->post(route('feed-purchases.store'), achatDirectAliment($this->lot->id, $this->provider->name));

    expect(FeedPurchase::withoutGlobalScopes()->count())->toBe(1);
});

test('le chef d’élevage sans droit provenderie ne voit plus un bouton qui échoue', function () {
    /*
     * L'autre sens : il VOYAIT le bouton, remplissait la modale entière, et se
     * faisait refuser à l'envoi.
     */
    $chefElevage = compteParModule('chef_elevage_sans_provende', [
        'elevage'     => ['L', 'C', 'M'],
        'provenderie' => ['L'],
    ]);

    $page = $this->actingAs($chefElevage)->get(route('batches.show', $this->lot->id));

    $page->assertOk()->assertDontSee('Achat direct aliment', false);

    // Et la porte le refusait bien : c'est ce que le bouton lui cachait.
    $this->actingAs($chefElevage)
        ->post(route('feed-purchases.store'), achatDirectAliment($this->lot->id, $this->provider->name));

    expect(FeedPurchase::withoutGlobalScopes()->count())->toBe(0);
});

test('le crayon et la corbeille gardent leurs verrous — non-régression', function () {
    /*
     * Les deux gestes voisins, qui disaient déjà juste. C'est d'eux que la
     * création s'aligne — on ne les touche pas.
     */
    $achat = FeedPurchase::create([
        'batch_id'      => $this->lot->id,
        'supplier'      => $this->provider->name,
        'feed_type'     => 'Démarrage',
        'quantity'      => 10,
        'unit'          => 'Sac',
        'unit_price'    => 250_000,
        'total_price'   => 2_500_000,
        'purchase_date' => today()->toDateString(),
    ]);

    // Crée mais ne rectifie pas : pas de crayon.
    $createur = compteParModule('provende_createur', [
        'provenderie' => ['L', 'C'], 'elevage' => ['L'],
    ]);

    $this->actingAs($createur)
        ->get(route('batches.show', $this->lot->id))
        ->assertOk()
        ->assertDontSee(route('feed-purchases.edit', $achat->id), false);

    // Rectifie : crayon présent.
    $rectificateur = compteParModule('provende_rectificateur', [
        'provenderie' => ['L', 'C', 'M'], 'elevage' => ['L'],
    ]);

    $this->actingAs($rectificateur)
        ->get(route('batches.show', $this->lot->id))
        ->assertOk()
        ->assertSee(route('feed-purchases.edit', $achat->id), false);
});

test('l’administrateur garde le bouton — non-régression', function () {
    // Le cas de très loin le plus courant : rien ne change pour lui.
    $this->actingAs($this->adminUser)
        ->get(route('batches.show', $this->lot->id))
        ->assertOk()
        ->assertSee('Achat direct aliment', false);

    $this->actingAs($this->adminUser)
        ->post(route('feed-purchases.store'), achatDirectAliment($this->lot->id, $this->provider->name));

    expect(FeedPurchase::withoutGlobalScopes()->count())->toBe(1);
});
