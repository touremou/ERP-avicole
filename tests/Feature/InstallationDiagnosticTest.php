<?php

use App\Models\DailyCheck;
use App\Models\Setting;
use App\Models\Stock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DIAGNOSTIC DE L'INSTALLATION — ce que la commande doit voir, et ne pas faire.
 *
 * Presque chaque difficulté remontée du terrain pendant cette session n'était pas
 * un défaut de code mais un état d'installation invisible : WhatsApp en mode
 * journal, clefs de notification jamais générées, compte non rattaché à son site.
 * La commande les nomme. Ces tests garantissent qu'elle les nomme VRAIMENT — un
 * diagnostic qui rend « tout va bien » sur une installation muette serait pire
 * qu'aucun diagnostic.
 *
 * DEUX PROPRIÉTÉS COMPTENT PLUS QUE LES AUTRES :
 *
 *   1. Elle n'écrit RIEN. C'est ce qu'elle annonce en tête d'écran ; on l'éprouve
 *      ici en comparant un relevé de toutes les tables avant/après.
 *   2. Elle ne rend pas de verdict qu'elle n'a pas les moyens de porter. Le
 *      planificateur de l'hébergeur n'est pas observable depuis une commande
 *      lancée à la main : elle doit le DIRE, non le supposer vert.
 */

beforeEach(function () {
    $this->setUpRbac();
});

/**
 * Lance le diagnostic et rend [code de sortie, texte complet].
 *
 * POURQUOI PAS `expectsOutputToContain` : cette assertion se règle sur les APPELS
 * d'écriture, un par ligne, et Mockery n'attribue chaque appel qu'à UNE attente.
 * Deux fragments attendus sur la MÊME ligne — « BLOQUANT » et le constat qui le
 * suit — ne peuvent donc jamais être satisfaits ensemble : le test échouait en
 * annonçant absent un texte pourtant présent à l'écran. Vérifié à la main.
 * On lit donc la sortie entière, et on l'examine.
 */
function runDiagnostic(): array
{
    $code = Artisan::call('avismart:diagnostic');

    return [$code, Artisan::output()];
}

