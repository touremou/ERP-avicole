<?php

use App\Actions\Slaughter\RecordCcp;
use App\Models\CcpRecord;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'ÉCRAN ANNONÇAIT UN SEUIL QUE LE MOTEUR N'APPLIQUAIT PAS.
 *
 * `RecordCcp::seuil()` lit le réglage par `Setting::rawValue()` — seule façon de
 * distinguer « pas de seuil » de « seuil à zéro ». Faute de seuil, il ne
 * condamne pas : l'appréciation de l'opérateur fait foi. C'est délibéré et
 * documenté (un champ vidé par mégarde bloquait toute la production).
 *
 * Mais l'ÉCRAN de saisie, lui, lit `setting('abattoir.ccp3_core_temp_max', 4)`.
 * Or `setting()` rend son défaut quand la valeur est vide — c'est le correctif
 * qui rendait leur promesse à deux cents appels.
 *
 * Les deux ne disent donc pas la même chose quand le réglage est effacé :
 *
 *   • le formulaire d'abattage affiche « ≤ 4 °C » ;
 *   • le moteur, lui, n'applique AUCUN seuil ;
 *   • et le registre CCP, qui n'a pas de défaut, affiche une case vide.
 *
 * Trois écrans, trois réponses, pour un point critique de maîtrise sanitaire.
 * Le 4 affiché n'est pas seulement inexact : il rassure. Un opérateur lisant
 * « ≤ 4 °C » croit la limite tenue par l'application, alors qu'à ce moment-là
 * rien ne la tient que son propre jugement.
 *
 * ─── CE QU'ON NE CORRIGE PAS ───
 *
 * Le repli du moteur reste tel quel : sans seuil, on ne condamne pas. Inventer
 * ici une valeur de secours en ferait une seconde déclaration — exactement ce
 * qu'on reproche à l'écran. Ce qui change, c'est que les écrans DISENT ce que
 * le moteur fait.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
});

/** Efface le réglage comme le fait l'écran des Réglages : valeur vide. */
function effacerSeuil(string $groupe, string $clef): void
{
    Setting::where('group', $groupe)->where('key', $clef)->update(['value' => '']);
    Setting::clearCache();
}

/** Fixe le réglage à une valeur donnée. */
function poserSeuil(string $groupe, string $clef, string $valeur): void
{
    Setting::where('group', $groupe)->where('key', $clef)->update(['value' => $valeur]);
    Setting::clearCache();
}

/** Un ordre d'abattage planifié, prêt pour l'écran d'exécution. */
function ordreAbattage(int $batchId, int $demandeurId): \App\Models\SlaughterOrder
{
    return \App\Models\SlaughterOrder::create([
        'order_number'     => \App\Models\SlaughterOrder::generateNumber(),
        'batch_id'         => $batchId,
        'planned_date'     => now()->toDateString(),
        'planned_quantity' => 60,
        'status'           => 'planifie',
        'requested_by'     => $demandeurId,
    ]);
}

test('sans seuil, le moteur ne condamne pas — et c’est voulu', function () {
    /*
     * La borne à ne pas franchir : un champ vidé par mégarde ne doit pas
     * déclarer non conforme toute carcasse et bloquer la production.
     */
    effacerSeuil('abattoir', 'ccp3_core_temp_max');

    $conforme = app(RecordCcp::class)->evaluate(
        CcpRecord::CCP3,
        ['temperature_coeur' => 25.0],   // très au-dessus de tout seuil plausible
        null,
    );

    expect($conforme)->toBeTrue('Sans seuil, l’appréciation déclarée fait foi.');
});

test('un seuil RENSEIGNÉ est bien appliqué', function () {
    // L'autre borne : le repli ne doit pas avaler la règle quand elle existe.
    poserSeuil('abattoir', 'ccp3_core_temp_max', '4');

    expect(app(RecordCcp::class)->evaluate(CcpRecord::CCP3, ['temperature_coeur' => 25.0], null))
        ->toBeFalse()
        ->and(app(RecordCcp::class)->evaluate(CcpRecord::CCP3, ['temperature_coeur' => 3.0], null))
        ->toBeTrue();
});

test('zéro reste un vrai seuil, pas une absence', function () {
    // La confusion que `rawValue` existe pour lever.
    poserSeuil('abattoir', 'ccp3_core_temp_max', '0');

    expect(app(RecordCcp::class)->evaluate(CcpRecord::CCP3, ['temperature_coeur' => 1.0], null))
        ->toBeFalse('0 °C déclaré est une limite, pas un réglage manquant.');
});

test('l’écran n’annonce PAS un seuil que le moteur n’applique pas', function () {
    /*
     * LE défaut. Avec le réglage effacé, le formulaire d'abattage affichait
     * « ≤ 4 °C » — un chiffre venu de son propre défaut, que le moteur ignore.
     */
    effacerSeuil('abattoir', 'ccp3_core_temp_max');

    $lot    = \App\Models\Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
    ]);
    $ordre  = ordreAbattage($lot->id, $this->adminUser->id);

    $html = $this->get(route('slaughter.execute.form', ['order' => $ordre->id]))
        ->assertOk()
        ->getContent();

    expect(str_contains($html, '≤ 4 °C'))
        ->toBeFalse('L’écran annonce un seuil de 4 °C que le moteur n’applique pas.');
});

test('un seuil PARAMÉTRÉ est bien annoncé à l’écran', function () {
    /*
     * Le pendant du test précédent, sans lequel un écran devenu muet passerait
     * aussi : quand la limite existe, l'opérateur doit la lire.
     */
    poserSeuil('abattoir', 'ccp3_core_temp_max', '4');

    $lot   = \App\Models\Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
    ]);
    $ordre = ordreAbattage($lot->id, $this->adminUser->id);

    $html = $this->get(route('slaughter.execute.form', ['order' => $ordre->id]))
        ->assertOk()
        ->getContent();

    expect(str_contains($html, '≤ 4 °C'))->toBeTrue('Le seuil paramétré doit être affiché.');
});

test('le REGISTRE CCP n’affiche pas une case vide en guise de seuil', function () {
    /*
     * Le troisième écran, et la troisième réponse : celui-ci n'avait aucun
     * défaut, donc affichait « Seuil maximum :  °C » — un blanc là où
     * l'opérateur cherche une limite.
     */
    effacerSeuil('abattoir', 'ccp3_core_temp_max');
    effacerSeuil('abattoir', 'ccp2_soiled_max_pct');

    $html = $this->get(route('slaughter.registres.ccp.create'))->assertOk()->getContent();

    /*
     * Il n'affichait pas un BLANC, mais pire : « 0 °C » et « 0 % ». `castValue`
     * transforme la chaîne vide en 0 pour un réglage numérique — un zéro qui se
     * lit comme une limite extrêmement stricte, alors qu'aucune n'est appliquée.
     * Sur la ligne des souillures, « toléré : 0 % » annonce même l'inverse de ce
     * que le moteur fait.
     */
    $texte = strip_tags($html);

    expect(str_contains($texte, 'Seuil maximum : 0 °C'))->toBeFalse()
        ->and(str_contains($texte, 'toléré : 0 %'))->toBeFalse()
        ->and(str_contains($texte, 'non paramétré'))->toBeTrue();
});
