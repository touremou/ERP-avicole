<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * LES LISTES DE VALEURS DÉCLARÉES DANS LES MODÈLES vs CELLES DE LA BASE.
 *
 * Un modèle qui déclare `const STATUSES = [...]` affirme connaître les valeurs
 * admises. Si la base en admet d'autres — ou moins — l'une des deux déclarations
 * ment, et on ne l'apprend qu'à l'écriture : soit une saisie légitime est refusée
 * par la base (erreur serveur), soit une valeur impossible passe le formulaire.
 *
 * C'est la famille corrigée dans #201, où deux ENUM ne connaissaient pas des
 * valeurs que le code écrivait depuis un mois (« dechet » à la découpe, « Annulé »
 * sur un ordre de production). Ces deux colonnes sont passées en chaîne ; ce test
 * empêche la divergence de revenir par une autre colonne.
 *
 * ─── POURQUOI CE TEST NE PEUT PAS ÊTRE VAGUE ───
 *
 * Une première version comparait les règles de validation `in:` aux ENUM en
 * appariant sur le NOM DU CHAMP. Résultat : 688 « écarts », tous faux — le `type`
 * d'un mouvement de stock était confronté au `type` d'un client. Un test qui crie
 * 688 fois ne se lit pas, donc ne sert à rien.
 *
 * On compare donc ce qui est réellement comparable : les constantes-listes d'un
 * MODÈLE face aux ENUM de SA table, et seulement quand elles se recoupent — sans
 * recoupement, elles parlent d'autre chose.
 */

test('aucune liste de valeurs d’un modèle ne diverge de l’ENUM de sa table', function () {
    // sqlite ne conserve pas les types ENUM : il les stocke en texte libre. Le
    // balayage n'y trouverait AUCUN enum et passerait sans rien vérifier — un
    // garde-fou vide, exactement ce que ce lot cherche à éliminer. On saute
    // explicitement plutôt que de laisser croire à une vérification.
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped('Les ENUM ne survivent pas à sqlite : ce garde-fou ne vaut que sur MySQL (joué en CI).');
    }

    $enums = [];

    foreach (Schema::getTableListing() as $table) {
        $short = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        foreach (Schema::getColumns($table) as $column) {
            if (! str_starts_with($column['type'] ?? '', 'enum')) {
                continue;
            }

            preg_match_all("/'([^']+)'/", $column['type'], $matches);
            $enums[$short][$column['name']] = $matches[1];
        }
    }

    // Le balayage doit trouver de quoi travailler : zéro ENUM signifierait que la
    // détection est cassée, et le test passerait en ne vérifiant rien.
    expect(count($enums))->toBeGreaterThan(10);

    /*
     * Exclusions MOTIVÉES, une par une. Une liste d'exclusions sans raison écrite
     * devient le trou par lequel un vrai défaut passe.
     */
    $legitimate = [
        // Sous-ensemble VOLONTAIRE : les contrats à terme (avec date de fin), par
        // opposition au CDI. La colonne admet les trois ; la constante n'en
        // désigne que deux, et c'est tout son objet (cf. hasFixedTerm()).
        'Employee::FIXED_TERM',

        // Sous-ensemble VOLONTAIRE : les statuts d'absence qui OCCUPENT le
        // calendrier d'un agent, donc ceux avec lesquels une nouvelle absence
        // entre en conflit. La colonne admet cinq valeurs ; celle-ci en désigne
        // quatre — « refuse » en est exclu à dessein, un congé refusé n'ayant
        // jamais eu lieu (cf. EmployeeLeave::overlapping()).
        'EmployeeLeave::OCCUPYING_STATUSES',
    ];

    $divergences = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\' . basename($file, '.php');

        if (! class_exists($class)) {
            continue;
        }

        try {
            $model = new $class;
        } catch (\Throwable) {
            continue;   // modèle non instanciable sans contexte : hors sujet ici
        }

        $table = $model->getTable();

        if (! isset($enums[$table])) {
            continue;
        }

        foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
            if (! is_array($value) || $value === []) {
                continue;
            }

            // Listes de chaînes uniquement : une constante de configuration
            // imbriquée n'est pas une liste de valeurs admises.
            if (count(array_filter($value, 'is_string')) !== count($value)) {
                continue;
            }

            if (in_array(basename($file, '.php') . '::' . $name, $legitimate, true)) {
                continue;
            }

            foreach ($enums[$table] as $column => $allowed) {
                // Sans recoupement, la constante et l'ENUM ne parlent pas de la
                // même chose : les confronter fabriquerait du bruit.
                if (array_intersect($value, $allowed) === []) {
                    continue;
                }

                $extra = array_values(array_diff($value, $allowed));
                $missing = array_values(array_diff($allowed, $value));

                if ($extra === [] && $missing === []) {
                    continue;
                }

                $divergences[] = sprintf(
                    '%s::%s vs %s.%s — le modèle autorise en trop [%s], la base [%s]',
                    basename($file, '.php'), $name, $table, $column,
                    implode(',', $extra) ?: 'rien',
                    implode(',', $missing) ?: 'rien'
                );
            }
        }
    }

    expect($divergences)->toBe([], "Listes de valeurs divergentes :\n  " . implode("\n  ", $divergences));
});