/** Amène l'installation à un état sain, pour éprouver les cas UN À UN. */
function makeInstallationHealthy(int $farmId): void
{
    Setting::set('whatsapp.driver', 'ultramsg');
    Setting::set('whatsapp.api_key', 'clef-de-test');
    Setting::set('whatsapp.admin_phone', '+224620000000');
    Setting::set('push.vapid_public_key', 'pub');
    Setting::set('push.vapid_private_key', 'priv');
    Setting::set('general.company_name', 'Ferme de Kindia');

    // Rattache tous les comptes au site : un compte orphelin est BLOQUANT, et
    // les fabriques n'écrivent pas le pivot.
    foreach (DB::table('users')->pluck('id') as $id) {
        DB::table('farm_user')->insertOrIgnore([
            'farm_id' => $farmId, 'user_id' => $id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    DB::table('users')->update(['whatsapp_phone' => '+224610000000']);

    // Une installation saine a des sauvegardes RÉCENTES : c'est le seul incident de
    // ce diagnostic qui ne se rattrape pas, et son absence est donc bloquante.
    \Illuminate\Support\Facades\Storage::fake('backups')->put('avismart/hier.zip', 'contenu');
}

test('la commande n’écrit RIEN', function () {
    // La promesse affichée en tête d'écran. Un diagnostic qui modifie l'état
    // observé n'est plus un diagnostic.
    makeInstallationHealthy($this->farm->id);

    $before = [];

    foreach (DB::connection()->getSchemaBuilder()->getTableListing() as $table) {
        $short = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
        $before[$short] = DB::table($short)->count();
    }

    // Le relevé doit porter sur quelque chose : zéro table signifierait que le
    // test passe sans rien comparer.
    expect(count($before))->toBeGreaterThan(20);

    runDiagnostic();

    $after = [];

    foreach (array_keys($before) as $short) {
        $after[$short] = DB::table($short)->count();
    }

    expect($after)->toBe($before);
});

test('WhatsApp en mode journal est BLOQUANT, avec le geste exact', function () {
    // L'état réel de cette exploitation depuis le début : aucune alerte ne part,
    // et rien ne le disait sur aucun écran.
    makeInstallationHealthy($this->farm->id);
    Setting::set('whatsapp.driver', 'log');

    [$code, $out] = runDiagnostic();

    expect($out)->toContain('BLOQUANT')
        ->and($out)->toContain('n’atteignent PERSONNE')
        ->and($out)->toContain('Paramètres › WhatsApp')
        ->and($code)->toBe(1);
});

test('une clé Twilio sans deux-points est signalée AVANT le premier échec', function () {
    // Twilio exige deux identifiants ; l'écran n'en offre qu'un champ, à remplir
    // « SID:TOKEN ». Sans les deux moitiés, chaque envoi rend un 401 muet.
    makeInstallationHealthy($this->farm->id);
    Setting::set('whatsapp.driver', 'twilio');
    Setting::set('whatsapp.api_key', 'ACxxxxxxxx');   // le SID seul
    Setting::set('whatsapp.sender', '+14155238886');

    [$code, $out] = runDiagnostic();

    expect($out)->toContain('SID:TOKEN')->and($code)->toBe(1);
});

test('un compte non rattaché à son site est BLOQUANT et NOMMÉ', function () {
    // Le piège le plus coûteux à diagnostiquer : toutes les saisies mobiles du
    // compte sont refusées, et le message parle de « bâtiment invalide ». Qui
    // cherche la cause dans les bâtiments ne la trouve jamais.
    makeInstallationHealthy($this->farm->id);

    $this->adminUser->update(['name' => 'Technicien Kérouané']);
    DB::table('farm_user')->where('user_id', $this->adminUser->id)->delete();

    [$code, $out] = runDiagnostic();

    expect($out)->toContain('Technicien Kérouané')
        ->and($out)->toContain('bâtiment invalide')
        ->and($code)->toBe(1);
});

test('les clefs de notification absentes sont BLOQUANTES', function () {
    makeInstallationHealthy($this->farm->id);
    Setting::set('push.vapid_private_key', '');

    [$code, $out] = runDiagnostic();

    expect($out)->toContain('push:generate-keys')->and($code)->toBe(1);
});

test('un pointage consommant de l’aliment sans coût est signalé', function () {
    // Le trou silencieux dans le coût de revient : le total s'affiche quand même,
    // simplement faux vers le bas.
    makeInstallationHealthy($this->farm->id);

    $batch = \App\Models\Batch::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $batch->id,
        'user_id' => $this->adminUser->id, 'check_date' => now()->toDateString(),
        'feed_consumed' => 40, 'feed_type' => 'Chair Démarrage',
        'feed_unit_cost' => null,
    ]);

    expect(runDiagnostic()[1])->toContain('SANS coût unitaire');
});

test('un article en stock sans prix est signalé', function () {
    makeInstallationHealthy($this->farm->id);

    Stock::withoutGlobalScopes()->create([
        'farm_id'          => $this->farm->id,
        'item_name'        => 'Maïs vrac',
        'category'         => Stock::CAT_CONSO,
        'unit'             => 'KG',
        'current_quantity' => 300,
        'alert_threshold'  => 10,
        'unit_price'       => 0,
    ]);

    $out = runDiagnostic()[1];

    expect($out)->toContain('Maïs vrac')->and($out)->toContain('comptée gratuite');
});

test('le planificateur n’est JAMAIS déclaré vert sans preuve', function () {
    /*
     * Ce test disait d'abord « NON VÉRIFIABLE, jamais vert ». Sa prémisse a été
     * dépassée, et il faut le dire plutôt que de le contorsionner : la sauvegarde de
     * 02:00 est déclenchée par le planificateur, et par lui seul. Une sauvegarde
     * datée de cette nuit est donc une PREUVE que le cron tourne.
     *
     * Ce qui ne change pas — et c'est le vrai principe — c'est qu'on ne rend jamais
     * ce verdict SANS trace. Sans preuve, on dit qu'on ne sait pas.
     */
    makeInstallationHealthy($this->farm->id);

    // Aucune trace : on ne conclut pas.
    \Illuminate\Support\Facades\Storage::fake('backups');

    $out = runDiagnostic()[1];

    expect($out)->toContain('NON VÉRIFIABLE')->and($out)->toContain('schedule:run');
});

test('une installation saine ne rend AUCUN bloquant', function () {
    // Le pendant indispensable : un diagnostic qui crie toujours ne se lit plus.
    // S'il reste un bloquant ici, c'est que l'un des contrôles se déclenche sur un
    // état normal — et il faut le corriger, pas s'y habituer.
    makeInstallationHealthy($this->farm->id);

    [$code, $out] = runDiagnostic();

    expect($out)->not->toContain('BLOQUANT')->and($code)->toBe(0);
});

test('chaque constat de niveau bloquant ou attention porte un remède', function () {
    // Un diagnostic qui nomme un problème sans dire quoi faire renvoie l'utilisateur
    // exactement là où il était. On l'impose par la structure, pas par relecture.
    $reflection = new ReflectionClass(\App\Console\Commands\InstallationDiagnostic::class);

    $source = file_get_contents($reflection->getFileName());

    // Chaque appel à attention()/blocking() doit comporter au moins deux
    // arguments — le second étant le geste à faire.
    preg_match_all('/\$this->(attention|blocking)\(([^;]*?)\);/s', $source, $matches);

    expect(count($matches[0]))->toBeGreaterThan(8);

    $withoutRemedy = [];

    foreach ($matches[2] as $i => $args) {
        // Trois arguments attendus : sujet, constat, remède.
        //
        // On compte les virgules de PREMIER NIVEAU, en ignorant celles qui vivent
        // dans une chaîne ou entre parenthèses. Ma première version comptait
        // naïvement les occurrences de « ', » : un constat bâti par concaténation —
        // 'texte : ' . $e->getMessage() — n'en portait pas, et le test criait au
        // remède manquant sur un appel parfaitement correct. Un détecteur qui
        // produit des faux positifs finit ignoré, donc inutile.
        $depth = 0;
        $quote = null;
        $topLevelCommas = 0;

        foreach (str_split($args) as $char) {
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $topLevelCommas++;
            }
        }

        if ($topLevelCommas < 2) {
            $withoutRemedy[] = trim(substr($matches[0][$i], 0, 90));
        }
    }

    expect($withoutRemedy)->toBe([], "Constats sans remède :\n  " . implode("\n  ", $withoutRemedy));
});

