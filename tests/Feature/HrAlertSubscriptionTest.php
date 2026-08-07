<?php

use App\Models\Employee;
use App\Models\NotificationPreference;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN INTERRUPTEUR QUI N'ÉTEIGNAIT QUE LA MOITIÉ DES LAMPES.
 *
 * `broadcast()` résout ses destinataires par DEUX chemins : getSubscribers()
 * pour le WhatsApp, typeRecipients() pour la cloche, l'e-mail et le push. Chacun
 * portait SA copie de la carte « type d'alerte → colonne de souscription », et
 * les deux divergeaient :
 *
 *   • WhatsApp rattachait `alert_hr_contract` et `alert_hr_attendance` à la
 *     souscription « anti-fraude » ;
 *   • les autres canaux ne les connaissaient pas et retombaient sur « aucun
 *     filtre » — c'est-à-dire TOUT LE MONDE.
 *
 * « Alertes anti-fraude » est la SEULE case que l'écran des préférences offre pour
 * ces deux alertes : il n'existe aucune case « RH ». La décocher arrêtait donc les
 * WhatsApp et laissait la cloche, le push et l'e-mail continuer d'arroser. On
 * croit avoir coupé, et on reçoit toujours.
 *
 * La carte est désormais déclarée une seule fois. Et comme `alert_fraud` vaut
 * « activé » par défaut, l'unification ne retire rien à personne : elle n'obéit
 * qu'à ceux qui ont ACTIVEMENT décoché.
 */

beforeEach(function () {
    $this->setUpRbac();
});

/** Notifications « database » reçues par un utilisateur, pour un type donné. */
function bellCount(int $userId, string $type): int
{
    return DB::table('notifications')
        ->where('notifiable_id', $userId)
        ->where('data', 'like', '%"' . $type . '"%')
        ->count();
}

test('décocher « anti-fraude » arrête AUSSI la cloche sur les alertes RH', function () {
    // Le défaut corrigé. Avant, cette case n'arrêtait que le WhatsApp.
    NotificationPreference::forUser($this->adminUser->id)->update([
        'is_active'        => true,
        'channel_database' => true,
        'alert_fraud'      => false,
    ]);

    // Par la porte publique : c'est le chemin qu'emprunte la tâche planifiée.
    app(NotificationHub::class)->alertAttendanceMissing(
        'Kindia',
        [now()->subDays(3)->toDateString(), now()->subDays(2)->toDateString()],
        4
    );

    expect(bellCount($this->adminUser->id, 'alert_hr_attendance'))->toBe(0);
});

test('la case cochée laisse passer les alertes RH', function () {
    // Le pendant : couper trop serait aussi grave que ne pas couper.
    NotificationPreference::forUser($this->adminUser->id)->update([
        'is_active'        => true,
        'channel_database' => true,
        'alert_fraud'      => true,
    ]);

    app(NotificationHub::class)->alertContractsToDecide(
        collect([Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif'])])
    );

    expect(bellCount($this->adminUser->id, 'alert_hr_contract'))->toBeGreaterThan(0);
});

test('un compte qui n’a jamais ouvert les réglages reçoit toujours', function () {
    // Garde-fou contre le piège rencontré sur le canal SMS : unifier une carte de
    // correspondance ne doit pas réduire la portée par effet de bord. `alert_fraud`
    // valant « activé » par défaut, un compte sans ligne de préférences continue
    // d'être servi.
    expect(NotificationPreference::DEFAULTS['alert_fraud'])->toBeTrue();

    DB::table('notification_preferences')->where('user_id', $this->managerUser->id)->delete();

    app(NotificationHub::class)->alertContractsToDecide(
        collect([Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif'])])
    );

    expect(bellCount($this->managerUser->id, 'alert_hr_contract'))->toBeGreaterThan(0);
});

test('les deux chemins de destinataires lisent la MÊME déclaration', function () {
    // Garde-fou de structure : c'est la duplication qui a produit la divergence.
    // Si une seconde carte réapparaît, ce test le dira.
    $source = file_get_contents(app_path('Services/NotificationHub.php'));

    expect($source)->toContain('private function subscriptionColumnFor(')
        // UNE seule fois le rattachement RH → anti-fraude : dans la déclaration
        // unique. Deux occurrences signifieraient que la carte est de nouveau
        // recopiée — la duplication est le défaut, pas la valeur.
        ->and(substr_count($source, "'alert_hr_attendance' => 'alert_fraud'"))->toBe(1)
        // …et les DEUX résolveurs de destinataires la consultent.
        ->and(substr_count($source, '$this->subscriptionColumnFor($type)'))->toBe(2);
});

test('un type sans souscription touche tout le monde, à dessein', function () {
    // Toutes les alertes ne sont pas gouvernées par une case : une alerte HACCP ou
    // une consigne structurelle ne se désabonne pas. Ce comportement doit rester,
    // sinon l'unification aurait fermé des alertes qu'on ne peut pas refuser.
    NotificationPreference::forUser($this->adminUser->id)->update([
        'is_active'        => true,
        'channel_database' => true,
        'alert_fraud'      => false,
    ]);

    app(NotificationHub::class)->alertHaccp(
        'Registre de températures incomplet.',
        'HACCP',
        'critique'
    );

    expect(bellCount($this->adminUser->id, 'alert_haccp'))->toBeGreaterThan(0);
});
