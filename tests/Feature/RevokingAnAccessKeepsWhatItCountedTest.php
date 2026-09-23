<?php

use App\Models\CashRegisterSession;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use App\Models\User;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RÉVOQUER UN ACCÈS EFFAÇAIT CE QUE LA PERSONNE AVAIT COMPTÉ.
 *
 * `User` n'a PAS de SoftDeletes : `UserController::destroy` fait une
 * suppression PHYSIQUE, et les clés étrangères partent alors en cascade.
 *
 * `users` a cinq enfants en CASCADE. Quatre sont des réglages personnels —
 * affectation au site, préférences de notification, tableau de bord, abonnement
 * push — qui doivent bien s'en aller avec le compte. Le cinquième est
 * `cash_register_sessions` : LA CLÔTURE DE CAISSE.
 *
 * ─── MESURÉ ───
 *
 * Un caissier ouvre la caisse, la clôture sur un comptage de 60 000 GNF et un
 * ÉCART de 60 000. On révoque son accès :
 *
 *   • la session DISPARAÎT — comptage, théorique, écart, coupures relevées ;
 *   • l'écriture « clôture de caisse » de 60 000, elle, RESTE au grand-livre,
 *     désormais sans rien pour l'expliquer.
 *
 * C'est le registre anti-fraude. Le scénario qui fait mal s'écrit tout seul :
 * un caissier part — ou est remercié À CAUSE d'un écart — et le geste même qui
 * clôt son accès détruit la pièce qui l'établissait.
 *
 * ─── LE REMÈDE EXISTAIT DÉJÀ, ET LE DÉPÔT LE FORMULE DEUX FOIS ───
 *
 *   « Compte non supprimable : il porte des mouvements. Désactivez-le plutôt
 *     pour préserver l'historique. »          (compte de trésorerie)
 *   « Impossible de supprimer un article possédant un historique. »   (stock)
 *
 * `toggleActive` existe pour les comptes, révoque les jetons et conserve tout.
 * On y renvoie, comme `BatchController::destroy` renvoie vers la clôture.
 *
 * ─── POURQUOI PAS `DependencyGuard::blockers()` TEL QUEL ───
 *
 * Il compte TOUTE référence — c'est la bonne question pour un lot ou un
 * employé. Pour un compte, il rendrait tout utilisateur indéboulonnable : les
 * cent soixante-treize clés `SET NULL` de cette base (l'auteur d'un pointage,
 * d'une dépense) laissent l'enregistrement intact et n'ont rien à empêcher, et
 * les réglages personnels doivent partir.
 *
 * `cascadeBlockers()` pose la seule question qui vaille pour une suppression
 * physique : qu'est-ce que la cascade DÉTRUIRAIT ? Et l'inversion compte — on
 * énumère les tables sans importance, on dérive le reste du schéma. Une table
 * ajoutée demain et oubliée BLOQUERA la suppression, au lieu d'être détruite en
 * silence.
 */

beforeEach(function () {
    $this->setUpRbac();

    $this->compte = TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Caisse', 'type' => 'caisse',
        'opening_balance' => 0, 'current_balance' => 0, 'is_active' => true,
    ]);
});

/** Un caissier, avec les droits du décor. */
function caissierDeLaFerme(int $roleId): User
{
    return User::factory()->create(['role_id' => $roleId]);
}

/** Sa journée : ouverture, puis clôture sur ce comptage. */
function journeeDeCaisse(object $test, User $caissier, array $coupures): CashRegisterSession
{
    $test->actingAs($caissier);
    $test->post(route('cash-register.open'), ['opening_float' => 0]);

    $session = CashRegisterSession::open()->firstOrFail();
    $test->post(route('cash-register.close', $session), ['counts' => $coupures]);

    return $session->fresh();
}

test('révoquer l’accès d’un caissier est REFUSÉ', function () {
    /*
     * LE défaut : le geste passait, et emportait la caisse avec lui.
     */
    $caissier = caissierDeLaFerme($this->adminUser->role_id);
    journeeDeCaisse($this, $caissier, [20000 => 3]);

    $this->actingAs($this->adminUser);
    $this->delete(route('users.destroy', $caissier))->assertSessionHas('error');

    expect(User::whereKey($caissier->id)->exists())->toBeTrue();
});

test('et sa clôture de caisse survit, écart compris', function () {
    /*
     * L'enjeu : ce n'est pas le compte qu'on protège, c'est la PIÈCE. Un écart
     * de 60 000 GNF relevé un soir est le registre anti-fraude — et le geste
     * qui clôt l'accès du caissier le détruisait.
     */
    $caissier = caissierDeLaFerme($this->adminUser->role_id);
    $session  = journeeDeCaisse($this, $caissier, [20000 => 3]);

    expect((float) $session->difference)->toBe(60_000.0);

    $this->actingAs($this->adminUser);
    $this->delete(route('users.destroy', $caissier));

    $survivante = CashRegisterSession::find($session->id);

    expect($survivante)->not->toBeNull()
        ->and((float) $survivante->counted_cash)->toBe(60_000.0)
        ->and((float) $survivante->difference)->toBe(60_000.0)
        ->and($survivante->denominations)->toBe([20000 => 3]);
});

