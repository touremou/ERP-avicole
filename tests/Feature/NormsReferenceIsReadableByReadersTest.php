<?php

use App\Models\Module;
use App\Models\ProductionNorm;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN BOUTON OFFERT À « C », UNE PORTE VERROUILLÉE À « S ».
 *
 * Le « Référentiel Normes » — la table des objectifs zootechniques par souche et
 * par semaine — se déclare en TROIS endroits, qui donnent trois réponses :
 *
 *   1. L'ÉCRAN (`batches/index.blade.php`) offre le bouton sous `@can('elevage.C')`,
 *      glissé dans le bloc portant le commentaire « PERMISSION C : CRÉATION D'UN
 *      NOUVEAU LOT » — dont il n'est pourtant pas ;
 *   2. LA ROUTE hérite du groupe `can:S` de l'administration — TOUS les verbes,
 *      `index` compris ;
 *   3. LE COMMENTAIRE de la route annonce « Gestion réservée aux admins
 *      (can:S, hérité) » — or `index` n'est pas une gestion, c'est une lecture.
 *
 * Mesuré : le rôle à qui l'écran OFFRE le bouton — `elevage.C` — reçoit 403 en
 * cliquant dessus. Un bouton qui échoue toujours vaut moins que pas de bouton.
 *
 * ─── ET LE LECTEUR CONSULTE DÉJÀ CES CHIFFRES ───
 *
 * `BatchController::show`, verrouillée à `can:L`, charge les normes et bâtit sur
 * elles la courbe de poids du lot : le lecteur VOIT déjà l'objectif hebdomadaire
 * auquel son lot est comparé. Lui refuser la table qui porte ce même chiffre ne
 * protège rien — cela l'empêche seulement de savoir contre quoi on le juge.
 *
 * ─── LA RÈGLE QU'ON APPLIQUE ───
 *
 * Celle que le fichier de routes énonce lui-même 800 lignes plus haut :
 * « Verrou de route par verbe […] : store = C, édition = M, suppression = S ».
 * La lecture se lit en L, et l'écran s'aligne sur la porte.
 *
 * L'IMPORT reste en `can:S`, sans changement : c'est la règle déjà déclarée pour
 * le référentiel normé voisin (« Le référentiel normé s'importe en S »), et un
 * import CSV écrase des lignes existantes en masse. On aligne des déclarations
 * divergentes ; on ne desserre pas une décision prise.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();

    $this->norme = ProductionNorm::create([
        'batch_type'         => 'chair',
        'model_name'         => 'Cobb 500',
        'week_number'        => 3,
        'phase_name'         => 'Croissance',
        'target_weight'      => 950,
        'target_feed_daily'  => 85,
        'target_water_daily' => 170,
    ]);
});

/**
 * Un compte dont le rôle porte EXACTEMENT ces droits sur tous les modules.
 *
 * `seedModuleMatrix` attend une LISTE de lettres : lui passer un tableau
 * associatif ne donne AUCUN droit, et tout refus observé n'apprend alors rien
 * du code mesuré.
 */
