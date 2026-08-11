<?php

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\Module;
use App\Models\ModulePermission;
use App\Models\NotificationPreference;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LES ALERTES DE CONGÉ N'ATTEIGNAIENT AUCUN VALIDEUR.
 *
 * Les trois notifications de congé — demande, approbation, refus — envoyaient un
 * WhatsApp EN DIRECT (`$this->whatsapp->send(...)`), hors de la chaîne de canaux.
 * Donc : ni cloche, ni push, ni e-mail. Et la destination `alert_leave`, pourtant
 * déclarée dans DESTINATIONS et couverte par un test, n'était jamais utilisée —
 * `destinationFor()` n'étant consulté que par broadcast().
 *
 * Le WhatsApp de l'exploitation est en mode journal faute de provider configuré :
 * une demande de congé ne parvenait donc À PERSONNE. Rien à l'écran, rien sur le
 * téléphone, rien par e-mail.
 *
 * broadcast() accepte désormais une AUDIENCE imposée, ce qui préserve le ciblage
 * volontaire : un congé va aux VALIDEURS, pas à qui a coché une case. Une
 * diffusion ouverte aurait envoyé les demandes de congé à toute l'exploitation.
 */

beforeEach(function () {
    $this->setUpRbac();

    // Le valideur : `annuaire` avec droit de suppression, c'est la règle que
    // NotificationHub applique pour désigner qui approuve.
    $annuaire = Module::where('slug', 'annuaire')->first();

    ModulePermission::updateOrCreate(
        ['role_id' => $this->managerUser->role_id, 'module_id' => $annuaire->id],
        ['can_read' => true, 'can_create' => true, 'can_modify' => true, 'can_delete' => true]
    );

    NotificationPreference::forUser($this->managerUser->id)
        ->update(['is_active' => true, 'channel_database' => true]);

    $this->employee = Employee::factory()->create([
        'farm_id' => $this->farm->id,
        'status'  => 'Actif',
        'user_id' => $this->adminUser->id,
    ]);

    $this->leave = EmployeeLeave::create([
        'farm_id'     => $this->farm->id,
        'employee_id' => $this->employee->id,
        'type'        => 'conge_annuel',
        'start_date'  => now()->addWeek()->toDateString(),
        'end_date'    => now()->addWeek()->addDays(2)->toDateString(),
        'days_count'  => 3,
        'status'      => 'demande',
    ]);
});

function leaveBell(int $userId): int
{
    return DB::table('notifications')
        ->where('notifiable_id', $userId)
        ->where('data', 'like', '%alert_leave%')
        ->count();
}

test('une demande de congé atteint le valideur sur sa CLOCHE, sans WhatsApp', function () {
    // LE test de régression : c'est le canal qui fonctionne quand le WhatsApp est
    // en mode journal. Avant, il ne recevait rien du tout.
    expect(leaveBell($this->managerUser->id))->toBe(0);

    app(NotificationHub::class)->notifyLeaveRequested($this->leave);

    expect(leaveBell($this->managerUser->id))->toBeGreaterThan(0);
});

test('un valideur SANS téléphone WhatsApp est tout de même prévenu', function () {
    // La requête filtrait sur `whereNotNull('whatsapp_phone')` : un valideur sans
    // numéro était écarté AVANT même qu'on envisage la cloche.
    $this->managerUser->update(['whatsapp_phone' => null]);

    app(NotificationHub::class)->notifyLeaveRequested($this->leave);

    expect(leaveBell($this->managerUser->id))->toBeGreaterThan(0);
});

test('la demande NE part PAS à toute l’exploitation', function () {
    // Le ciblage est volontaire : un congé regarde les valideurs. Une diffusion
    // ouverte inonderait les techniciens des demandes de leurs collègues.
    NotificationPreference::forUser($this->readonlyUser->id)
        ->update(['is_active' => true, 'channel_database' => true]);

    app(NotificationHub::class)->notifyLeaveRequested($this->leave);

    expect(leaveBell($this->readonlyUser->id))->toBe(0);
});

test('la réponse à la demande atteint l’employé dans l’application', function () {
    // Approbation et refus visaient l'employé par WhatsApp seul. Il doit voir la
    // réponse dans l'application, où il travaille.
    NotificationPreference::forUser($this->adminUser->id)
        ->update(['is_active' => true, 'channel_database' => true]);

    app(NotificationHub::class)->notifyLeaveApproved($this->leave->fresh());

    expect(leaveBell($this->adminUser->id))->toBeGreaterThan(0);
});

test('l’alerte de congé porte enfin sa DESTINATION', function () {
    // `alert_leave` était déclarée dans DESTINATIONS et testée — mais jamais
    // utilisée, faute de passer par broadcast(). Une alerte sans destination laisse
    // chercher où agir.
    app(NotificationHub::class)->notifyLeaveRequested($this->leave);

    $payload = DB::table('notifications')
        ->where('notifiable_id', $this->managerUser->id)
        ->where('data', 'like', '%alert_leave%')
        ->value('data');

    $data = json_decode((string) $payload, true);

    expect($data['url'] ?? null)->not->toBeNull()
        ->and($data['url'])->toContain('leaves')
        ->and($data['mobile_url'] ?? null)->toBe('/alertes');
});

test('aucune notification de congé ne contourne la chaîne de canaux', function () {
    // Garde-fou : c'est l'envoi DIRECT qui constituait le défaut. Si un chemin
    // futur reprend ce raccourci, la cloche et le push redeviennent muets sans que
    // rien ne le signale.
    $source = file_get_contents(app_path('Services/NotificationHub.php'));

    // On isole les trois méthodes de congé et on vérifie qu'aucune n'appelle
    // directement le service WhatsApp.
    foreach (['notifyLeaveRequested', 'notifyLeaveApproved', 'notifyLeaveRejected'] as $method) {
        $reflection = new ReflectionMethod(NotificationHub::class, $method);
        $body = implode('', array_slice(
            file($reflection->getFileName()),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));

        expect($body)->not->toContain('$this->whatsapp->send(', "{$method} contourne broadcast()")
            ->and($body)->toContain('broadcast(');
    }

    unset($source);
});