test('le refus DIT ce qui serait détruit, et le remède', function () {
    /*
     * Un refus qui n'explique pas se contourne ou se subit. Celui-ci nomme la
     * pièce en jeu et renvoie vers la désactivation, qui révoque l'accès tout
     * en conservant l'historique.
     */
    $caissier = caissierDeLaFerme($this->adminUser->role_id);
    journeeDeCaisse($this, $caissier, [20000 => 3]);

    $this->actingAs($this->adminUser);
    $this->delete(route('users.destroy', $caissier));

    expect(session('error'))->toContain('clôtures de caisse')
        ->and(session('error'))->toContain('Désactivez');
});

test('la DÉSACTIVATION reste possible et conserve tout', function () {
    /*
     * Le remède doit marcher, sinon le refus enferme l'administrateur. La
     * désactivation coupe l'accès — c'est ce que `SuspendingAnAccountCutsItsAccessTest`
     * éprouve — et ne touche pas à la caisse.
     */
    $caissier = caissierDeLaFerme($this->adminUser->role_id);
    $session  = journeeDeCaisse($this, $caissier, [20000 => 3]);

    $this->actingAs($this->adminUser);
    $this->patch(route('users.toggle_active', $caissier));

    expect($caissier->fresh()->is_active)->toBeFalse()
        ->and(CashRegisterSession::find($session->id))->not->toBeNull();
});

test('un compte SANS historique de caisse reste supprimable', function () {
    /*
     * LA borne, et elle est essentielle : la garde ne doit pas supprimer la
     * fonctionnalité qu'elle protège. Un accès créé par erreur, jamais utilisé
     * pour tenir la caisse, se révoque comme avant.
     */
    $sansCaisse = caissierDeLaFerme($this->adminUser->role_id);

    $this->actingAs($this->adminUser);
    $this->delete(route('users.destroy', $sansCaisse))->assertSessionHas('success');

    expect(User::whereKey($sansCaisse->id)->exists())->toBeFalse();
});

test('les réglages PERSONNELS ne bloquent pas la suppression', function () {
    /*
     * Affectation au site, préférences de notification : ils appartiennent au
     * compte et doivent partir avec lui. Les compter comme des obstacles
     * rendrait TOUT utilisateur indéboulonnable — la garde aurait remplacé un
     * défaut par une impasse.
     */
    $agent = caissierDeLaFerme($this->adminUser->role_id);

    \Illuminate\Support\Facades\DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $agent->id,
        'is_default' => true, 'is_owner' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->adminUser);
    $this->delete(route('users.destroy', $agent))->assertSessionHas('success');

    expect(User::whereKey($agent->id)->exists())->toBeFalse();
});

test('un agent qui a POINTÉ reste supprimable, et ses pointages survivent', function () {
    /*
     * LA borne du filtre CASCADE, et la raison d'être de `cascadeBlockers`.
     *
     * `employee_attendances.recorded_by` pointe vers `users` en SET NULL : la
     * ligne SURVIT à la suppression, son auteur devient simplement inconnu. Il
     * n'y a donc rien à protéger, et bloquer là-dessus rendrait indéboulonnable
     * quiconque a saisi un pointage — c'est-à-dire tout le monde.
     *
     * C'est précisément ce que `blockers()` ferait, et pourquoi on ne pouvait
     * pas le réutiliser tel quel.
     */
    $agent = caissierDeLaFerme($this->adminUser->role_id);

    $employe = \App\Models\Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'hire_date' => '2024-01-15', 'contract_end_date' => null,
    ]);

    $pointage = \App\Models\EmployeeAttendance::create([
        'farm_id' => $this->farm->id, 'employee_id' => $employe->id,
        'attendance_date' => now()->toDateString(), 'status' => 'present',
        'recorded_by' => $agent->id,
    ]);

    $this->actingAs($this->adminUser);
    $this->delete(route('users.destroy', $agent))->assertSessionHas('success');

    $survivant = \App\Models\EmployeeAttendance::find($pointage->id);

    expect(User::whereKey($agent->id)->exists())->toBeFalse()
        ->and($survivant)->not->toBeNull()              // le pointage reste
        ->and($survivant->recorded_by)->toBeNull()      // son auteur s'efface
        ->and($survivant->status)->toBe('present');
});

test('l’écriture de clôture garde donc son explication', function () {
    /*
     * De bout en bout : le grand-livre porte une écriture « clôture de caisse ».
     * Sans la session, elle devenait une somme sans justification — le défaut
     * d'écriture orpheline rencontré ailleurs dans cette campagne, ici par un
     * chemin tout différent.
     */
    $caissier = caissierDeLaFerme($this->adminUser->role_id);
    journeeDeCaisse($this, $caissier, [20000 => 3]);

    expect(TreasuryTransaction::where('category', 'cloture_caisse')->count())->toBe(1);

    $this->actingAs($this->adminUser);
    $this->delete(route('users.destroy', $caissier));

    $ecriture = TreasuryTransaction::where('category', 'cloture_caisse')->sole();

    expect($ecriture->reference)->toContain('CAISSE-')
        ->and(CashRegisterSession::count())->toBe(1);   // la pièce qu'elle désigne
});
