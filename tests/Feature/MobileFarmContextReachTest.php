<?php

use App\Models\Farm;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE TERRAIN SYNCHRONISAIT ENCORE DANS UN SITE FERMÉ.
 *
 * Le lot #221 a corrigé le côté bureau : on ne peut plus basculer dans un site
 * désactivé, ni le garder comme site courant. Le même défaut vivait côté API — là où
 * il coûte le plus cher, puisque c'est le terrain qui SAISIT.
 *
 * Deux endroits, tous deux sans le moindre filtre :
 *
 *   • SetApiFarmContext lisait `farm_user` seul. Un site désactivé — ou SUPPRIMÉ —
 *     restait donc le contexte de synchronisation, et toutes les saisies du
 *     technicien continuaient d'y entrer ;
 *   • /auth/me joignait `farms` sans `is_active` ni `deleted_at` : le sélecteur de
 *     site du terrain proposait des sites fermés, alors que celui du bureau les
 *     excluait déjà. Un technicien pouvait choisir un site qui n'existe plus.
 *
 * ─── CE QUI EST CONSERVÉ, ET POURQUOI ───
 *
 * Le repli reste le même : quand un compte n'a plus AUCUN site en service, on retombe
 * sur la ferme par défaut plutôt que sur « aucune ferme ». Sans ferme en session, le
 * FarmScope ne filtre plus rien — la fuite inter-sites se ferait en « fail-open ». On
 * borne toujours à UN site, jamais à tous. C'est la règle que 412 tests m'ont
 * enseignée au lot précédent, et elle vaut ici aussi.
 *
 * Et un X-Farm-Id hors périmètre continue d'être IGNORÉ plutôt que refusé en 403 :
 * un en-tête périmé « briquerait » sinon toute l'application, /auth/me compris,
 * c'est-à-dire la requête même qui permet de se recaler.
 */

beforeEach(function () {
    $this->setUpRbac();

    // Un technicien rattaché à deux sites, comme à Kindia et Kérouané.
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->operatorUser->id,
        'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

test('le sélecteur de site du TERRAIN n’offre pas un site désactivé', function () {
    $ferme = Farm::create(['code' => 'FT-OFF', 'name' => 'Site désactivé', 'is_active' => false]);

    DB::table('farm_user')->insert([
        'farm_id' => $ferme->id, 'user_id' => $this->operatorUser->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Sanctum::actingAs($this->operatorUser);

    $farms = $this->getJson('/api/v1/auth/me')->assertOk()->json('scope.farms');

    expect(collect($farms)->pluck('id'))->not->toContain($ferme->id);
});

test('le sélecteur de site du TERRAIN n’offre pas un site supprimé', function () {
    $ferme = Farm::create(['code' => 'FT-DEL', 'name' => 'Site supprimé', 'is_active' => true]);

    DB::table('farm_user')->insert([
        'farm_id' => $ferme->id, 'user_id' => $this->operatorUser->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $ferme->delete();

    Sanctum::actingAs($this->operatorUser);

    $farms = $this->getJson('/api/v1/auth/me')->assertOk()->json('scope.farms');

    expect(collect($farms)->pluck('id'))->not->toContain($ferme->id);
});

test('le site EN SERVICE reste offert', function () {
    // Le pendant : un garde-fou qui écarte aussi le cas voulu ne protège rien.
    Sanctum::actingAs($this->operatorUser);

    $farms = $this->getJson('/api/v1/auth/me')->assertOk()->json('scope.farms');

    expect(collect($farms)->pluck('id'))->toContain($this->farm->id);
});

test('un X-Farm-Id désignant un site FERMÉ n’est pas adopté', function () {
    // Le cœur : c'est le contexte de SYNCHRONISATION. Adopté, il ferait entrer
    // toutes les saisies du technicien dans un site qui n'existe plus.
    $ferme = Farm::create(['code' => 'FT-OFF', 'name' => 'Site désactivé', 'is_active' => false]);

    DB::table('farm_user')->insert([
        'farm_id' => $ferme->id, 'user_id' => $this->operatorUser->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Sanctum::actingAs($this->operatorUser);

    $scope = $this->withHeader('X-Farm-Id', (string) $ferme->id)
        ->getJson('/api/v1/auth/me')->assertOk()->json('scope');

    expect((int) $scope['farm_id'])->not->toBe($ferme->id)
        ->and((int) $scope['farm_id'])->toBe($this->farm->id);
});

test('un X-Farm-Id valide est toujours adopté', function () {
    Sanctum::actingAs($this->operatorUser);

    $scope = $this->withHeader('X-Farm-Id', (string) $this->farm->id)
        ->getJson('/api/v1/auth/me')->assertOk()->json('scope');

    expect((int) $scope['farm_id'])->toBe($this->farm->id);
});

test('un compte dont TOUS les sites sont fermés garde un contexte, jamais « tous »', function () {
    // Étanchéité : sans ferme en session, le FarmScope ne filtre plus RIEN. On borne
    // toujours à UN site — c'est la règle que le lot précédent m'a apprise, à ses
    // dépens.
    $seul = Farm::create(['code' => 'FT-SEUL', 'name' => 'Unique site', 'is_active' => false]);

    DB::table('farm_user')->where('user_id', $this->readonlyUser->id)->delete();
    DB::table('farm_user')->insert([
        'farm_id' => $seul->id, 'user_id' => $this->readonlyUser->id,
        'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    Sanctum::actingAs($this->readonlyUser);

    $scope = $this->getJson('/api/v1/auth/me')->assertOk()->json('scope');

    expect($scope['farm_id'])->not->toBeNull()
        ->and((int) $scope['farm_id'])->not->toBe($seul->id);
});

test('le coût énergie d’une période N’EST PAS réécrit par la suppression d’une source', function () {
    /*
     * DÉCISION EXPLICITE, à rebours du balayage qui a trouvé ces jointures.
     *
     * `ReportController` et `UtilityService` joignent `energy_sources` — une table à
     * suppression logique — SANS filtrer les supprimées. Un balayage mécanique le
     * signale comme un défaut. C'en est un ailleurs ; ici, le corriger ferait BAISSER
     * après coup le coût énergie d'une période déjà arrêtée.
     *
     * Le gasoil brûlé par un groupe a coûté ce qu'il a coûté. Supprimer la fiche du
     * groupe ne doit pas réécrire un compte de résultat, pas plus que la synchro de
     * nuit ne devait réécrire un stock (#215). On garde donc la jointure telle
     * quelle, et ce test existe pour qu'on ne la « corrige » pas par réflexe.
     */
    foreach ([app_path('Services/UtilityService.php'), app_path('Http/Controllers/ReportController.php')] as $file) {
        $source = file_get_contents($file);

        expect($source)->toContain('PAS de filtre `energy_sources.deleted_at` ICI');
    }
});
