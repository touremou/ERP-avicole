<?php

use App\Models\Employee;
use App\Models\Module;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\Role;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'APPROBATION DE LA PAIE N'APPROUVAIT AUCUN MONTANT.
 *
 * `validatePeriod` exige `rh.S` — l'administrateur. Le contrôleur dit lui-même
 * pourquoi, dans `markPaid` :
 *
 *   « `validatePeriod` exige le droit `rh.S` (administrateur) : c'est le moment
 *     où quelqu'un approuve la paie AVANT QUE L'ARGENT SORTE. »
 *
 * Mais le verrou du bulletin, lui, ne connaît que le PAIEMENT
 * (`Payslip::isLocked()`) :
 *
 *   return $this->payment_status === 'paye' || $this->period?->status === 'paye';
 *
 * Les trois écrivains de lignes — `addLine`, `recordOvertime`, `removeLine` —
 * ne consultent que lui. Le statut `valide` n'interdisait donc RIEN.
 *
 * Mesuré : l'administrateur approuve une paie de 2 600 000 GNF ; un utilisateur
 * `rh.M` ajoute ensuite une prime de 1 000 000 et marque payé. 3 600 000 GNF
 * sortent de la caisse — `TreasuryPostingService::postPayslip` lit `net_salary`
 * en direct — pour une paie approuvée à 2 600 000. L'approbation `rh.S` portait
 * sur un montant que `rh.M` pouvait changer après coup.
 *
 * ─── ET « GÉNÉRER » DÉFAISAIT L'APPROBATION EN GARDANT SA SIGNATURE ───
 *
 * `generate()` ne refusait que « payé », et `PayrollService` réécrit le statut
 * sans condition : `$period->update(['status' => 'calcule'])`. Une période
 * validée y redescendait donc en « calculée » EN CONSERVANT `validated_by` et
 * `validated_at` — une période « calculée » portant la signature d'un
 * validateur. `validatePeriod` déclare pourtant, à quinze lignes de là : « on ne
 * ré-horodate JAMAIS une validation ». L'écran cache bien le bouton hors
 * brouillon, mais la route l'accepte : un retour arrière du navigateur ou un
 * second onglet suffit.
 *
 * ─── UN VERROU SANS OUVERTURE SERAIT UN PIÈGE ───
 *
 * Verrouiller sans rien d'autre enfermerait le bureau : une paie approuvée avec
 * une erreur deviendrait incorrigible, la seule sortie étant justement la porte
 * dérobée qu'on ferme. On rend donc la marche arrière EXPLICITE et de même rang
 * que l'approbation : `rh.S` rouvre la période, ce qui EFFACE la signature et
 * la ramène en « calculée ». C'est ce qui rend vraie la phrase déjà écrite : on
 * ne ré-horodate pas une validation — on la RETIRE, puis on en pose une neuve.
 */

beforeEach(function () {
    $this->setUpRbac();

    // Juin 2026 : 30 j − 4 dimanches = 26 jours ouvrés.
    $this->periode = PayrollPeriod::create([
        'farm_id' => $this->farm->id, 'label' => 'Juin 2026', 'year' => 2026, 'month' => 6,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'brouillon',
    ]);

    $this->agent = Employee::factory()->create([
        'status' => 'Actif', 'salary' => 2_600_000,
        'hire_date' => '2024-01-15', 'contract_end_date' => null,
    ]);

    (new PayrollService())->generatePayroll($this->periode);

    $this->bulletin = Payslip::where('payroll_period_id', $this->periode->id)
        ->where('employee_id', $this->agent->id)->firstOrFail();
});

