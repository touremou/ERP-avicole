<?php

use App\Models\Farm;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DÉSACTIVER UN SITE NE DÉSACTIVAIT RIEN, ET LES TÂCHES DE NUIT Y TRAVAILLAIENT ENCORE.
 *
 * `Farm` n'a délibérément PAS le trait BelongsToFarm — c'est écrit dans le modèle.
 * Sur ce modèle, `withoutGlobalScopes()` ne retire donc rien d'utile : seulement la
 * protection des SUPPRESSIONS. Mesuré sur une base portant une ferme active et une
 * ferme supprimée :
 *
 *     Farm::active()                                   → 1
 *     Farm::withoutGlobalScopes()->where('is_active')  → 2
 *
 * Cinq tâches planifiées écrivaient la seconde forme, ou pire.
 *
 * ─── LE PIRE : tasks:generate ───
 *
 * Elle lisait `DB::table('farms')->pluck('id')` — AUCUN filtre. Un site désactivé, ou
 * même supprimé, recevait donc ses tâches quotidiennes chaque matin à 05:00,
 * indéfiniment, et elles s'accumulaient dans les compteurs de retard. Mesuré avant
 * correction : 4 sites parcourus dont un inactif et un supprimé, 4 tâches créées pour
 * chacun.
 *
 * ─── LE CÔTÉ HUMAIN, TOUT AUSSI PARLANT ───
 *
 * « Peut-on travailler dans ce site ? » était écrit trois fois, une seule justement :
 *
 *   • le SÉLECTEUR de site exigeait bien `is_active` ET `deleted_at IS NULL` ;
 *   • `switchFarm()` ne vérifiait QUE le rattachement pivot ;
 *   • la ferme courante était résolue par `withoutGlobalScopes()`.
 *
 * Un site désactivé restait donc pleinement utilisable par qui y était rattaché : il
 * pouvait y basculer, l'en-tête le nommait, et toutes ses saisies y allaient — pendant
 * que le sélecteur faisait comme s'il n'existait plus.
 *
 * ─── DEUX DE CES DÉFAUTS SONT LES MIENS ───
 *
 * `avismart:diagnostic` (#213) et `stocks:sync` (#215) portaient la même faute :
 * je les ai écrites avec `withoutGlobalScopes()` en croyant neutraliser un scope de
 * ferme qui n'existe pas sur ce modèle. Elles comptaient donc les sites supprimés.
 *
 * ─── ET UNE ERREUR DE CONCEPTION, CORRIGÉE EN ROUTE ───
 *
 * Ma première déclaration mêlait « ce site tourne-t-il ? » et « ce compte y est-il
 * rattaché ? ». 412 tests sont tombés d'un coup, pour deux raisons que je n'aurais pas
 * devinées à la lecture : un compte SANS ligne dans `farm_user` retombe volontairement
 * sur la ferme par défaut (sans quoi le FarmScope ne filtrerait plus rien — fuite
 * inter-fermes en « fail-open ») ; et ce contrôle se mettait à ÉCRASER une ferme
 * explicitement choisie. La déclaration ne répond donc qu'à la première question.
 */

beforeEach(function () {
    $this->setUpRbac();
});

test('un site SUPPRIMÉ n’est plus compté parmi les sites actifs', function () {
    // Le cœur mesurable : Farm::active() exclut les suppressions, pas
    // withoutGlobalScopes().
    $avant = Farm::active()->count();

    $supprime = Farm::create(['code' => 'FT-DEL', 'name' => 'Site supprimé', 'is_active' => true]);
    $supprime->delete();

    expect(Farm::active()->count())->toBe($avant)
        // Et la forme fautive, elle, le compterait.
        ->and(Farm::withoutGlobalScopes()->where('is_active', true)->count())->toBe($avant + 1);
});

test('tasks:generate ne génère RIEN pour un site désactivé ou supprimé', function () {
    // LE défaut de ce lot. Avant correction : 4 sites parcourus dont un inactif et
    // un supprimé.
    $inactif = Farm::create(['code' => 'FT-OFF', 'name' => 'Site désactivé', 'is_active' => false]);

    $supprime = Farm::create(['code' => 'FT-DEL', 'name' => 'Site supprimé', 'is_active' => true]);
    $supprime->delete();

    \Illuminate\Support\Facades\Artisan::call('tasks:generate');

    expect(DB::table('task_assignments')->where('farm_id', $inactif->id)->count())->toBe(0)
        ->and(DB::table('task_assignments')->where('farm_id', $supprime->id)->count())->toBe(0);
});

test('tasks:generate continue de générer pour les sites EN SERVICE', function () {
    // Le pendant : un garde-fou qui empêche aussi le cas voulu ne protège rien.
    \Illuminate\Support\Facades\Artisan::call('tasks:generate');

    expect(DB::table('task_assignments')->where('farm_id', $this->farm->id)->count())
        ->toBeGreaterThan(0);
});

test('on ne peut pas BASCULER dans un site désactivé', function () {
    // Le contrôle ne portait que sur le pivot : rattaché = autorisé, même si le site
    // était éteint. « Désactiver un site » ne désactivait donc rien pour ces comptes.
    $inactif = Farm::create(['code' => 'FT-OFF', 'name' => 'Site désactivé', 'is_active' => false]);

    DB::table('farm_user')->insert([
        'farm_id' => $inactif->id, 'user_id' => $this->adminUser->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('farms.switch'), ['farm_id' => $inactif->id])
        ->assertSessionHas('error');

    expect((int) session('current_farm_id'))->not->toBe($inactif->id);
});

test('un site SUPPRIMÉ ne reste pas le site courant', function () {
    // Il restait affiché comme site courant, et le travail continuait de s'y
    // déverser — pendant que le sélecteur, sur le même écran, l'excluait déjà.
    $supprime = Farm::create(['code' => 'FT-DEL', 'name' => 'Site supprimé', 'is_active' => true]);

    DB::table('farm_user')->insert([
        'farm_id' => $supprime->id, 'user_id' => $this->adminUser->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('farms.switch'), ['farm_id' => $supprime->id])
        ->assertSessionHas('success');

    expect((int) session('current_farm_id'))->toBe($supprime->id);

    // Le site est supprimé APRÈS la bascule : c'est le cas réel, un site fermé
    // pendant qu'un compte y travaille.
    $supprime->delete();

    $this->actingAs($this->adminUser)->get(route('dashboard'));

    expect((int) session('current_farm_id'))->not->toBe($supprime->id);
});

test('une ferme explicitement choisie et EN SERVICE n’est pas écrasée', function () {
    // L'erreur de conception que j'ai faite en route : le contrôle écrasait une
    // ferme volontairement sélectionnée. Il ne répond qu'à « ce site tourne-t-il ? ».
    session(['current_farm_id' => $this->farm->id]);

    $this->actingAs($this->adminUser)->get(route('dashboard'));

    expect((int) session('current_farm_id'))->toBe($this->farm->id);
});

test('un compte SANS rattachement garde son repli mono-ferme', function () {
    // Étanchéité : sans ferme en session, le FarmScope ne filtre plus RIEN — la fuite
    // inter-fermes se ferait en « fail-open ». C'est ce que ma première version avait
    // cassé, et ce que les 412 échecs ont nommé.
    expect(DB::table('farm_user')->where('user_id', $this->adminUser->id)->count())->toBe(0);

    session()->forget('current_farm_id');

    $this->actingAs($this->adminUser)->get(route('dashboard'));

    expect(session('current_farm_id'))->not->toBeNull();
});

test('aucune tâche planifiée ne parcourt les sites par withoutGlobalScopes()', function () {
    // Garde-fou de forme. Sur Farm, cet appel ne retire QUE la protection des
    // suppressions : il n'a aucun usage légitime pour choisir des sites à traiter.
    $offenders = [];

    $files = array_merge(
        glob(app_path('Console/Commands/*.php')),
        glob(app_path('Services/*.php'))
    );

    foreach ($files as $file) {
        $source = file_get_contents($file);

        // On ne retient que les commentaires exclus : une mention explicative ne
        // reproduit pas le défaut.
        $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $source);

        if (preg_match('/Farm::withoutGlobalScopes\(\)/', (string) $code)) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([], "Sites choisis en contournant le filtre des suppressions :\n  " . implode("\n  ", $offenders));
});

test('la moyenne de performance d’un incubateur ignore les incubations supprimées', function () {
    // Sur le même écran, le TOTAL produit était calculé par Eloquent (donc sans les
    // supprimées) et la MOYENNE par DB::table() (donc avec). Deux chiffres qui ne
    // portaient pas sur les mêmes lignes.
    $controller = file_get_contents(app_path('Http/Controllers/IncubatorController.php'));

    expect($controller)->not->toContain("DB::table('incubations')");
});
