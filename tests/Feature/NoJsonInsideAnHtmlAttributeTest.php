<?php

use Tests\TestCase;

uses(TestCase::class);

/*
 * `@json` DANS UN ATTRIBUT HTML FERME L'ATTRIBUT.
 *
 * `@json('Supprimer ?')` produit `"Supprimer ?"` — avec des guillemets
 * DOUBLES. Posé dans `onsubmit="return confirm(@json(…))"`, le premier d'entre
 * eux referme l'attribut : le navigateur lit `onsubmit="return confirm("`, un
 * gestionnaire qui ne compile pas, donc AUCUN gestionnaire — et le formulaire
 * part sans demander confirmation. Vérifié dans Chromium sur la fiche lot :
 * « Annuler cet achat ? » n'était jamais posée.
 *
 * Vingt-neuf occurrences : annulation d'achat, suppression d'incubation,
 * d'acte sanitaire, de protocole, annulation d'ordre de production et
 * d'abattage, liste noire fournisseur… Les étiquettes Alpine (`x-text`)
 * restaient vides, et la fiche lot levait « Unexpected token '}' ».
 *
 * `@js` produit une chaîne entre apostrophes, échappée pour HTML : c'est la
 * directive des attributs. `@json` reste juste dans un bloc <script>.
 */
test('aucun @json ne se trouve dans un attribut HTML', function () {
    $fautifs = [];

    $vues = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));
    foreach ($vues as $fichier) {
        if (! str_ends_with($fichier->getFilename(), '.blade.php')) continue;

        $source = file_get_contents($fichier->getPathname());
        // Dans un <script>, @json est à sa place : on le masque.
        $masque = preg_replace_callback('/<script\b.*?<\/script>/s', fn ($m) => str_repeat(' ', strlen($m[0])), $source);

        preg_match_all('/@json\(/', $masque, $occurrences, PREG_OFFSET_CAPTURE);
        foreach ($occurrences[0] as [, $position]) {
            $debutBalise = strrpos(substr($masque, 0, $position), '<');
            $avant = substr($masque, $debutBalise, $position - $debutBalise);

            // Un nombre impair de guillemets depuis l'ouverture de la balise :
            // on est À L'INTÉRIEUR d'une valeur d'attribut.
            if (preg_match_all('/(?<!\\\\)"/', $avant) % 2 === 1) {
                $ligne = substr_count(substr($source, 0, $position), "\n") + 1;
                $fautifs[] = str_replace(resource_path('views') . '/', '', $fichier->getPathname()) . ':' . $ligne;
            }
        }
    }

    expect($fautifs)->toBe([]);
});
