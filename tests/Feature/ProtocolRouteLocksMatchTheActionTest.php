<?php

use App\Models\Protocol;
use App\Models\ProtocolStep;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CINQ GESTES DIFFÉRENTS, UN SEUL VERROU DE ROUTE.
 *
 * Les routes de protocole — export, import, duplication, ajout d'étape,
 * suppression d'étape — étaient groupées derrière un unique `can:C`. Or chacune
 * de ces actions déclare son propre droit dans le contrôleur :
 *
 *   • export        → elevage.L   (c'est une lecture)
 *   • import        → elevage.C
 *   • duplication   → elevage.C
 *   • ajout d'étape → elevage.M   (on modifie un protocole existant)
 *   • suppr. étape  → elevage.S
 *
 * Le verrou de route contredisait donc le contrôleur sur TROIS d'entre elles,
 * et dans les deux sens :
 *
 *   • un rôle L+S — qui DÉTIENT le droit exigé par `destroyStep` — était refusé
 *     par la route faute de C. La personne autorisée à supprimer ne pouvait pas
 *     supprimer ;
 *   • un rôle L seul ne pouvait pas EXPORTER, alors qu'exporter est une lecture.
 *
 * Le groupe de routes juste en dessous énonce pourtant la règle mot pour mot :
 * « Verrou de route par verbe (défense en profondeur) : store = C, édition = M,
 * suppression = S ». Elle valait pour la ressource, pas pour ces cinq-là.
 *
 * ─── ET L'ÉCRAN ANNONÇAIT DES GARDES QU'IL NE POSAIT PAS ───
 *
 * `protocols/show.blade.php` ne contenait AUCUN `@can`, tout en portant les
 * commentaires « FORMULAIRE D'AJOUT D'ÉTAPE (C) » et « Permission S :
 * Suppression ». Le formulaire d'ajout et la corbeille de chaque étape étaient
 * donc offerts à quiconque pouvait ouvrir la page — et l'envoi retombait sur un
 * refus. Un bouton qui échoue toujours vaut moins que pas de bouton.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();

    $this->protocole = Protocol::create([
        'name' => 'Protocole test', 'type' => 'chair', 'farm_id' => $this->farm->id,
    ]);

    $this->etape = ProtocolStep::create([
        'protocol_id' => $this->protocole->id,
        'action_name' => 'Vaccin J1',
        'day_number'  => 1,
        'type'        => 'Vaccin',
    ]);
});

/**
 * Un compte dont le rôle porte EXACTEMENT ces droits sur tous les modules.
 *
 * `seedModuleMatrix` attend une LISTE de lettres — lui passer un tableau
 * associatif (`['L' => true]`) ne donne aucun droit, et tout refus observé
 * n'apprend alors rien du code.
 */
function compteAvecDroits(string $nom, array $lettres): User
{
    $role = Role::create([
        'name' => $nom, 'display_name' => ucfirst($nom), 'label' => ucfirst($nom),
        'icon' => '🔧', 'permissions' => $lettres,
    ]);

    // La matrice Modules × Rôles est la SEULE source de vérité des Gates.
    foreach (\App\Models\Module::pluck('id') as $moduleId) {
        \Illuminate\Support\Facades\DB::table('module_permissions')->updateOrInsert(
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

test('un rôle qui DÉTIENT le droit de supprimer peut supprimer une étape', function () {
    /*
     * LE défaut : la route exigeait C, que ce rôle n'a pas. L'agent qualité,
     * habilité à retirer une étape d'un protocole master, en était empêché.
     */
    $qualite = compteAvecDroits('qualite', ['L', 'S']);

    $this->actingAs($qualite)->delete(route('protocols.destroyStep', $this->etape->id));

    expect(ProtocolStep::withoutGlobalScopes()->whereKey($this->etape->id)->exists())->toBeFalse();
});

test('un rôle SANS le droit de supprimer ne le peut pas — non-régression', function () {
    /*
     * LA borne : on aligne le verrou, on ne l'ouvre pas. Un rôle qui crée et
     * modifie ne supprime pas pour autant.
     */
    $operateur = compteAvecDroits('operateur', ['L', 'C', 'M']);

    $this->actingAs($operateur)->delete(route('protocols.destroyStep', $this->etape->id));

    expect(ProtocolStep::withoutGlobalScopes()->whereKey($this->etape->id)->exists())->toBeTrue();
});

test('un rôle LECTEUR peut exporter un protocole', function () {
    /*
     * L'autre sens du même défaut : exporter est une LECTURE, et le contrôleur
     * ne demande que L. La route en exigeait C.
     */
    $lecteur = compteAvecDroits('observateur', ['L']);

    $this->actingAs($lecteur)
        ->get(route('protocols.export', $this->protocole->id))
        ->assertOk();
});

test('ajouter une étape demande le droit de MODIFIER', function () {
    /*
     * Le contrôleur exige M — on modifie un protocole existant. La route
     * demandait C, et le commentaire de la vue annonçait « (C) » : trois
     * endroits, deux réponses.
     */
    $lecteurCreateur = compteAvecDroits('creaseul', ['L', 'C']);

    $this->actingAs($lecteurCreateur)->post(route('protocols.addStep', $this->protocole->id), [
        'day_number'  => 7,
        'action_name' => 'Vitamine J7',
        'type'        => 'Vitamine',
    ]);

    expect(ProtocolStep::withoutGlobalScopes()->count())->toBe(1);

    $modificateur = compteAvecDroits('modificateur', ['L', 'C', 'M']);

    $this->actingAs($modificateur)->post(route('protocols.addStep', $this->protocole->id), [
        'day_number'  => 7,
        'action_name' => 'Vitamine J7',
        'type'        => 'Vitamine',
    ]);

    expect(ProtocolStep::withoutGlobalScopes()->count())->toBe(2);
});

test('l’écran n’offre pas la corbeille à qui ne peut pas supprimer', function () {
    /*
     * La vue ne portait aucun `@can` : la corbeille de chaque étape s'affichait
     * pour tout lecteur, et le clic retombait sur un refus.
     */
    $operateur = compteAvecDroits('operateur2', ['L', 'C', 'M']);

    $page = $this->actingAs($operateur)->get(route('protocols.show', $this->protocole->id));

    $page->assertOk()
        ->assertDontSee(route('protocols.destroyStep', $this->etape->id));
});

test('l’écran offre la corbeille à qui peut supprimer — non-régression', function () {
    // On cache un bouton inutilisable, on ne cache pas le bouton utile.
    $qualite = compteAvecDroits('qualite2', ['L', 'S']);

    $this->actingAs($qualite)
        ->get(route('protocols.show', $this->protocole->id))
        ->assertOk()
        ->assertSee(route('protocols.destroyStep', $this->etape->id), false);
});

test('l’administrateur garde tous les gestes — non-régression', function () {
    // Le cas de très loin le plus courant : rien ne doit changer pour lui.
    $this->actingAs($this->adminUser)
        ->get(route('protocols.show', $this->protocole->id))
        ->assertOk()
        ->assertSee(route('protocols.destroyStep', $this->etape->id), false);

    $this->actingAs($this->adminUser)
        ->delete(route('protocols.destroyStep', $this->etape->id));

    expect(ProtocolStep::withoutGlobalScopes()->count())->toBe(0);
});
