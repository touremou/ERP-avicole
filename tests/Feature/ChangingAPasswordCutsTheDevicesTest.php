<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, Tests\Helpers\AviSmartTestHelper::class);

/*
 * CHANGER SON MOT DE PASSE NE COUPAIT AUCUN APPAREIL.
 *
 * Un jeton Sanctum ne dépend PAS du mot de passe : il survit à son changement.
 * `UserController::resetPassword` le dit déjà, mot pour mot :
 *
 *   « Un jeton ne dépend PAS du mot de passe : sans cette ligne, changer le mot
 *     de passe d'un compte compromis laissait l'appareil de l'intrus connecté et
 *     écrivant. C'est pourtant la raison même pour laquelle on réinitialise. »
 *
 * Et il révoque. Mais cette règle n'était appliquée QU'À CETTE PORTE-LÀ — celle
 * où un ADMINISTRATEUR agit pour quelqu'un d'autre. Les trois portes où
 * l'utilisateur change SON PROPRE mot de passe ne révoquaient rien :
 *
 *   • le profil web ;
 *   • le lien de réinitialisation par e-mail ;
 *   • l'application mobile.
 *
 * La deuxième est la pire : c'est le parcours « j'ai été piraté ». La personne
 * fait exactement ce qu'il faut, et le téléphone de l'intrus continue d'écrire.
 *
 * ─── COMMENT ON MESURE, ET POURQUOI PAS AUTREMENT ───
 *
 * On compte les JETONS EN BASE. Une première version de ce test interrogeait
 * `/api/v1/auth/me` avec le jeton en en-tête : c'était faux. Le garde `sanctum`
 * accepte d'abord la SESSION quand il y en a une, et `actingAs()` en ouvre une.
 * Le test mesurait donc la session, pas le jeton, et rendait « encore valide »
 * pour un jeton déjà supprimé.
 *
 * ─── CE QUE CE CORRECTIF NE FAIT PAS ───
 *
 * Les sessions de NAVIGATEUR ouvertes ailleurs ne sont pas coupées : cela
 * demanderait le middleware `AuthenticateSession`, absent du groupe web, et son
 * activation déconnecte les utilisateurs de façon visible. C'est une décision
 * d'exploitation, pas une correction à glisser dans un audit.
 */

/** Un compte et ses deux appareils appairés. */
function compteAvecDeuxAppareils(): array
{
    $user = User::factory()->create(['password' => Hash::make('ancien-mdp')]);

    return [$user, $user->createToken('telephone')->plainTextToken, $user->createToken('tablette')];
}

function jetonsRestants(User $user): int
{
    return DB::table('personal_access_tokens')
        ->where('tokenable_id', $user->id)
        ->where('tokenable_type', $user::class)
        ->count();
}

test('profil web : changer son mot de passe coupe les appareils', function () {
    /*
     * LE défaut, porte n°1.
     */
    [$user] = compteAvecDeuxAppareils();

    expect(jetonsRestants($user))->toBe(2);

    $this->actingAs($user)->put('/password', [
        'current_password'      => 'ancien-mdp',
        'password'              => 'NouveauMdp123',
        'password_confirmation' => 'NouveauMdp123',
    ]);

    expect(Hash::check('NouveauMdp123', $user->fresh()->password))->toBeTrue()
        ->and(jetonsRestants($user))->toBe(0);
});

test('lien e-mail : le parcours « j’ai été piraté » les coupe aussi', function () {
    /*
     * LA porte qui compte le plus. `remember_token` ne coupe que les cookies
     * « se souvenir de moi » — jamais les appareils appairés.
     */
    [$user] = compteAvecDeuxAppareils();

    $this->post('/reset-password', [
        'token'                 => Password::createToken($user),
        'email'                 => $user->email,
        'password'              => 'NouveauMdp123',
        'password_confirmation' => 'NouveauMdp123',
    ]);

    expect(Hash::check('NouveauMdp123', $user->fresh()->password))->toBeTrue()
        ->and(jetonsRestants($user))->toBe(0);
});

test('mobile : les AUTRES appareils tombent, celui qui demande reste', function () {
    /*
     * La borne du remède, et elle est délibérée : déconnecter l'appareil au
     * moment même où il obéit serait hostile, et sans gain — c'est le seul dont
     * on sache qu'il a le nouveau mot de passe.
     */
    [$user, $telephone] = compteAvecDeuxAppareils();

    $this->withHeader('Authorization', "Bearer {$telephone}")
        ->patchJson('/api/v1/auth/password', [
            'current_password'      => 'ancien-mdp',
            'password'              => 'NouveauMdp123',
            'password_confirmation' => 'NouveauMdp123',
        ])->assertOk();

    expect(Hash::check('NouveauMdp123', $user->fresh()->password))->toBeTrue()
        ->and(jetonsRestants($user))->toBe(1);
});

