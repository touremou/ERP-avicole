<?php

use App\Actions\Slaughter\RecordCcp;
use App\Models\CcpRecord;
use App\Models\Setting;
use App\Models\TemperatureLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN SEUIL VIDÉ DÉCLARAIT TOUT NON CONFORME, ET BLOQUAIT L'ABATTOIR.
 *
 * L'écran des Réglages annonce, dans son propre commentaire, que la validation
 * traite les valeurs vides : « vide = "ne pas toucher" / "effacer", traité plus
 * bas ». Plus bas, l'écriture ne saute les valeurs vides que pour les réglages
 * SENSIBLES (mots de passe, clefs d'API). Pour tous les autres — dont les dix
 * seuils HACCP, de type « number » — une chaîne vide part en base.
 *
 * Un commentaire qui promet une garde que le code n'implémente pas : c'est la
 * famille de défauts la plus fréquente de cet audit.
 *
 * ─── CE QUE PRODUISAIT UN CHAMP EFFACÉ PAR MÉGARDE ───
 *
 * La lecture coule la valeur en nombre : `(float) '' === 0.0`. Le seuil « T° à
 * cœur max » passait donc de 4 °C à 0 °C, et la conformité se lisait
 * « température ≤ 0 » :
 *
 *   • toute carcasse à 2 °C, parfaitement conforme, était déclarée NON CONFORME ;
 *   • une alerte HACCP « critique » partait à chaque relevé ;
 *   • et l'ordre d'abattage était BLOQUÉ — la production s'arrête.
 *
 * Le pire est le sens de l'erreur : pas une conformité complaisante, mais une
 * avalanche de fausses alertes sur le canal le plus grave. Comme pour l'écart de
 * caisse (#277), une alerte qui crie sur la routine finit par ne plus être lue.
 *
 * ─── LA CORRECTION EST À LA LECTURE, ET SEULEMENT LÀ ───
 *
 * Un seuil absent devient un seuil ABSENT, et non zéro. La borne n'est alors pas
 * appliquée, et l'appréciation de l'opérateur fait foi. Ce chemin existait déjà
 * dans TemperatureLog::isCompliant, qui teste `!== null` — il était simplement
 * INATTEIGNABLE, le cast en float transformant l'absence en 0.
 *
 * La lecture passe donc par `Setting::rawValue`, que le modèle porte justement
 * pour cela : « la seule façon de distinguer pas de réglage de réglé à zéro ».
 *
 * On ne réinvente aucun seuil de repli : les valeurs de référence vivent dans la
 * migration, et une constante en dur ici en serait une seconde déclaration.
 *
 * ─── UNE PREMIÈRE VERSION REFUSAIT AUSSI LA MISE À VIDE. ELLE AVAIT TORT ───
 *
 * Elle interdisait d'enregistrer un réglage numérique ou horaire vide. La suite
 * de tests l'a démentie : l'application donne déjà un SENS à l'absence, et le
 * documente — des bornes d'heures ouvrées vides signifient « surveillance
 * éteinte » (ScheduleHourSettingTest), une cible vide « pas de référence »
 * (Setting::rawValue).
 *
 * C'était donc opposer une règle neuve à deux règles établies : exactement le
 * défaut que cet audit poursuit. Le garde-fou d'écriture a été retiré. Vider un
 * seuil reste permis ; ce que cela veut dire est maintenant honnête — « pas de
 * contrôle automatique » — au lieu d'être catastrophique.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

/** Enregistre le groupe « abattoir » comme le fait l'écran des Réglages. */
function enregistrerSeuils(array $valeurs)
{
    return test()->put(route('settings.update'), [
        '_group'   => 'abattoir',
        'settings' => $valeurs,
    ]);
}

test('vider un seuil reste permis, comme partout ailleurs', function () {
    /*
     * La borne de cohérence. Une première version de ce correctif refusait ce
     * geste — et contredisait deux règles déjà établies : heures ouvrées vides =
     * « surveillance éteinte », cible vide = « pas de référence ». L'absence a un
     * sens dans cette application, y compris ici.
     */
    enregistrerSeuils(['ccp3_core_temp_max' => ''])->assertSessionHasNoErrors();

    expect(Setting::rawValue('abattoir.ccp3_core_temp_max'))->toBeEmpty();
});

test('une carcasse conforme reste conforme même si le seuil a été vidé', function () {
    /*
     * L'HISTORIQUE. Interdire le geste ne répare pas une base qui porte déjà un
     * seuil vide. On reconstitue cet état hors écran — puis on vérifie qu'une
     * température à 2 °C n'est plus condamnée par un seuil fantôme à 0.
     */
    Setting::where('group', 'abattoir')->where('key', 'ccp3_core_temp_max')->update(['value' => '']);

    $conforme = app(RecordCcp::class)->evaluate(
        CcpRecord::CCP3,
        ['temperature_coeur' => 2],
        true,
    );

    expect($conforme)->toBeTrue();
});

test('un seuil vidé n’applique aucune borne, il n’en invente pas une', function () {
    /*
     * Le chemin `!== null` de TemperatureLog::isCompliant existait déjà — il
     * était inatteignable, le cast transformant l'absence en 0.
     */
    Setting::where('group', 'abattoir')->where('key', 'cold_positive_max')->update(['value' => '']);

    expect(TemperatureLog::boundsFor('chambre_froide_positive')['max'])->toBeNull()
        ->and(TemperatureLog::isCompliant('chambre_froide_positive', 3))->toBeTrue();
});

test('le seuil RENSEIGNÉ continue de condamner ce qui le dépasse', function () {
    /*
     * LA borne qui compte le plus. On supprime les fausses alertes, on ne rend
     * pas le contrôle sanitaire aveugle : à 4 °C de maximum, une carcasse à 9 °C
     * reste non conforme.
     */
    $depasse = app(RecordCcp::class)->evaluate(
        CcpRecord::CCP3,
        ['temperature_coeur' => 9],
        true,
    );

    expect($depasse)->toBeFalse()
        ->and(TemperatureLog::isCompliant('chambre_froide_positive', 9))->toBeFalse();
});

test('un seuil à ZÉRO déclaré reste un vrai seuil', function () {
    /*
     * Le piège de la correction : « 0 » est une valeur légitime — c'est le
     * minimum de la chambre froide positive. Traiter zéro comme une absence
     * relâcherait un seuil réellement voulu.
     */
    Setting::where('group', 'abattoir')->where('key', 'cold_positive_min')->update(['value' => '0']);

    expect(TemperatureLog::boundsFor('chambre_froide_positive')['min'])->toBe(0.0)
        ->and(TemperatureLog::isCompliant('chambre_froide_positive', -3))->toBeFalse();
});

test('un seuil MODIFIÉ s’enregistre normalement', function () {
    // La borne du geste courant : le vétérinaire conseil doit pouvoir régler.
    enregistrerSeuils(['ccp3_core_temp_max' => '3'])->assertSessionHasNoErrors();

    expect((float) setting('abattoir.ccp3_core_temp_max'))->toBe(3.0);
});

test('un réglage de TEXTE reste effaçable', function () {
    /*
     * La borne qui protège l'existant : un pied de facture, une adresse, un logo
     * se vident légitimement. On ne durcit que ce qui n'a pas de vide qui
     * veuille dire quelque chose.
     */
    Setting::set('ventes.invoice_footer', 'Merci de votre confiance');

    test()->put(route('settings.update'), [
        '_group'   => 'ventes',
        'settings' => ['invoice_footer' => ''],
    ])->assertSessionHasNoErrors();

    expect(setting('ventes.invoice_footer'))->toBeEmpty();
});
