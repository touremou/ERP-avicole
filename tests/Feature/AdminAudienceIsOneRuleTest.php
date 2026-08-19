<?php

use App\Models\Batch;
use App\Models\Role;
use App\Models\User;
use App\Services\CumulativeMortalityAlert;
use App\Support\Administrators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * TROIS ALERTES, TROIS FAÇONS DE TROUVER LES ADMINISTRATEURS.
 *
 * La même question — « à qui adresser une alerte de service ? » — recevait trois
 * réponses différentes :
 *
 *   • CheckBackupHealth        → rôle nommé, REPLI par role_id, comptes ACTIFS ;
 *   • ErrorAlertService        → rôle nommé, REPLI par role_id, tous les comptes ;
 *   • CumulativeMortalityAlert → rôle nommé SEULEMENT.
 *
 * Le troisième est celui qui prévient d'une SURMORTALITÉ — l'alerte la plus
 * critique d'un élevage, celle qui dit qu'une bande est en train de mourir.
 *
 * ─── CE QUE J'AI CRU, ET CE QUE LE TEST A DIT ───
 *
 * Premier jet de ce fichier : « sur une base dont les rôles ne portent pas le nom
 * admin, l'alerte de surmortalité ne trouve personne, là où le repli des deux
 * autres les sauve ». C'ÉTAIT FAUX, et ce test l'a montré aussitôt — le repli
 * cherche `Role::where('name', 'admin')`, donc il dépend LUI AUSSI du nom. Il ne
 * couvre pas un rôle renommé, mais une relation `userRole` défaillante.
 *
 * La divergence réelle est plus étroite, et c'est celle-ci qu'on corrige.
 *
 * ─── LA DIVERGENCE PROUVABLE : LE COMPTE DÉSACTIVÉ ───
 *
 * Un administrateur désactivé — quelqu'un qui a quitté l'exploitation — ne
 * recevait PAS l'alerte de sauvegarde, mais recevait encore les erreurs serveur
 * et les alertes de surmortalité. Trois déclarations, deux comportements.
 *
 * Un compte désactivé est un compte dont on ne veut plus : lui adresser une
 * alerte, c'est l'envoyer à quelqu'un qui est parti — et croire l'exploitation
 * prévenue. La règle de la surveillance des sauvegardes devient celle de tout le
 * monde. C'est le seul changement de comportement de cette correction.
 *
 * ─── ET LA REDONDANCE, ALIGNÉE AU PASSAGE ───
 *
 * Le repli par `role_id` protège d'une relation `userRole` défaillante — le
 * commentaire de CumulativeMortalityAlert garde d'ailleurs la trace d'une panne
 * de ce genre (`role` → `userRole`, exception avalée par un catch), qui avait
 * rendu l'alerte muette. Deux appelants sur trois s'en étaient prémunis ; le
 * troisième l'a maintenant aussi, sans avoir eu à le savoir.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();

    $this->lot = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'building_id'      => $this->building->id,
        'initial_quantity' => 1000,
        'current_quantity' => 800,
    ]);
});

test('un compte DÉSACTIVÉ ne reçoit plus les alertes de service', function () {
    /*
     * LE défaut prouvable : deux appelants sur trois adressaient encore leurs
     * alertes aux comptes désactivés — c'est-à-dire à des gens partis, en
     * croyant l'exploitation prévenue. La surveillance des sauvegardes, elle,
     * les écartait déjà.
     */
    $this->adminUser->update(['is_active' => false]);

    expect(Administrators::all()->pluck('id'))->not->toContain($this->adminUser->id);
});

test('le rôle nommé reste prioritaire sur le repli', function () {
    // Le cas courant, inchangé.
    expect(Administrators::all()->pluck('id'))->toContain($this->adminUser->id);
});

test('sans aucun rôle admin, la liste est VIDE et non une erreur', function () {
    /*
     * L'appelant doit pouvoir dire « aucun administrateur à prévenir » plutôt que
     * d'éclater — c'est ce que fait la surveillance des sauvegardes, qui le
     * signale explicitement.
     */
    Role::where('name', 'admin')->update(['name' => 'autre_chose']);
    User::query()->update(['role_id' => Role::where('name', 'operator')->value('id')]);

    expect(Administrators::all())->toBeEmpty();
});

test('les TROIS appelants tirent de la même source', function () {
    /*
     * La garde qui empêche la divergence de revenir : aucun des trois ne
     * reconstruit la requête chez lui.
     */
    $fichiers = [
        'app/Console/Commands/CheckBackupHealth.php',
        'app/Services/ErrorAlertService.php',
        'app/Services/CumulativeMortalityAlert.php',
    ];

    foreach ($fichiers as $fichier) {
        $code = file_get_contents(base_path($fichier));

        // `toContain` prend des MOTIFS, pas un message d'échec : le nom du
        // fichier est donc porté par une assertion distincte.
        expect(str_contains($code, 'Administrators::all()'))
            ->toBeTrue("Résolution recopiée dans {$fichier}")
            ->and(str_contains($code, "where('name', 'admin')"))
            ->toBeFalse("Requête d'admins reconstruite dans {$fichier}");
    }
});

test('la surmortalité alerte réellement, repli compris', function () {
    /*
     * De bout en bout : c'est le geste qui compte, pas la liste.
     */
    Notification::fake();

    app(CumulativeMortalityAlert::class)->evaluate($this->lot->fresh(), 1000);

    Notification::assertSentTo(
        Administrators::all(),
        \App\Notifications\AlertNotification::class,
    );
});
