<?php

use App\Models\Client;
use App\Models\Sale;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN CHAMP EFFACÉ VALAIT ZÉRO, PAS SON DÉFAUT.
 *
 * `Setting::get()` résout la valeur ainsi :
 *
 *     $value = $all[$dotKey] ?? $default;
 *
 * Le `??` ne se déclenche que si la clef est ABSENTE. Une clef présente avec une
 * valeur VIDE — ce qu'écrit l'écran des Réglages quand on efface un champ —
 * renvoie la chaîne vide, que le cast d'un réglage numérique transforme en 0.
 *
 * Deux cents appels de la forme `setting('x', 30)` promettaient donc un repli
 * qu'ils n'obtenaient jamais dans le seul cas où il servait vraiment.
 *
 * ─── CE QUE CELA DÉCLENCHAIT, EN CLAIR ───
 *
 * `ventes.payment_delay_days` effacé devient 0 : l'échéance d'une vente tombe le
 * JOUR de la vente, et toute vente à crédit non soldée est « en retard » dès le
 * lendemain.
 *
 * Or une commande planifiée tourne chaque jour à 09:00 et relance les clients en
 * retard PAR WHATSAPP. Et si `ventes.reminder_cooldown_days` a été effacé lui
 * aussi, le délai de courtoisie tombe à 0 : la relance repart TOUS LES JOURS.
 *
 * Effacer une case dans les Réglages suffisait donc à faire harceler la
 * clientèle de l'exploitation. C'est le seul défaut de cet audit dont les
 * conséquences sortent de la ferme.
 *
 * ─── LA CORRECTION, ET LA LEÇON DE #286 ───
 *
 * Une valeur vide se comporte désormais comme une valeur absente : le défaut
 * FOURNI PAR L'APPELANT s'applique. C'est exactement ce qu'un appelant déclare en
 * écrivant `setting('x', 30)` — « faute de valeur utilisable, prends 30 ».
 *
 * ─── ET SEULEMENT POUR LES RÉGLAGES NUMÉRIQUES ───
 *
 * Un premier jet appliquait ce repli à TOUS les types. La suite l'a démenti, une
 * fois de plus : un test exige qu'un pied de ticket vidé n'imprime AUCUNE ligne
 * de remerciement — et son appelant fournit pourtant un défaut. Mon argument
 * (« ceux qui acceptent le vide passent un défaut vide ») avait donc tort.
 *
 * Pour du texte, la chaîne vide est une valeur à part entière et l'application
 * s'en sert : pied de ticket effacé = pas de pied, heures ouvrées vides =
 * surveillance éteinte (#286). Le dégât venait EXCLUSIVEMENT du cast numérique,
 * qui donne à l'absence l'autorité d'un zéro. On ne corrige donc que
 * l'arithmétique, où le vide n'a aucun sens possible.
 *
 * Et « 0 » DÉCLARÉ reste zéro : on distingue le champ vidé du seuil réglé à
 * zéro, ce que le modèle sait faire depuis toujours.
 */

beforeEach(function () {
    $this->setUpRbac();

    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-500',
        'name' => 'Boulangerie du marché', 'category' => 'detaillant',
        'phone' => '620555444',
    ]);
});

/** Efface un réglage, comme le fait l'écran quand on vide la case. */
function effacerReglage(string $group, string $key): void
{
    Setting::where('group', $group)->where('key', $key)->update(['value' => '']);
    Setting::clearCache();
}

/** Une vente à crédit du jour, non soldée. */
function venteACredit(int $farmId, int $clientId, int $userId): Sale
{
    return Sale::create([
        'farm_id' => $farmId, 'client_id' => $clientId,
        'reference' => 'VTE-' . random_int(1000, 9999),
        'sale_date' => today()->toDateString(), 'status' => 'valide',
        'total_amount' => 800_000, 'paid_amount' => 0,
        'payment_status' => 'impaye', 'user_id' => $userId,
    ]);
}

test('un délai de paiement effacé ne rend pas la vente du jour en retard', function () {
    /*
     * LE défaut, et il sort de la ferme : la relance WhatsApp part le lendemain
     * de l'achat.
     */
    effacerReglage('ventes', 'payment_delay_days');

    venteACredit($this->farm->id, $this->client->id, $this->adminUser->id);

    expect(Sale::overdue()->count())->toBe(0);
});

test('le délai effacé reprend sa valeur par défaut, pas zéro', function () {
    effacerReglage('ventes', 'payment_delay_days');

    expect((int) setting('ventes.payment_delay_days', 30))->toBe(30);
});

test('le délai de courtoisie entre deux relances survit lui aussi', function () {
    /*
     * L'autre moitié du dégât : sans lui, la relance repart chaque jour.
     */
    effacerReglage('ventes', 'reminder_cooldown_days');

    expect((int) setting('ventes.reminder_cooldown_days', 7))->toBe(7);
});

test('« 0 » DÉCLARÉ reste zéro', function () {
    /*
     * LA distinction qui porte tout : un champ vidé n'est pas un réglage à zéro.
     * Confondre les deux relâcherait un seuil que quelqu'un a voulu.
     */
    Setting::set('ventes.payment_delay_days', '0');
    Setting::clearCache();

    expect((int) setting('ventes.payment_delay_days', 30))->toBe(0);
});

test('une valeur renseignée l’emporte toujours sur le défaut', function () {
    // La borne du cas courant.
    Setting::set('ventes.payment_delay_days', '15');
    Setting::clearCache();

    expect((int) setting('ventes.payment_delay_days', 30))->toBe(15);
});

test('une clef ABSENTE prend le défaut, comme avant', function () {
    // Le comportement d'origine, qu'on n'a pas touché.
    expect(setting('ventes.clef_qui_nexiste_pas', 'repli'))->toBe('repli');
});

test('« surveillance éteinte » reste éteinte — la leçon de #286', function () {
    /*
     * L'application donne un SENS à l'absence pour les heures ouvrées : vide =
     * pas de fenêtre, donc pas de silence nocturne. Ce cas est préservé, et la
     * raison est structurelle : cet appelant passe un défaut VIDE.
     *
     * Ce test tomberait si la correction avait substitué un défaut inventé.
     */
    effacerReglage('whatsapp', 'business_hours_start');

    expect(trim((string) setting('whatsapp.business_hours_start', '')))->toBe('');
});

test('un réglage de TEXTE effacé reste vide, MÊME avec un défaut', function () {
    /*
     * La borne qui a corrigé ce correctif. Un premier jet rendait son défaut à
     * tout réglage vidé ; un test du ticket de caisse l'a démenti — un pied de
     * ticket effacé doit n'imprimer AUCUNE ligne, et son appelant fournit
     * pourtant un défaut.
     *
     * Pour du texte, la chaîne vide est une valeur, pas une absence.
     */
    Setting::set('ventes.invoice_footer', 'Merci de votre confiance');
    effacerReglage('ventes', 'invoice_footer');

    expect(setting('ventes.invoice_footer'))->toBeEmpty()
        ->and(setting('ventes.invoice_footer', 'Texte de repli'))->toBeEmpty();
});