test('une heure de réglage illisible est signalée', function () {
    // Elle n'arrête plus le planificateur, mais l'heure qui s'applique n'est pas
    // celle que l'exploitation a choisie — et rien d'autre ne le dit.
    makeInstallationHealthy($this->farm->id);
    Setting::set('whatsapp.daily_summary_hour', '25:00');

    $out = runDiagnostic()[1];

    expect($out)->toContain('25:00')->and($out)->toContain('la valeur de repli s’applique');
});

// ─────────────────────────────────────────────────────────────
//  LES SAUVEGARDES — le seul incident de cette liste qui ne se rattrape pas
// ─────────────────────────────────────────────────────────────

test('l’absence TOTALE de sauvegarde est BLOQUANTE', function () {
    // Tout le reste de ce diagnostic porte sur des états rattrapables. Une panne de
    // disque sans sauvegarde, non : elle met fin à l'exploitation.
    makeInstallationHealthy($this->farm->id);
    \Illuminate\Support\Facades\Storage::fake('backups');

    [$code, $out] = runDiagnostic();

    expect($out)->toContain('AUCUNE sauvegarde')
        ->and($out)->toContain('ne se rattrape pas')
        ->and($code)->toBe(1);
});

test('une sauvegarde TROP ANCIENNE est bloquante, pas « au vert »', function () {
    // Un fichier présent ne prouve rien s'il date de trois semaines : la sauvegarde
    // quotidienne ne tourne alors plus, et personne ne s'en aperçoit.
    makeInstallationHealthy($this->farm->id);

    $disk = \Illuminate\Support\Facades\Storage::fake('backups');
    $disk->put('avismart/2026-07-01.zip', 'contenu');
    touch($disk->path('avismart/2026-07-01.zip'), now()->subDays(20)->timestamp);

    [$code, $out] = runDiagnostic();

    expect($out)->toContain('ne tourne donc plus')->and($code)->toBe(1);
});

test('une sauvegarde RÉCENTE prouve que le planificateur tourne', function () {
    /*
     * Le point le plus utile de ce lot.
     *
     * Le diagnostic annonçait le planificateur « NON VÉRIFIABLE d'ici », et c'était
     * vrai au sens strict : aucune commande lancée à la main n'interroge le cron de
     * l'hébergeur. Mais elle peut en observer la TRACE — la sauvegarde de 02:00 est
     * déclenchée par le planificateur, et par lui seul.
     *
     * Un fait établi par ce qu'il a laissé vaut mieux qu'un « je ne sais pas ».
     */
    makeInstallationHealthy($this->farm->id);

    $disk = \Illuminate\Support\Facades\Storage::fake('backups');
    $disk->put('avismart/hier.zip', 'contenu');

    $out = runDiagnostic()[1];

    expect($out)->toContain('Le planificateur TOURNE')
        ->and($out)->not->toContain('NON VÉRIFIABLE');
});

test('sans sauvegarde récente, le planificateur reste déclaré NON VÉRIFIABLE', function () {
    // Le pendant : sans trace, on ne conclut pas. Annoncer « le planificateur
    // tourne » sans preuve serait exactement le verdict inventé que cette commande
    // s'interdit.
    makeInstallationHealthy($this->farm->id);
    \Illuminate\Support\Facades\Storage::fake('backups');

    expect(runDiagnostic()[1])->toContain('NON VÉRIFIABLE');
});

test('des sauvegardes uniquement LOCALES sont signalées', function () {
    // Une sauvegarde à côté des données ne protège que de l'effacement, pas de la
    // perte de la machine.
    makeInstallationHealthy($this->farm->id);

    $disk = \Illuminate\Support\Facades\Storage::fake('backups');
    $disk->put('avismart/hier.zip', 'contenu');

    config(['backup.backup.destination.disks' => ['backups']]);

    expect(runDiagnostic()[1])->toContain('emporterait les données ET leurs sauvegardes');
});

test('une copie hors site est reconnue', function () {
    makeInstallationHealthy($this->farm->id);

    $disk = \Illuminate\Support\Facades\Storage::fake('backups');
    $disk->put('avismart/hier.zip', 'contenu');

    config(['backup.backup.destination.disks' => ['backups', 'backups_offsite']]);

    expect(runDiagnostic()[1])->toContain('Copie sur 2 destination(s)');
});
