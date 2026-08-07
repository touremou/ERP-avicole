<?php

use App\Models\CcpRecord;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CCP 1 — DEUX COMMANDES POUR UN SEUL FAIT, ET LE SERVEUR N'EN LISAIT QU'UNE.
 *
 * L'écran terrain du CCP 1 (réception du vif) offrait DEUX commandes
 * indépendantes :
 *
 *   • « Appréciation ante-mortem » → conforme / non conforme, envoyée dans
 *     `mesures.appreciation` ;
 *   • « Déclaration terrain » → le drapeau `conforme`, à « ✅ Conforme » par défaut.
 *
 * Or `RecordCcp::evaluate()` n'a AUCUN cas pour le CCP 1 : il retombe sur
 * `return $declared ?? true`. La conformité du relevé ne dépend donc que du
 * second drapeau ; `mesures.appreciation` n'est jamais relu.
 *
 * Conséquence à la réception du vif : l'opérateur touche la commande qui porte le
 * NOM du contrôle — « Appréciation ante-mortem → Non conforme » — et laisse la
 * déclaration générique sur son défaut. Le relevé est enregistré CONFORME :
 * aucune action corrective exigée, aucune alerte HACCP, aucun ordre bloqué. Et le
 * registre, qui est le document légal présenté en cas de contrôle, atteste des
 * animaux propres à l'abattage.
 *
 * LE WEB NE S'Y TROMPAIT PAS : HaccpRegisterController n'a qu'une commande
 * (`declared_conforme`) et DÉRIVE `appreciation` de celle-ci. Le terrain fait
 * désormais pareil — une seule commande, nommée par le contrôle qu'elle exprime.
 */

beforeEach(function () {
    $this->setUpRbac();
});

function ccpScreenSource(): string
{
    return file_get_contents(base_path('mobile/src/features/abattoir/CcpScreen.tsx'));
}

test('l’écran terrain n’a plus DEUX commandes pour la conformité du CCP 1', function () {
    $source = ccpScreenSource();

    // L'état en double a disparu…
    expect($source)->not->toContain("useState<'conforme' | 'non_conforme'>")
        // …et l'appréciation est DÉRIVÉE de la déclaration, comme sur le web.
        ->and($source)->toContain("appreciation: conforme ? 'conforme' : 'non_conforme'");
});

test('la commande unique porte le nom du contrôle à la réception du vif', function () {
    // Un libellé générique (« Déclaration terrain ») à côté d'un contrôle nommé
    // « Appréciation ante-mortem » est précisément ce qui a fait toucher la
    // mauvaise commande.
    expect(ccpScreenSource())
        ->toContain("ccp === 'ccp1_reception' ? t('Appréciation ante-mortem') : t('Déclaration terrain')");
});

test('le serveur juge le CCP 1 sur le drapeau déclaré — rien d’autre', function () {
    // On fixe le comportement que le correctif terrain suppose. Si `evaluate()`
    // se met un jour à lire `mesures.appreciation`, ce test le dira, et la
    // dérivation côté écran devra suivre.
    $action = app(\App\Actions\Slaughter\RecordCcp::class);

    expect($action->evaluate(CcpRecord::CCP1, ['appreciation' => 'non_conforme'], true))->toBeTrue()
        ->and($action->evaluate(CcpRecord::CCP1, ['appreciation' => 'conforme'], false))->toBeFalse();
});

test('un relevé CCP 1 non conforme EXIGE une action corrective', function () {
    // La conséquence qui compte : le refus d'enregistrer sans action corrective.
    // Avant, la non-conformité n'atteignait jamais cette porte.
    $this->actingAs($this->adminUser)
        ->post(route('slaughter.registres.ccp.store'), [
            'ccp'               => CcpRecord::CCP1,
            'declared_conforme' => 0,
            'corrective_action' => '',
        ])
        ->assertSessionHasErrors('corrective_action');
});

test('le web dérive l’appréciation de la déclaration — une seule source', function () {
    // Le modèle que le terrain imite désormais. S'il changeait, la parité serait
    // rompue en silence.
    expect(file_get_contents(app_path('Http/Controllers/HaccpRegisterController.php')))
        ->toContain("'appreciation' => \$declared ? 'conforme' : 'non_conforme'");
});
