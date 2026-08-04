<?php

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * TOUT RÉGLAGE LU DOIT ÊTRE DÉCLARÉ.
 *
 * Le défaut qui revient : `setting('rh.contract_notice_days', 30)` est lu à
 * quatre endroits, dont la tâche planifiée des fins de contrat — mais la clef
 * n'existe dans aucun écran de Paramètres. L'application retourne alors
 * éternellement son repli, et rien ne le signale : ni erreur, ni champ vide,
 * ni ligne de journal. Un réglage figé qui a l'air d'un réglage.
 *
 * On l'a découvert par hasard une demi-douzaine de fois (nom d'exploitation,
 * `is_active` des fermes, cibles de rendement, URL des notifications). Ce test
 * ferme la porte : il relit le code source, en extrait toute clef lue EN DUR,
 * et exige qu'elle soit déclarée.
 *
 * Les clefs construites dynamiquement — `setting("abattoir.{$slug}")` — ne sont
 * pas couvertes ici : leur ensemble n'est pas connu du texte. Elles ont leurs
 * propres tests (nomenclature, numérotation), et ce sont d'ailleurs les seules
 * qui ne se sont jamais figées, puisqu'une clef absente y saute aux yeux.
 */

/**
 * Toutes les clefs `setting('groupe.clef')` / `Setting::get('groupe.clef')`
 * écrites littéralement dans le code applicatif et les vues.
 *
 * @return array<string, string>  clef => fichier:ligne du premier appel
 */
function literalSettingKeys(): array
{
    $roots = [app_path(), resource_path('views'), base_path('routes')];
    $keys = [];

    foreach ($roots as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $lines = file($file->getPathname());

            foreach ($lines as $no => $line) {
                if (! preg_match_all(
                    "/(?:setting|Setting::get)\(\s*'([a-z0-9_]+\.[a-z0-9_]+)'/",
                    $line, $matches
                )) {
                    continue;
                }

                foreach ($matches[1] as $key) {
                    $keys[$key] ??= str_replace(base_path() . '/', '', $file->getPathname())
                        . ':' . ($no + 1);
                }
            }
        }
    }

    ksort($keys);

    return $keys;
}

test('toute clef de réglage lue en dur est déclarée dans la table', function () {
    $read = literalSettingKeys();

    // Garde-fou du garde-fou : si l'extraction ne trouve plus rien, c'est elle
    // qui est cassée, et le test passerait en ne vérifiant rien.
    expect(count($read))->toBeGreaterThan(100);

    $declared = DB::table('settings')->whereNull('farm_id')
        ->get(['group', 'key'])
        ->map(fn ($s) => "{$s->group}.{$s->key}")
        ->flip();

    $missing = [];

    foreach ($read as $key => $where) {
        // Les clefs écrites à l'exécution (paire VAPID générée par
        // `php artisan push:generate-keys`) n'ont pas à préexister : elles
        // naissent du geste d'activation, et une valeur par défaut y serait un
        // secret partagé par toutes les installations.
        if (str_starts_with($key, 'push.vapid_')) {
            continue;
        }

        if (! $declared->has($key)) {
            $missing[] = "{$key}  (lu en {$where})";
        }
    }

    expect($missing)->toBe([], "Réglages lus mais jamais déclarés — ils resteront figés sur leur repli, invisibles dans Paramètres :\n  " . implode("\n  ", $missing));
});

test('chaque réglage appartient à un groupe qui a un onglet', function () {
    // Un réglage rangé dans un groupe non déclaré existe en base et ne
    // s'affiche nulle part : figé, exactement comme s'il n'existait pas.
    $tabs = array_keys(Setting::getGroups());

    $orphans = DB::table('settings')->whereNull('farm_id')
        ->distinct()->pluck('group')
        ->reject(fn ($g) => in_array($g, $tabs, true))
        ->values()->all();

    expect($orphans)->toBe([], 'Groupes de réglages sans onglet dans Paramètres : ' . implode(', ', $orphans));
});

test('les treize réglages du lot valent exactement leur ancien repli', function () {
    // Le point entier de ce lot : rendre réglable SANS rien changer. Si l'un de
    // ces chiffres bougeait, une exploitation verrait son comportement se
    // modifier au déploiement, sans que personne n'ait rien demandé.
    $expected = [
        'abattoir.yield_target_min'            => '70',
        'abattoir.yield_target_max'            => '75',
        'abattoir.yield_alert_min'             => '65',
        'abattoir.condemnation_tolerance'      => '2',
        'abattoir.tolerance_cutting_loss'      => '10',
        'abattoir.yield_ponte_est'             => '60-65',
        'abattoir.yield_repro_est'             => '55-65',
        'elevage.incident_diagnosis_sla_days'  => '2',
        'rh.contract_notice_days'              => '30',
        'ventes.reminder_cooldown_days'        => '7',
        'telemetry.min_interval_seconds'       => '300',
        'telemetry.min_delta_c'                => '0.3',
        'telemetry.calibration_gap_c'          => '2',
    ];

    foreach ($expected as $dotKey => $value) {
        [$group, $key] = explode('.', $dotKey, 2);

        $row = DB::table('settings')->where('group', $group)->where('key', $key)
            ->whereNull('farm_id')->first();

        expect($row)->not->toBeNull("Réglage manquant : {$dotKey}");
        expect((string) $row->value)->toBe($value, "Valeur inattendue pour {$dotKey}");
    }
});

test('un réglage désormais déclaré est réellement pris en compte', function () {
    // Déclarer ne suffit pas : encore faut-il que le site de lecture voie la
    // nouvelle valeur. On le vérifie sur le préavis de contrat, celui qui pilote
    // une tâche planifiée.
    expect((int) setting('rh.contract_notice_days'))->toBe(30);

    Setting::set('rh.contract_notice_days', 45);

    expect((int) setting('rh.contract_notice_days'))->toBe(45);
});

test('les colonnes de la table ne tronquent aucun libellé', function () {
    // Sur MySQL, `unit` est un varchar(20) et `label` un varchar(191) : un
    // dépassement n'y est pas rogné, il fait ÉCHOUER la migration en production
    // alors qu'il passe sur sqlite.
    $rows = DB::table('settings')->get(['group', 'key', 'label', 'unit']);

    foreach ($rows as $row) {
        expect(mb_strlen((string) $row->unit))->toBeLessThanOrEqual(20, "unit trop longue : {$row->group}.{$row->key}");
        expect(mb_strlen((string) $row->label))->toBeLessThanOrEqual(191, "label trop long : {$row->group}.{$row->key}");
    }
});
