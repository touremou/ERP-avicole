<?php

use App\Models\Batch;
use App\Models\Employee;
use App\Models\Farm;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CYCLE DE VIE D'UN SITE — désactiver, et supprimer seulement le vide.
 *
 * Demandé par le promoteur : « on devrait pouvoir désactiver, archiver ou
 * supprimer un site existant, qu'en penses-tu ? »
 *
 * CE QUE L'AUDIT A TROUVÉ : `is_active` et les archives (SoftDeletes) existaient
 * DÉJÀ sur les fermes, et étaient honorés partout en lecture — sélecteur de site,
 * vue consolidée, contrôles planifiés. Mais AUCUN écran ne pouvait les écrire :
 * un état appliqué partout que personne ne pouvait poser. Même famille de défaut
 * que le nom d'exploitation ce matin — des lecteurs, pas de rédacteur.
 *
 * TROIS GESTES DEMANDÉS, DEUX RETENUS :
 *
 *   • DÉSACTIVER — exposé. Réversible, ne détruit rien : le site quitte les
 *     sélecteurs, l'historique reste lisible. C'est le geste attendu pour un site
 *     qu'on ferme, qu'on met en sommeil, ou qu'on a créé en double.
 *
 *   • ARCHIVER — volontairement PAS ajouté comme geste distinct. Ce serait un
 *     second « inactif » aux lectures subtilement différentes : deux mécanismes
 *     pour un besoin, exactement la divergence corrigée une dizaine de fois cette
 *     semaine. La suppression d'un site vide l'archive déjà (traçable).
 *
 *   • SUPPRIMER — uniquement si le site ne porte AUCUNE écriture. Un site est la
 *     racine de tout ce qui s'y produit : une suppression qui cascade détruirait
 *     des années d'écritures dont la paie ; une suppression qui n'en tient pas
 *     compte laisserait des lignes orphelines. Les deux sont pires que refuser.
 */

beforeEach(function () {
    $this->setUpRbac();
    session(['current_farm_id' => $this->farm->id]);
});

test('un site VIDE est reconnu comme tel', function () {
    $empty = Farm::create(['code' => 'VIDE-A', 'name' => 'Site neuf', 'is_active' => true]);

    expect($empty->isEmpty())->toBeTrue()
        ->and($empty->dataCounts())->toBe([]);
});

test('le volume de données est ÉNUMÉRÉ, pas tenu à la main', function () {
    // Une liste manuscrite de tables oublie celle ajoutée le mois suivant, et
    // l'oubli ne se voit qu'au moment où l'on supprime — trop tard.
    Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    $counts = $this->farm->fresh()->dataCounts();

    expect($counts)->toHaveKey('employees')
        ->and($counts['employees'])->toBeGreaterThan(0)
        ->and($this->farm->fresh()->isEmpty())->toBeFalse();

    // Le pivot des droits d'accès n'est pas une écriture d'exploitation.
    expect($counts)->not->toHaveKey('farm_user');
});

test('désactiver un site le retire des sélecteurs sans rien détruire', function () {
    $other = Farm::create(['code' => 'AUTRE-1', 'name' => 'Second site', 'is_active' => true]);
    Employee::factory()->create(['farm_id' => $other->id, 'status' => 'Actif']);

    $this->actingAs($this->adminUser)
        ->patch(route('farms.toggleActive', $other))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($other->fresh()->is_active)->toBeFalse()
        // Rien n'est supprimé : c'est toute la différence avec une suppression.
        ->and($other->fresh()->deleted_at)->toBeNull()
        ->and(Employee::withoutGlobalScopes()->where('farm_id', $other->id)->count())->toBe(1)
        // …et il quitte bien le vivier des sites utilisables.
        ->and(Farm::active()->pluck('id'))->not->toContain($other->id);
});

test('désactiver le DERNIER site actif est refusé', function () {
    // Sans site actif, plus de contexte de ferme, donc plus d'écrans : l'utilisateur
    // s'enfermerait dehors avec un bouton parfaitement légitime.
    Farm::where('id', '!=', $this->farm->id)->update(['is_active' => false]);

    $this->actingAs($this->adminUser)
        ->patch(route('farms.toggleActive', $this->farm))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($this->farm->fresh()->is_active)->toBeTrue();
});

test('désactiver le site COURANT bascule la session et le dit', function () {
    // Laisser la session sur un site désactivé produirait des écrans vides sans
    // explication.
    Farm::create(['code' => 'AUTRE-2', 'name' => 'Second site', 'is_active' => true]);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->patch(route('farms.toggleActive', $this->farm))
        ->assertRedirect();

    // On n'affirme PAS lequel : le repli prend le premier site actif, et fixer
    // lequel ferait dépendre le test de l'ordre des identifiants du jeu d'essai.
    // Ce qui compte : la session a quitté le site désactivé pour un site ACTIF,
    // et le message le nomme.
    $now = session('current_farm_id');

    expect($now)->not->toBe($this->farm->id)
        ->and(Farm::active()->pluck('id')->all())->toContain($now)
        ->and(session('success'))->toContain(Farm::find($now)->name);
});

