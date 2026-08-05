<?php

use App\Models\Building;
use App\Models\Setting;
use App\Services\PlanningService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * VIDE SANITAIRE — une règle de biosécurité, cinq déclarations.
 *
 * La durée du vide sanitaire était déclarée cinq fois : une constante, deux
 * réglages distincts dans deux onglets de Paramètres portant le même libellé
 * (14 j et 21 j), deux colonnes par bâtiment qu'aucun écran ne pouvait écrire.
 *
 * Régler « 21 jours » dans Paramètres › Élevage changeait le décompte du tableau
 * de bord, et rien d'autre : le planning appliquait son propre réglage, le compte
 * à rebours de la fiche affichait 14, et la LIBÉRATION AUTOMATIQUE rendait le
 * bâtiment disponible au 14ᵉ jour — une semaine avant le vide demandé.
 *
 * Un vide sanitaire écourté n'est pas un détail d'affichage : c'est la mesure qui
 * casse le cycle des pathogènes entre deux bandes. Le réglage existait, l'écran
 * l'acceptait, et le geste ne suivait pas.
 */

beforeEach(function () {
    $this->setUpRbac();
});

test('la durée réglée gouverne, et non la constante', function () {
    expect(Building::sanitaryBreakDays())->toBe(Building::SANITARY_BREAK_DAYS);

    Setting::set('elevage.sanitary_break_days', 21);

    expect(Building::sanitaryBreakDays())->toBe(21);
});

test('un réglage absurde ne supprime pas le vide sanitaire', function () {
    // Obéir à un zéro reviendrait à rendre le bâtiment disponible sur-le-champ.
    // Un garde-fou vaut mieux qu'une obéissance littérale.
    Setting::set('elevage.sanitary_break_days', 0);
    expect(Building::sanitaryBreakDays())->toBe(Building::SANITARY_BREAK_DAYS);

    Setting::set('elevage.sanitary_break_days', -5);
    expect(Building::sanitaryBreakDays())->toBe(Building::SANITARY_BREAK_DAYS);
});

test('la libération automatique HONORE la durée réglée', function () {
    // Le cœur du défaut : c'est cette commande qui rend le bâtiment réutilisable.
    Setting::set('elevage.sanitary_break_days', 21);

    $tooEarly = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'B-16j', 'type' => 'Poulailler',
        'capacity' => 500, 'status' => Building::STATUS_DESINFECTION,
        'disinfection_started_at' => now()->subDays(16),
    ]);

    $ripe = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'B-22j', 'type' => 'Poulailler',
        'capacity' => 500, 'status' => Building::STATUS_DESINFECTION,
        'disinfection_started_at' => now()->subDays(22),
    ]);

    $this->artisan('farm:release-buildings')->assertExitCode(0);

    // 16 jours < 21 : le bâtiment RESTE en désinfection. Avant, il était libéré.
    expect($tooEarly->fresh()->status)->toBe(Building::STATUS_DESINFECTION)
        ->and($tooEarly->fresh()->disinfection_started_at)->not->toBeNull();

    expect($ripe->fresh()->status)->toBe(Building::STATUS_VIDE)
        ->and($ripe->fresh()->disinfection_started_at)->toBeNull();
});

test('le compte à rebours affiché suit la durée réglée', function () {
    Setting::set('elevage.sanitary_break_days', 21);

    $building = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'B-rebours', 'type' => 'Poulailler',
        'capacity' => 500, 'status' => Building::STATUS_DESINFECTION,
        'disinfection_started_at' => now()->subDays(15),
    ]);

    // 21 - 15 = 6 jours restants. Sur l'ancienne constante, la fiche affichait 0
    // — « prêt » — pendant que le vide demandé courait encore.
    expect($building->sanitary_break_remaining_days)->toBe(6);
});

test('le décompte ne perd pas un jour par troncature', function () {
    // L'écart était mesuré en instants : une désinfection commencée à 14 h et
    // un vide de 14 jours donnaient 13,x jours restants, tronqués à 13. Le
    // dernier jour s'affichait donc « 0 » — prêt — un jour trop tôt, chaque fois.
    $building = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'B-troncature', 'type' => 'Poulailler',
        'capacity' => 500, 'status' => Building::STATUS_DESINFECTION,
        'disinfection_started_at' => now()->startOfDay()->addHours(14),
    ]);

    expect($building->sanitary_break_remaining_days)->toBe(Building::SANITARY_BREAK_DAYS);
});

test('le planning annonce la MÊME durée que celle appliquée', function () {
    // Un planning qui annonce une indisponibilité que le système ne respecte pas
    // est pire qu'un planning muet : on s'organise sur une date fausse.
    Setting::set('elevage.sanitary_break_days', 21);

    $building = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'B-planning', 'type' => 'Poulailler',
        'capacity' => 500, 'status' => Building::STATUS_DESINFECTION,
        'disinfection_started_at' => now()->subDays(5),
    ]);

    $conflicts = app(PlanningService::class)
        ->checkBuildingAvailability($building->id, now(), now()->addDays(30));

    $void = collect($conflicts)->firstWhere('type', 'vide_sanitaire');

    expect($void)->not->toBeNull()
        ->and(\Carbon\Carbon::parse($void['to'])->toDateString())
        ->toBe(now()->subDays(5)->addDays(21)->toDateString());
});