function compteNormes(string $nom, array $lettres): User
{
    $role = Role::create([
        'name' => $nom, 'display_name' => ucfirst($nom), 'label' => ucfirst($nom),
        'icon' => '📏', 'permissions' => $lettres,
    ]);

    foreach (Module::pluck('id') as $moduleId) {
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

test('le rôle à qui l’écran OFFRE le bouton peut l’ouvrir', function () {
    /*
     * LE défaut, dans sa forme la plus nette : l'écran propose, la route refuse.
     */
    $eleveur = compteNormes('eleveur_normes', ['L', 'C']);

    $this->actingAs($eleveur)
        ->get(route('batches.norms.index'))
        ->assertOk();
});

test('un LECTEUR peut consulter le référentiel', function () {
    /*
     * Il voit déjà ces chiffres sur la fiche du lot (courbe de poids, bâtie sur
     * les normes) : la table qui les porte est une lecture comme une autre.
     */
    $lecteur = compteNormes('lecteur_normes', ['L']);

    $this->actingAs($lecteur)
        ->get(route('batches.norms.index'))
        ->assertOk()
        ->assertSee('Cobb 500', false);
});

test('l’écran des lots offre le lien au LECTEUR', function () {
    /*
     * Le bouton était rangé dans le bloc « PERMISSION C : CRÉATION D'UN NOUVEAU
     * LOT », dont il ne fait pas partie. Il s'aligne sur le verrou de sa porte.
     */
    $lecteur = compteNormes('lecteur_normes2', ['L']);

    $this->actingAs($lecteur)
        ->get(route('batches.index'))
        ->assertOk()
        ->assertSee(route('batches.norms.index'), false);
});

test('créer une norme demande le droit de CRÉER — non-régression', function () {
    $lecteur = compteNormes('lecteur_normes3', ['L']);

    $this->actingAs($lecteur)->post(route('batches.norms.store'), [
        'batch_type' => 'chair', 'week_number' => 4, 'phase_name' => 'Finition',
        'target_weight' => 1400, 'model_name' => 'Cobb 500',
    ]);

    expect(ProductionNorm::count())->toBe(1);

    $createur = compteNormes('createur_normes', ['L', 'C']);

    $this->actingAs($createur)->post(route('batches.norms.store'), [
        'batch_type' => 'chair', 'week_number' => 4, 'phase_name' => 'Finition',
        'target_weight' => 1400, 'model_name' => 'Cobb 500',
    ]);

    expect(ProductionNorm::count())->toBe(2);
});

test('supprimer une norme demande le droit de SUPPRIMER — non-régression', function () {
    /*
     * LA borne : on aligne les verrous, on ne les ouvre pas. Un rôle qui crée et
     * modifie ne supprime pas le référentiel pour autant.
     */
    $operateur = compteNormes('operateur_normes', ['L', 'C', 'M']);

    $this->actingAs($operateur)->delete(route('batches.norms.destroy', $this->norme->id));

    expect(ProductionNorm::whereKey($this->norme->id)->exists())->toBeTrue();

    $admin = compteNormes('admin_normes', ['L', 'C', 'M', 'S']);

    $this->actingAs($admin)->delete(route('batches.norms.destroy', $this->norme->id));

    expect(ProductionNorm::whereKey($this->norme->id)->exists())->toBeFalse();
});

test('l’IMPORT de masse reste réservé au droit de SUPPRIMER — non-régression', function () {
    /*
     * La règle déjà déclarée pour le référentiel normé voisin, qu'on ne desserre
     * pas : un import CSV écrase des lignes existantes en masse.
     *
     * On mesure le verrou par son EFFET, et non par le code HTTP : un refus du
     * middleware `can` sur une route web est une REDIRECTION, que rien ne
     * distingue d'un succès qui rend `back()`.
     */
    $csv = "semaine,phase,poids,ponte,aliment,eau,souche\n"
         . "5,Finition,1800,0,110,220,Ross 308\n";

    $fichier = fn () => \Illuminate\Http\UploadedFile::fake()
        ->createWithContent('normes.csv', $csv);

    $operateur = compteNormes('operateur_normes2', ['L', 'C', 'M']);

    $this->actingAs($operateur)
        ->post(route('batches.norms.import'), ['file' => $fichier(), 'batch_type' => 'chair']);

    expect(ProductionNorm::count())->toBe(1);   // rien importé

    $admin = compteNormes('admin_normes2', ['L', 'C', 'M', 'S']);

    $this->actingAs($admin)
        ->post(route('batches.norms.import'), ['file' => $fichier(), 'batch_type' => 'chair']);

    expect(ProductionNorm::count())->toBe(2);
});

test('l’administrateur garde tous les gestes — non-régression', function () {
    // Le cas de très loin le plus courant : rien ne change pour lui.
    $this->actingAs($this->adminUser)
        ->get(route('batches.norms.index'))
        ->assertOk();

    $this->actingAs($this->adminUser)
        ->delete(route('batches.norms.destroy', $this->norme->id));

    expect(ProductionNorm::count())->toBe(0);
});