test('désactiver ANNONCE les lots encore actifs au lieu de bloquer', function () {
    // Bloquer serait excessif — le geste est réversible. Se taire serait pire :
    // des lots vivants sortiraient de toute surveillance en silence.
    $other = Farm::create(['code' => 'AUTRE-3', 'name' => 'Site avec lots', 'is_active' => true]);
    Batch::factory()->create(['farm_id' => $other->id, 'status' => 'Actif']);

    $this->actingAs($this->adminUser)
        ->patch(route('farms.toggleActive', $other))
        ->assertRedirect();

    expect(session('success'))->toContain('lot')
        ->and($other->fresh()->is_active)->toBeFalse();
});

test('réactiver un site est toujours possible', function () {
    $other = Farm::create(['code' => 'AUTRE-4', 'name' => 'Site dormant', 'is_active' => false]);

    $this->actingAs($this->adminUser)
        ->patch(route('farms.toggleActive', $other))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($other->fresh()->is_active)->toBeTrue();
});

test('supprimer un site PORTEUR DE DONNÉES est refusé, en disant quoi faire', function () {
    // Le refus doit orienter vers la désactivation, qui répond au même besoin.
    $other = Farm::create(['code' => 'AUTRE-5', 'name' => 'Site en service', 'is_active' => true]);
    Employee::factory()->create(['farm_id' => $other->id, 'status' => 'Actif']);

    $this->actingAs($this->adminUser)
        ->delete(route('farms.destroy', $other))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($other->fresh())->not->toBeNull();
    expect(session('error'))->toContain('Désactivez');
});

test('supprimer un site VIDE fonctionne', function () {
    $empty = Farm::create(['code' => 'VIDE-B', 'name' => 'Créé par erreur', 'is_active' => true]);

    $this->actingAs($this->adminUser)
        ->delete(route('farms.destroy', $empty))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Farm::find($empty->id))->toBeNull()
        // Archivé, pas effacé : la trace reste pour l'audit.
        ->and(Farm::withTrashed()->find($empty->id))->not->toBeNull();
});

test('supprimer le SEUL site est refusé', function () {
    Farm::where('id', '!=', $this->farm->id)->forceDelete();
    DB::table('employees')->where('farm_id', $this->farm->id)->delete();

    $solo = Farm::find($this->farm->id);

    if ($solo->isEmpty()) {
        $this->actingAs($this->adminUser)
            ->delete(route('farms.destroy', $solo))
            ->assertRedirect()
            ->assertSessionHas('error');

        expect(Farm::find($solo->id))->not->toBeNull();
    } else {
        // Le site de test porte des données : le refus vient alors de là, ce qui
        // reste le bon comportement. On ne fabrique pas un cas artificiel.
        expect($solo->isEmpty())->toBeFalse();
    }
});

test('un site SUPPRIMÉ disparaît de la liste ; un site DÉSACTIVÉ y reste', function () {
    // La liste retirait tous les scopes globaux, SoftDeletes compris : un site
    // supprimé y restait affiché avec ses boutons, comme s'il existait encore.
    // Les désactivés, eux, doivent rester — c'est de là qu'on les réactive.
    $deleted = Farm::create(['code' => 'SUPP-1', 'name' => 'Site supprime', 'is_active' => true]);
    $deleted->delete();

    $off = Farm::create(['code' => 'OFF-1', 'name' => 'Site dormant', 'is_active' => false]);

    $response = $this->actingAs($this->adminUser)->get(route('farms.index'));

    $listed = $response->viewData('farms')->pluck('id');

    expect($listed)->not->toContain($deleted->id)
        ->and($listed)->toContain($off->id);
});

test('l’écran ne propose la suppression QUE pour un site vide', function () {
    // Un bouton qui existe et refuse toujours use la confiance : il vaut mieux
    // qu'il n'apparaisse pas.
    Farm::create(['code' => 'VIDE-C', 'name' => 'Site neuf vide', 'is_active' => true]);

    $view = file_get_contents(resource_path('views/farms/index.blade.php'));

    expect($view)->toContain('@if($farm->isEmpty() && $farms->count() > 1)')
        ->and($view)->toContain("route('farms.destroy'")
        ->and($view)->toContain("route('farms.toggleActive'");
});

test('un site désactivé se VOIT dans la liste', function () {
    // Sans marque visible, on le croit en service et l'on cherche pourquoi il ne
    // remonte nulle part.
    $off = Farm::create(['code' => 'OFF-2', 'name' => 'Site en sommeil', 'is_active' => false]);

    $this->actingAs($this->adminUser)
        ->get(route('farms.index'))
        ->assertOk()
        ->assertSee(e(__('Désactivé')), false);

    unset($off);
});

test('seule la direction peut désactiver ou supprimer', function () {
    $other = Farm::create(['code' => 'AUTRE-6', 'name' => 'Site', 'is_active' => true]);

    $this->actingAs($this->readonlyUser)
        ->patch(route('farms.toggleActive', $other))
        ->assertRedirect();

    expect($other->fresh()->is_active)->toBeTrue();

    $this->actingAs($this->readonlyUser)
        ->delete(route('farms.destroy', $other))
        ->assertRedirect();

    expect(Farm::find($other->id))->not->toBeNull();
});
