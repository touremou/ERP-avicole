<?php

use App\Models\Setting;
use App\Models\Stock;
use App\Models\Transformation;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DES GARDE-FOUS QUI N'ÉTAIENT BRANCHÉS À RIEN.
 *
 * Deux protections annoncées par le code, et inopérantes en pratique : la jauge
 * de rendement de l'atelier de transformation, et le recalcul anti-dérive du
 * solde de trésorerie.
 *
 * ── 1. LA JAUGE DE RENDEMENT DE TRANSFORMATION ──
 *
 * L'écran comparait le rendement à `abattoir.yield_smoking` puis à
 * `abattoir.yield_carcass` :
 *
 *   1. `setting()` est une fonction PHP, et l'appel était placé DANS une
 *      expression Alpine (`:class`), évaluée par le navigateur. Blade
 *      n'interpole pas là : le texte `setting('abattoir.yield_smoking', 65)`
 *      partait tel quel au navigateur, où `setting` n'existe pas. L'expression
 *      levait une erreur à chaque frappe et aucune couleur ne s'appliquait ;
 *   2. `abattoir.yield_carcass` n'a JAMAIS existé dans la table des réglages —
 *      seules `yield_cutting` et `yield_smoking` y ont été créées ;
 *   3. les seuils étaient inversés (65 testé avant 72) : la branche orange était
 *      inatteignable, et tout rendement au-dessus de 65 % passait au vert ;
 *   4. aucun des procédés proposés — fumage, grillage, marinade, autre — n'est
 *      une carcasse. Une marinade était jugée à l'aune du fumage.
 */

beforeEach(function () {
    $this->setUpRbac();

    Stock::create([
        'category' => Stock::CAT_PRODUITS_FINIS, 'item_name' => 'Poulet entier',
        'unit' => 'kg', 'current_quantity' => 50, 'unit_price' => 4000,
        'last_unit_price' => 4000, 'alert_threshold' => 5,
    ]);
});

test('chaque procédé a sa propre cible, lue au paramétrage', function () {
    Setting::set('abattoir.yield_smoking', 65);
    Setting::set('abattoir.yield_grille', 78);

    $targets = Transformation::yieldTargets();

    expect($targets['fume'])->toBe(65.0)
        ->and($targets['grille'])->toBe(78.0);
});

test('une cible non réglée vaut null, et non la cible d’un autre procédé', function () {
    // Le fond du défaut : la marinade était jugée à l'aune du fumage.
    Setting::set('abattoir.yield_smoking', 65);

    expect(Transformation::yieldTargets()['marine'])->toBeNull();
});

test('les procédés sont déclarés une seule fois', function () {
    // La liste vivait dans le formulaire, dans getTypeLabelAttribute() et dans
    // la règle de validation du contrôleur.
    expect(array_keys(Transformation::TYPES))->toBe(['fume', 'grille', 'marine', 'autre']);

    $controller = file_get_contents(app_path('Http/Controllers/SlaughterController.php'));
    expect($controller)->not->toContain('in:fume,grille,marine,autre');

    $view = file_get_contents(resource_path('views/slaughter/transform.blade.php'));
    expect($view)->not->toContain('<option value="fume">');
});

test('le libellé d’un procédé vient de la même déclaration', function () {
    $transformation = new Transformation(['transformation_type' => 'marine']);
    expect($transformation->type_label)->toBe('Mariné');

    // Un procédé hérité d'anciennes données reste lisible.
    expect((new Transformation(['transformation_type' => 'sechage']))->type_label)->toBe('Sechage');
});

test('l’écran n’appelle plus une fonction PHP depuis une expression Alpine', function () {
    $view = file_get_contents(resource_path('views/slaughter/transform.blade.php'));

    // Le motif exact qui cassait la jauge : setting(...) dans un :class.
    expect($view)->not->toContain(":class=\"yieldPct >= setting(")
        ->and($view)->not->toContain('yield_carcass');
});

test('la jauge reçoit les cibles et le procédé sélectionné', function () {
    Setting::set('abattoir.yield_smoking', 65);

    $this->actingAs($this->adminUser)->get(route('slaughter.transform.form'))
        ->assertOk()
        ->assertSee('yieldTargets', false)
        ->assertSee('targetYield', false)
        ->assertSee('"fume":65', false);
});

test('les cibles des autres procédés sont créées VIDES au paramétrage', function () {
    // Y mettre un chiffre inventé donnerait à une supposition l'autorité d'une
    // mesure : c'est à la ferme de les relever.
    foreach (['yield_grille', 'yield_marine', 'yield_autre'] as $key) {
        $row = DB::table('settings')->where('group', 'abattoir')->where('key', $key)->first();
        expect($row)->not->toBeNull("le réglage {$key} doit exister");
        expect($row->value)->toBe('');
    }
});

test('un procédé inconnu est refusé à l’enregistrement', function () {
    $this->actingAs($this->adminUser)
        ->post(route('slaughter.transform.store'), [
            'product_source' => 'Poulet entier', 'type' => 'lyophilisation',
            'input_kg' => 10, 'output_kg' => 7, 'production_date' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('type');
});

/*
 * MÊME FAMILLE, AUTRE MODULE : le garde-fou anti-dérive de la trésorerie.
 *
 * TreasuryAccount portait depuis l'origine un `recomputeBalance()` présenté en
 * commentaire comme la garantie que le solde « reste recalculable pour garantir
 * l'absence de dérive ». Aucune route, aucune commande, aucun bouton ne
 * l'appelait : seul son test le touchait. Une dérive — plantage en cours
 * d'écriture, correction faite directement en base — était donc définitive.
 */

test('le solde d’un compte peut être vérifié face au grand-livre', function () {
    $account = \App\Models\TreasuryAccount::create([
        'name' => 'Caisse test', 'type' => 'caisse',
        'opening_balance' => 100000, 'current_balance' => 100000, 'is_active' => true,
    ]);

    app(\App\Services\TreasuryService::class)->record($account, 'out', 25000, ['description' => 'Achat test']);
    expect((float) $account->fresh()->current_balance)->toBe(75000.0);

    // Dérive simulée : une correction faite directement en base.
    DB::table('treasury_accounts')->where('id', $account->id)->update(['current_balance' => 90000]);

    $this->actingAs($this->adminUser)
        ->post(route('treasury.verify-balance', $account))
        ->assertRedirect();

    expect((float) $account->fresh()->current_balance)->toBe(75000.0);
});

test('l’écart est ANNONCÉ, pas corrigé en silence', function () {
    $account = \App\Models\TreasuryAccount::create([
        'name' => 'Caisse test', 'type' => 'caisse',
        'opening_balance' => 50000, 'current_balance' => 80000, 'is_active' => true,
    ]);

    // Un solde réécrit sans un mot masque l'incident au lieu de le signaler.
    $this->actingAs($this->adminUser)
        ->post(route('treasury.verify-balance', $account))
        ->assertSessionHas('warning');

    // Et un compte conforme ne déclenche pas d'alerte inutile.
    $this->actingAs($this->adminUser)
        ->post(route('treasury.verify-balance', $account->fresh()))
        ->assertSessionHas('success');
});

test('un profil lecture seule ne peut pas réaligner un solde', function () {
    $account = \App\Models\TreasuryAccount::create([
        'name' => 'Caisse test', 'type' => 'caisse',
        'opening_balance' => 50000, 'current_balance' => 80000, 'is_active' => true,
    ]);

    $this->actingAs($this->readonlyUser)->post(route('treasury.verify-balance', $account));

    expect((float) $account->fresh()->current_balance)->toBe(80000.0);
});