test('il ne reste QU’UN réglage de vide sanitaire dans Paramètres', function () {
    // Deux champs pour une durée, dans deux onglets, sous le même libellé : la
    // ferme ne pouvait pas savoir lequel comptait. Aucun ne comptait.
    $rows = DB::table('settings')->whereNull('farm_id')
        ->where(function ($q) {
            $q->where('key', 'like', '%sanitary%')->orWhere('key', 'like', '%sanitaire%');
        })
        ->get(['group', 'key']);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->group)->toBe('elevage')
        ->and($rows->first()->key)->toBe('sanitary_break_days');
});

test('la valeur réglée par la ferme survit à la réconciliation', function () {
    // Le doublon disparaît ; l'intention de l'exploitation, non. On rejoue la
    // migration sur un état « la ferme avait réglé l'ancien champ à 28 ».
    DB::table('settings')->insert([
        'group' => 'planning', 'key' => 'void_sanitaire_days', 'value' => '28',
        'type' => 'number', 'label' => 'Durée vide sanitaire', 'display_order' => 99,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('settings')->where('group', 'elevage')->where('key', 'sanitary_break_days')
        ->update(['value' => '14']);   // resté au défaut

    Setting::clearCache();

    require_once database_path('migrations/2026_08_16_000000_reconcile_duplicate_sanitary_break_setting.php');
    (require database_path('migrations/2026_08_16_000000_reconcile_duplicate_sanitary_break_setting.php'))->up();

    Setting::clearCache();

    expect(Building::sanitaryBreakDays())->toBe(28)
        ->and(DB::table('settings')->where('key', 'void_sanitaire_days')->exists())->toBeFalse();
});

test('la réconciliation n’ÉCOURTE jamais un vide sanitaire', function () {
    // Les deux champs réglés : on retient le plus long. Allonger est sans danger ;
    // raccourcir serait décider d'un raccourci sanitaire à la place de la ferme.
    DB::table('settings')->insert([
        'group' => 'planning', 'key' => 'void_sanitaire_days', 'value' => '30',
        'type' => 'number', 'label' => 'Durée vide sanitaire', 'display_order' => 99,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('settings')->where('group', 'elevage')->where('key', 'sanitary_break_days')
        ->update(['value' => '18']);

    (require database_path('migrations/2026_08_16_000000_reconcile_duplicate_sanitary_break_setting.php'))->up();

    Setting::clearCache();

    expect(Building::sanitaryBreakDays())->toBe(30);
});

test('aucun code ne lit la colonne dépréciée users.role', function () {
    // `users.role` porte un NOM de rôle et vaut « worker » par défaut ; le RBAC
    // vit dans role_id → roles. Trois endroits la lisaient encore : deux
    // commandes d'alerte qui plantaient sur un null, et un vidage de cache qui
    // comparait ce nom à un identifiant. Un défaut qui ne se voit qu'à
    // l'exécution — et ces commandes n'étaient planifiées nulle part.
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname()) as $no => $line) {
            if (preg_match("/(?:where|orWhere)\(\s*'role'\s*,/", $line)) {
                $offenders[] = str_replace(base_path() . '/', '', $file->getPathname()) . ':' . ($no + 1);
            }
        }
    }

    expect($offenders)->toBe([], "Lectures de la colonne dépréciée users.role :\n  " . implode("\n  ", $offenders));
});

test('toute commande d’alerte est réellement planifiée', function () {
    // Une commande qui émet des alertes et que rien ne déclenche est une alerte
    // qui n'existe pas. Deux d'entre elles dormaient ainsi depuis des mois —
    // et auraient planté si on les avait lancées.
    $scheduler = file_get_contents(base_path('routes/console.php'));

    $unscheduled = [];

    foreach (glob(app_path('Console/Commands/*.php')) as $path) {
        $source = file_get_contents($path);

        if (! preg_match('/\$signature\s*=\s*[\'"]([^\s\'"]+)/', $source, $m)) {
            continue;
        }

        $signature = $m[1];

        // Seules les commandes qui NOTIFIENT sont concernées : les outils de
        // maintenance (génération de clefs, export de schéma, diagnostic) se
        // lancent à la main, c'est leur raison d'être.
        $notifies = str_contains($source, '->notify(')
            || str_contains($source, 'NotificationHub');

        if (! $notifies) {
            continue;
        }

        if (! str_contains($scheduler, "Schedule::command('{$signature}'")) {
            $unscheduled[] = $signature . '  (' . basename($path) . ')';
        }
    }

    expect($unscheduled)->toBe([], "Commandes d'alerte jamais planifiées — elles n'alerteront personne :\n  " . implode("\n  ", $unscheduled));
});
