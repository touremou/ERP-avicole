<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

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

test('la règle est DÉCLARÉE une fois, et les quatre portes l’appellent', function () {
    /*
     * La garde qui empêche la dispersion de revenir. Elle a vécu un an dans un
     * seul contrôleur, en commentaire explicatif, pendant que trois autres
     * portes l'ignoraient.
     */
    $portes = [
        'app/Http/Controllers/Auth/PasswordController.php',
        'app/Http/Controllers/Auth/NewPasswordController.php',
        'app/Http/Controllers/Api/AuthController.php',
    ];

    foreach ($portes as $porte) {
        expect(str_contains(file_get_contents(base_path($porte)), 'revoquerLesAppareils'))
            ->toBeTrue("{$porte} ne révoque pas les appareils");
    }

    // La porte historique, elle, révoque déjà — par son propre appel.
    expect(str_contains(file_get_contents(base_path('app/Http/Controllers/UserController.php')), 'tokens()->delete()'))
        ->toBeTrue();
});