test('et le jeton conservé est bien CELUI qui a demandé', function () {
    // Sans cette précision, garder « un » jeton laisserait passer l'inverse
    // exact du remède : couper le demandeur et laisser vivre l'intrus.
    [$user, $telephone, $tablette] = compteAvecDeuxAppareils();

    $this->withHeader('Authorization', "Bearer {$telephone}")
        ->patchJson('/api/v1/auth/password', [
            'current_password'      => 'ancien-mdp',
            'password'              => 'NouveauMdp123',
            'password_confirmation' => 'NouveauMdp123',
        ])->assertOk();

    expect(DB::table('personal_access_tokens')->where('id', $tablette->accessToken->id)->exists())
        ->toBeFalse();
});

test('un mot de passe REFUSÉ ne coupe rien — la borne', function () {
    /*
     * LA borne : une saisie erronée de l'ancien mot de passe ne doit pas
     * déconnecter les appareils. Sinon la garde deviendrait une arme : il
     * suffirait de se tromper pour mettre quelqu'un dehors.
     */
    [$user] = compteAvecDeuxAppareils();

    $this->actingAs($user)->put('/password', [
        'current_password'      => 'mauvais-mdp',
        'password'              => 'NouveauMdp123',
        'password_confirmation' => 'NouveauMdp123',
    ]);

    expect(Hash::check('ancien-mdp', $user->fresh()->password))->toBeTrue()
        ->and(jetonsRestants($user))->toBe(2);
});

test('changer son adresse depuis le MOBILE la dé-vérifie, comme sur le web', function () {
    /*
     * La porte voisine, et le même écart : `ProfileController::update` remet
     * `email_verified_at` à null quand l'adresse change ; l'API ne le faisait
     * pas. Une adresse saisie depuis le téléphone restait marquée « vérifiée »
     * alors que personne ne l'avait vérifiée.
     *
     * Sans conséquence aujourd'hui — le middleware `verified` du groupe
     * tableau de bord est INERTE, `User` n'implémentant pas `MustVerifyEmail`
     * — mais c'est l'écart qui mordrait le jour où la vérification serait
     * activée : les comptes passés par le mobile seraient les seuls jamais
     * contrôlés. Activer la vérification reste une décision d'exploitation.
     */
    $user = User::factory()->create([
        'password' => Hash::make('ancien-mdp'),
        'email_verified_at' => now(),
    ]);

    $jeton = $user->createToken('telephone')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$jeton}")
        ->patchJson('/api/v1/auth/profile', [
            'name'  => $user->name,
            'email' => 'nouvelle@adresse.test',
        ])->assertOk();

    expect($user->fresh()->email)->toBe('nouvelle@adresse.test')
        ->and($user->fresh()->email_verified_at)->toBeNull();
});

test('garder la MÊME adresse ne la dé-vérifie pas — la borne', function () {
    // Corriger son nom ou sa langue ne doit pas invalider une adresse dont
    // personne n'a changé un caractère.
    $user = User::factory()->create([
        'password' => Hash::make('ancien-mdp'),
        'email_verified_at' => now(),
    ]);

    $jeton = $user->createToken('telephone')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$jeton}")
        ->patchJson('/api/v1/auth/profile', [
            'name'  => 'Nom Corrigé',
            'email' => $user->email,
        ])->assertOk();

    expect($user->fresh()->name)->toBe('Nom Corrigé')
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
});

/** Un employé et le compte qui lui est lié, avec ses deux appareils. */
function employeAvecSonCompte(int $fermeId): array
{
    [$compte] = compteAvecDeuxAppareils();

    $employe = \App\Models\Employee::factory()->create([
        'farm_id' => $fermeId, 'user_id' => $compte->id, 'status' => 'Actif',
    ]);

    return [$employe, $compte];
}

test('espace RH : réinitialiser le mot de passe d’un employé coupe ses appareils', function () {
    /*
     * Jumeau exact de `UserController::resetPassword` — même geste, autre
     * écran —, et le seul des deux qui ne révoquait pas. La garde par liste
     * manuelle ne le connaissait pas.
     */
    $this->setUpRbac();
    [$employe, $compte] = employeAvecSonCompte($this->farm->id);

    $this->actingAs($this->adminUser)
        ->put(route('employees.access.password', $employe))
        ->assertSessionHas('temp_password');

    expect(jetonsRestants($compte))->toBe(0);
});