/** Un compte porteur d'exactement ces droits sur le module RH. */
function compteRh(string $nom, array $lettres): User
{
    $role = Role::create([
        'name' => $nom, 'display_name' => ucfirst($nom), 'label' => ucfirst($nom),
        'icon' => '👤', 'permissions' => $lettres,
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

/** Passe la période en « validée », comme le fait l'administrateur. */
function approuverLaPeriode(PayrollPeriod $periode, User $administrateur): void
{
    $periode->update([
        'status'       => 'valide',
        'validated_by' => $administrateur->id,
        'validated_at' => now(),
    ]);
}

test('une prime ne s’ajoute plus après l’approbation', function () {
    /*
     * LE défaut : 1 000 000 GNF ajoutés à une paie déjà approuvée à 2 600 000.
     */
    approuverLaPeriode($this->periode, $this->adminUser);

    $responsable = compteRh('responsable_rh', ['L', 'C', 'M']);

    $this->actingAs($responsable)->post(route('payroll.add-line', $this->bulletin->id), [
        'type' => 'prime', 'label' => 'Prime exceptionnelle', 'amount' => 1_000_000,
    ]);

    expect((int) $this->bulletin->fresh()->net_salary)->toBe(2_600_000)
        ->and(PayslipLine::where('payslip_id', $this->bulletin->id)->count())->toBe(0);
});

test('les heures supplémentaires non plus', function () {
    // Le deuxième écrivain, qui lisait le même verrou incomplet.
    approuverLaPeriode($this->periode, $this->adminUser);

    $responsable = compteRh('responsable_rh2', ['L', 'C', 'M']);

    $this->actingAs($responsable)->post(route('payroll.overtime', $this->bulletin->id), ['hours' => 20]);

    expect((int) $this->bulletin->fresh()->net_salary)->toBe(2_600_000)
        ->and((float) $this->bulletin->fresh()->overtime_hours)->toBe(0.0);
});

test('et une ligne approuvée ne se supprime plus', function () {
    /*
     * Le troisième écrivain. Retirer une déduction après approbation augmente le
     * net tout autant qu'ajouter une prime.
     */
    $deduction = PayslipLine::create([
        'payslip_id' => $this->bulletin->id, 'type' => 'deduction',
        'label' => 'Avance sur salaire', 'amount' => 400_000, 'category' => 'avance',
    ]);
    $this->bulletin->recalculate();

    expect((int) $this->bulletin->fresh()->net_salary)->toBe(2_200_000);

    approuverLaPeriode($this->periode, $this->adminUser);

    $responsable = compteRh('responsable_rh3', ['L', 'C', 'M']);

    $this->actingAs($responsable)->delete(route('payroll.remove-line', $deduction->id));

    expect((int) $this->bulletin->fresh()->net_salary)->toBe(2_200_000)
        ->and(PayslipLine::whereKey($deduction->id)->exists())->toBeTrue();
});

test('« générer » ne défait plus une approbation en gardant sa signature', function () {
    /*
     * LE second défaut : la période redescendait en « calculée » avec le
     * `validated_by` et le `validated_at` de l'administrateur encore posés.
     */
    approuverLaPeriode($this->periode, $this->adminUser);

    $responsable = compteRh('responsable_rh4', ['L', 'C', 'M']);

    /*
     * `confirm_no_attendance` est indispensable ici, et pas un artifice : sans
     * lui, `generate` s'arrête sur son BLOCAGE DOUX (« aucun pointage sur la
     * période ») et ne va jamais jusqu'au statut. Le test passerait alors au
     * vert sans rien mesurer du défaut — c'est ce qu'il faisait au premier jet.
     * Le bureau, lui, confirme et passe : c'est ce geste-là qu'on reproduit.
     */
    $this->actingAs($responsable)->post(route('payroll.generate', $this->periode->id), [
        'confirm_no_attendance' => 1,
    ]);

    $apres = $this->periode->fresh();

    expect($apres->status)->toBe('valide')
        ->and($apres->validated_by)->toBe($this->adminUser->id)
        ->and($apres->validated_at)->not->toBeNull();
});

test('rouvrir une période RETIRE la signature et rend la main', function () {
    /*
     * La marche arrière explicite, de même rang que l'approbation : c'est elle
     * qui empêche le verrou d'être un piège, et qui rend vraie la phrase déjà
     * écrite dans `validatePeriod` — on ne ré-horodate pas une validation, on la
     * retire.
     */
    approuverLaPeriode($this->periode, $this->adminUser);

    $this->actingAs($this->adminUser)->post(route('payroll.reopen', $this->periode->id));

    $apres = $this->periode->fresh();

    expect($apres->status)->toBe('calcule')
        ->and($apres->validated_by)->toBeNull()
        ->and($apres->validated_at)->toBeNull();

    // Et la correction redevient possible.
    $this->actingAs($this->adminUser)->post(route('payroll.add-line', $this->bulletin->id), [
        'type' => 'prime', 'label' => 'Correction', 'amount' => 50_000,
    ]);

    expect((int) $this->bulletin->fresh()->net_salary)->toBe(2_650_000);
});

test('rouvrir est du rang de l’approbation, pas au-dessous', function () {
    /*
     * LA borne : si `rh.M` pouvait rouvrir, il pourrait retirer l'approbation
     * puis modifier — et le verrou ne vaudrait rien de plus qu'avant.
     *
     * NOTE DE MÉTHODE, pour qui relira. Cette règle est tenue par DEUX couches
     * indépendantes : le verrou de route (`can:S`) et le `Gate::denies('rh.S')`
     * du contrôleur. Descendre l'une OU l'autre en `M` laisse ce test au vert —
     * l'autre suffit. Ce n'est pas que le test soit faible : les deux ensemble
     * descendues le font tomber. C'est la défense en profondeur que ce dépôt
     * pratique ailleurs, et le garde du contrôleur n'est donc pas du code mort.
     */
    approuverLaPeriode($this->periode, $this->adminUser);

    $responsable = compteRh('responsable_rh5', ['L', 'C', 'M']);

    $this->actingAs($responsable)->post(route('payroll.reopen', $this->periode->id));

    expect($this->periode->fresh()->status)->toBe('valide');
});

test('avant l’approbation, tout reste modifiable — non-régression', function () {
    /*
     * On ne resserre rien en amont : une période calculée se corrige librement,
     * c'est précisément à cela qu'elle sert.
     */
    expect($this->periode->fresh()->status)->toBe('calcule');

    $responsable = compteRh('responsable_rh6', ['L', 'C', 'M']);

    $this->actingAs($responsable)->post(route('payroll.add-line', $this->bulletin->id), [
        'type' => 'prime', 'label' => 'Prime de rendement', 'amount' => 300_000,
    ]);

    expect((int) $this->bulletin->fresh()->net_salary)->toBe(2_900_000);
});

test('une période PAYÉE reste verrouillée — non-régression', function () {
    // Le verrou qui existait déjà, et qu'on ne touche pas.
    $this->periode->update(['status' => 'paye']);

    $responsable = compteRh('responsable_rh7', ['L', 'C', 'M']);

    $this->actingAs($responsable)->post(route('payroll.add-line', $this->bulletin->id), [
        'type' => 'prime', 'label' => 'Trop tard', 'amount' => 100_000,
    ]);

    expect((int) $this->bulletin->fresh()->net_salary)->toBe(2_600_000);
});

test('l’écran n’offre pas « Payer » avant l’approbation', function () {
    /*
     * Le même désaccord, sur le même écran : `markPaid` exige ['valide','paye']
     * — « la validation par un administrateur précède le paiement » — et le
     * bouton s'offrait dès « calculé ». Il retombait donc toujours sur ce refus.
     */
    expect($this->periode->fresh()->status)->toBe('calcule');

    $this->actingAs($this->adminUser)
        ->get(route('payroll.show', $this->periode->id))
        ->assertOk()
        ->assertDontSee('openPayModal(' . $this->bulletin->id, false);

    // Et la porte le refusait bien : c'est ce que le bouton cachait.
    $this->actingAs($this->adminUser)->post(route('payroll.mark-paid', $this->bulletin->id), [
        'payment_method' => 'especes',
    ]);

    expect($this->bulletin->fresh()->payment_status)->not->toBe('paye');
});

test('l’écran offre « Payer » une fois la période approuvée — non-régression', function () {
    // On cache un bouton inutilisable, on ne cache pas le bouton utile.
    approuverLaPeriode($this->periode, $this->adminUser);

    $this->actingAs($this->adminUser)
        ->get(route('payroll.show', $this->periode->id))
        ->assertOk()
        ->assertSee('openPayModal(' . $this->bulletin->id, false);
});

test('l’écran offre la réouverture à l’administrateur', function () {
    // Le verrou posé doit avoir sa porte de sortie, visible.
    approuverLaPeriode($this->periode, $this->adminUser);

    $this->actingAs($this->adminUser)
        ->get(route('payroll.show', $this->periode->id))
        ->assertOk()
        ->assertSee(route('payroll.reopen', $this->periode->id), false);
});

test('une période payée ne se rouvre pas — non-régression', function () {
    /*
     * L'argent est sorti : rouvrir n'aurait plus de sens, et effacerait la
     * signature d'une paie déjà réglée.
     */
    approuverLaPeriode($this->periode, $this->adminUser);
    $this->periode->update(['status' => 'paye']);

    $this->actingAs($this->adminUser)->post(route('payroll.reopen', $this->periode->id));

    expect($this->periode->fresh()->status)->toBe('paye');
});
