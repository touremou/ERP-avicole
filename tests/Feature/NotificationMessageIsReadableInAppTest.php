<?php

use App\Models\User;
use App\Notifications\AlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE RÉSUMÉ DU MATIN ARRIVAIT DANS L'APP EN UN BLOC PARSEMÉ D'ASTÉRISQUES.
 *
 * Les alertes et le résumé quotidien sont composés côté serveur en syntaxe
 * WhatsApp — titres de section en `*gras*`, une donnée par ligne, sous-lignes
 * indentées de deux espaces. C'est le bon format pour le canal auquel ils
 * étaient destinés à l'origine, et ce canal existe toujours.
 *
 * Mais la cloche affichait la chaîne telle quelle dans un `{{ }}` : les sauts de
 * ligne s'effondraient en un seul paragraphe et les astérisques s'affichaient
 * comme des astérisques. Le message le plus dense de l'application — celui que
 * le promoteur lit chaque matin depuis l'étranger — était le plus illisible.
 *
 * ─── CE QU'ON NE CHANGE PAS ───
 *
 * La source. Elle doit rester lisible sur WhatsApp. C'est l'AFFICHAGE qui
 * s'adapte, pas le message qui s'appauvrit.
 *
 * ─── L'ÉCHAPPEMENT VIENT D'ABORD ───
 *
 * Le message porte des noms saisis par des humains : un bâtiment appelé
 * « <B2> », un lot « A & B ». On échappe AVANT de poser le balisage, jamais
 * l'inverse — sans quoi on aurait remplacé un défaut d'affichage par une faille.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
});

/** Dépose une alerte in-app portant le message voulu. */
function alerteInApp(User $user, string $message, string $titre = 'Résumé Quotidien'): void
{
    $user->notify(new AlertNotification(
        ['type' => 'daily_summary', 'title' => $titre, 'message' => $message, 'severity' => 'normal'],
        ['database'],
    ));
}

test('les SECTIONS en gras cessent de s’afficher avec leurs astérisques', function () {
    /*
     * LE défaut visible : « *CHEPTEL* » se lisait avec ses étoiles.
     */
    alerteInApp($this->adminUser, "🐔 *CHEPTEL*\n  Effectif actif : *1491* sujets");

    $reponse = $this->get(route('notifications.index'))->assertOk();

    $reponse->assertSee('<strong>CHEPTEL</strong>', false)
        ->assertSee('<strong>1491</strong>', false)
        ->assertDontSee('*CHEPTEL*');
});

test('chaque donnée occupe SA ligne', function () {
    /*
     * Le second défaut : tout s'effondrait en un paragraphe. Trois lignes
     * doivent produire trois blocs, pas une phrase continue.
     */
    alerteInApp($this->adminUser, "Ligne une\nLigne deux\nLigne trois");

    $html = $this->get(route('notifications.index'))->assertOk()->getContent();

    expect(substr_count($html, '<span class="block'))->toBeGreaterThanOrEqual(3);
});

test('les sous-lignes indentées restent DÉCALÉES', function () {
    // Deux espaces en tête = sous-ligne, comme sur WhatsApp.
    alerteInApp($this->adminUser, "TOTAL\n  détail");

    $this->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('pl-4', false);
});

test('un nom contenant du HTML est ÉCHAPPÉ, pas interprété', function () {
    /*
     * La garde qui compte. Le message porte des noms saisis par des humains :
     * les rendre en HTML brut aurait troqué un défaut d'affichage contre une
     * injection.
     */
    alerteInApp($this->adminUser, 'Bâtiment <script>alert(1)</script> — *alerte*');

    $this->get(route('notifications.index'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;', false);
});

test('un astérisque ISOLÉ reste un astérisque', function () {
    // Le balisage ne doit pas manger un caractère ordinaire.
    alerteInApp($this->adminUser, 'Poids moyen 2,4 kg * coefficient');

    $this->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('2,4 kg * coefficient');
});

test('l’aperçu de la cloche est SANS balisage', function () {
    /*
     * La liste déroulante tronque à une ligne : le gras et les sauts de ligne
     * n'y auraient aucun effet, mais les astérisques en avaient un.
     */
    expect(notif_preview("🐔 *CHEPTEL*\n  Effectif : *1491*"))
        ->toBe('🐔 CHEPTEL Effectif : 1491');
});

test('l’aperçu TRONQUE les messages longs', function () {
    // Sinon le résumé quotidien entier s'invitait dans la liste déroulante.
    expect(strlen(notif_preview(str_repeat('a', 500))))->toBeLessThanOrEqual(125);
});