test('espace RH : couper l’accès coupe les appareils — et ils ne REVIVENT pas', function () {
    /*
     * `EnsureAccountIsActive` refusait bien les jetons tant que le compte était
     * coupé. Mais ils n'étaient pas supprimés : RÉACTIVER le compte les rendait
     * valides, celui d'un téléphone perdu compris. C'est précisément le cas que
     * `UserController::toggleActive` écarte — « un jeton révoqué l'est pour de
     * bon, et l'appareil se ré-appaire ».
     */
    $this->setUpRbac();
    [$employe, $compte] = employeAvecSonCompte($this->farm->id);
    $this->actingAs($this->adminUser);

    $this->put(route('employees.access.update', $employe), ['action' => 'deactivate']);
    expect($compte->fresh()->is_active)->toBeFalse()
        ->and(jetonsRestants($compte))->toBe(0);

    $this->put(route('employees.access.update', $employe), ['action' => 'activate']);
    expect($compte->fresh()->is_active)->toBeTrue()
        ->and(jetonsRestants($compte))->toBe(0);   // rien n'est revenu
});

test('espace RH : changer le RÔLE ne coupe rien — la borne', function () {
    /*
     * LA borne. Un changement de rôle n'est pas une mesure de sécurité contre
     * l'appareil : les droits sont relus à chaque requête (et leur cache est
     * vidé, ligne `Cache::forget`). Déconnecter l'employé pour une promotion
     * serait une gêne sans gain.
     */
    $this->setUpRbac();
    [$employe, $compte] = employeAvecSonCompte($this->farm->id);

    $this->actingAs($this->adminUser)
        ->put(route('employees.access.update', $employe), [
            'action' => 'role', 'role_id' => $this->operatorUser->role_id,
        ]);

    expect(jetonsRestants($compte))->toBe(2);
});

test('TOUTE réécriture d’un mot de passe existant coupe les appareils — trouvée dans le code, pas listée', function () {
    /*
     * La garde qui empêche la dispersion de revenir — et elle a déjà échoué une
     * fois sous sa forme précédente.
     *
     * Elle énumérait les portes À LA MAIN : profil web, lien e-mail, mobile, et
     * l'écran des utilisateurs. Elle en a manqué DEUX, que l'audit du lendemain
     * a trouvées dans `EmployeeAccessController` — la réinitialisation depuis
     * l'espace RH, jumelle exacte de celle de l'écran des utilisateurs. Une liste
     * tenue à la main vieillit en silence : c'est le défaut même qu'elle était
     * censée surveiller.
     *
     * Elle contrôlait aussi le FICHIER, pas la méthode : un fichier qui révoque
     * dans une méthode passait, quelle que soit l'autre.
     *
     * Celle-ci parcourt le code par réflexion, méthode par méthode, et exige
     * une révocation partout où un mot de passe EXISTANT est réécrit
     * (`->update([... 'password'` ou `->forceFill([... 'password'`). Les
     * CRÉATIONS de compte (`::create`) sont hors champ : un compte neuf n'a
     * encore aucun appareil.
     */
    // On s'arrête à `])` — la FERMETURE de l'appel —, pas au premier `]`.
    // La première version s'arrêtait au premier `]` : elle ne voyait donc pas
    // `storeAdmin`, dont le tableau commence par `$data['admin_name']`. C'est
    // le compteur ci-dessous qui l'a trahie — cinq portes au lieu de six.
    $reecriture = "/(?:->update|->forceFill)\\(\\s*\\[(?:(?!\\]\\s*\\)).){0,600}?'password'\\s*=>/s";

    $examinees = [];
    $fautives  = [];

    $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($fichiers as $fichier) {
        if ($fichier->getExtension() !== 'php') {
            continue;
        }

        $classe = 'App\\' . str_replace(['/', '.php'], ['\\', ''],
            substr($fichier->getPathname(), strlen(app_path()) + 1));

        if (! class_exists($classe)) {
            continue;
        }

        foreach ((new ReflectionClass($classe))->getMethods() as $methode) {
            if ($methode->getFileName() !== $fichier->getPathname()) {
                continue;   // méthode héritée : examinée chez son parent
            }

            $lignes = file($methode->getFileName());
            $corps  = implode('', array_slice($lignes, $methode->getStartLine() - 1,
                $methode->getEndLine() - $methode->getStartLine() + 1));

            if (! preg_match($reecriture, $corps)) {
                continue;
            }

            $nom = class_basename($classe) . '::' . $methode->getName();
            $examinees[] = $nom;

            if (! str_contains($corps, 'revoquerLesAppareils') && ! str_contains($corps, 'tokens()->delete()')) {
                $fautives[] = $nom;
            }
        }
    }

    // Garde-fou du garde-fou : sans portes trouvées, la garde passerait en
    // n'ayant rien contrôlé. Il y en a au moins six aujourd'hui.
    expect(count($examinees))->toBeGreaterThanOrEqual(6);

    expect($fautives)->toBe([], 'réécrit un mot de passe sans couper les appareils : ' . implode(', ', $fautives));
});
