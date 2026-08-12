<?php

use App\Models\Setting;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * UN RÉGLAGE QUE RIEN NE LIT EST UN CHAMP QUI MENT.
 *
 * SettingsDeclarationGuardTest couvre un sens : toute clef lue en dur doit être
 * déclarée dans la table. Personne ne couvrait l'autre — un réglage OFFERT à
 * l'écran que le code ne consulte jamais.
 *
 * C'est le défaut symétrique de presque tout ce qu'a corrigé cet audit. Là, une
 * règle avait des lecteurs et pas de rédacteur ; ici un réglage a un rédacteur —
 * l'utilisateur — et aucun lecteur. Le résultat est identique : on croit avoir agi.
 *
 * Et un champ inerte est PIRE qu'un champ absent, parce qu'il détourne d'un réglage
 * qui fonctionne. Trois exemples trouvés sur cette base, tous retirés dans la
 * migration remove_unread_settings :
 *
 *   • `abattoir.tolerance_poultry`, décrit « Anti-fraude three-way matching », alors
 *     que le moteur d'écart ne connaît que `volaille_vivante` et `volaille_abattue`.
 *     Le régler à 5 % en croyant assouplir la réception laissait la tolérance
 *     réelle à 0 % ;
 *   • `abattoir.yield_carcass`, abandonnée volontairement le 7 août mais restée à
 *     l'écran ;
 *   • `elevage.tabaski_date`, dont la description promet un décompte qui lit en
 *     réalité la date d'une campagne.
 *
 * ─── POURQUOI CE TEST DOIT ÊTRE PRÉCIS, ET COMMENT IL L'EST ───
 *
 * Une première version de ce balayage a rendu 27 « orphelins », dont 24 FAUX. Deux
 * causes, toutes deux instructives :
 *
 *   1. il ne lisait pas `config/`. Les tolérances d'écart y sont nommées, puis lues
 *      par `setting($entry['setting'])` — la clef complète n'apparaît donc nulle
 *      part ailleurs ;
 *   2. il exigeait la clef ENTIÈRE. Or plusieurs familles sont lues par un suffixe
 *      construit : `setting("abattoir.{$keys['max']}")` dans TemperatureLog,
 *      `setting("elevage.$key")` dans Batch, la clef venant d'un `match`.
 *
 * Un test qui crie 27 fois dont 24 à tort ne se lit pas, donc ne sert à rien. On
 * accepte donc trois formes de lecture — clef complète, nom de clef seul, et
 * mention dans config/ — quitte à laisser passer un orphelin dont le nom serait
 * homonyme d'autre chose. Mieux vaut manquer un cas douteux que noyer les vrais.
 */

test('aucun réglage offert à l’écran n’est ignoré par le code', function () {
    // Ce balayage a été éprouvé à l'envers : en créant dans cette même
    // transaction un réglage `abattoir.reglage_totalement_inerte` que rien ne lit,
    // le test échoue en le nommant. Un garde-fou qu'on n'a pas vu échouer ne
    // prouve rien.
    $haystack = '';
    $scanned = 0;

    foreach (['app', 'routes', 'config', 'resources/views', 'mobile/src'] as $dir) {
        if (! is_dir($path = base_path($dir))) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
            if ($file->isDir() || ! in_array($file->getExtension(), ['php', 'ts', 'tsx', 'js'], true)) {
                continue;
            }

            $scanned++;
            $haystack .= file_get_contents($file->getPathname());
        }
    }

    // Garde-fou du garde-fou : sans matière, le test passerait sans rien vérifier.
    expect($scanned)->toBeGreaterThan(300);

    /*
     * AUCUNE EXCLUSION — et c'est volontaire.
     *
     * J'en avais d'abord écrit cinq, « au cas où » : des réglages descriptifs de
     * l'exploitation que je supposais imprimés en bloc sur les documents. Vérifié
     * une à une, la liste était fausse de bout en bout — trois de ces clefs sont
     * bel et bien lues littéralement, et les deux autres N'EXISTENT PAS dans la
     * table. Une liste d'exclusions spéculative est exactement le trou par lequel
     * un vrai défaut passe : elle a été supprimée.
     *
     * Si un réglage légitimement illisible apparaît un jour, il devra être ajouté
     * ICI avec sa raison écrite — pas avant.
     */
    $orphans = [];

    foreach (Setting::whereNull('farm_id')->orderBy('group')->orderBy('key')->get() as $setting) {
        $dotted = "{$setting->group}.{$setting->key}";

        // 1. Clef complète — la forme la plus courante : setting('groupe.clef').
        if (str_contains($haystack, $dotted)) {
            continue;
        }

        // 2. Nom de clef SEUL — les familles lues par un suffixe construit
        //    (TemperatureLog, Batch::dailyMortalityPhaseKey…). On exige un nom
        //    suffisamment distinctif pour que la coïncidence soit improbable.
        if (strlen($setting->key) >= 8 && str_contains($haystack, $setting->key)) {
            continue;
        }

        $orphans[] = "{$dotted} — « {$setting->label} »";
    }

    expect($orphans)->toBe([], "Réglages offerts à l’écran que RIEN ne lit :\n  " . implode("\n  ", $orphans));
});

test('les trois réglages inertes ont bien disparu de l’écran', function () {
    // Le pendant concret : la migration a-t-elle porté ? Un test de forme sur le
    // balayage ne le dirait pas, puisqu'il passerait aussi si les clefs étaient
    // simplement devenues lues.
    foreach ([['abattoir', 'tolerance_poultry'], ['abattoir', 'yield_carcass'], ['elevage', 'tabaski_date']] as [$group, $key]) {
        expect(Setting::where('group', $group)->where('key', $key)->whereNull('farm_id')->exists())
            ->toBeFalse("Le réglage inerte {$group}.{$key} est encore offert à l’écran.");
    }
});

test('les tolérances RÉELLEMENT utilisées par le moteur d’écart sont, elles, présentes', function () {
    // Le risque de ce lot : retirer une clef qui servait. `tolerance_poultry` n'est
    // pas lue, mais ses deux voisines le sont — et elles gouvernent l'anti-fraude
    // à la réception. On vérifie que le ménage ne les a pas emportées.
    foreach ((array) config('logistique.tolerances') as $entry) {
        [$group, $key] = explode('.', $entry['setting'], 2);

        expect(Setting::where('group', $group)->where('key', $key)->whereNull('farm_id')->exists())
            ->toBeTrue("La tolérance {$entry['setting']}, lue par le moteur d’écart, a disparu de la table.");
    }
});

test('le décompte Tabaski continue de fonctionner par la CAMPAGNE', function () {
    // Retirer `elevage.tabaski_date` ne doit rien casser : le décompte lit la date
    // de la campagne, ce qui est la bonne source — elle permet d'en suivre
    // plusieurs, là où un réglage global n'en porte qu'une.
    $controller = file_get_contents(app_path('Http/Controllers/DashboardController.php'));

    expect($controller)->toContain('tabaski')
        ->and($controller)->toContain('target_date')
        // Et il ne s'est jamais appuyé sur le réglage retiré.
        ->and($controller)->not->toContain('tabaski_date');
});
