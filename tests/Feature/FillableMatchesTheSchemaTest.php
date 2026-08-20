<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * DES CHAMPS DÉCLARÉS MODIFIABLES, POUR DES COLONNES QUI N'EXISTENT PAS.
 *
 * `$fillable` est la liste de ce qu'un formulaire a le droit d'écrire. Y
 * inscrire un nom sans colonne derrière, c'est promettre une écriture que la
 * base refusera : le jour où un appelant passe la clé, l'insertion part en
 * erreur SQL.
 *
 * Un balayage de tous les modèles contre le schéma réel en a trouvé neuf :
 *
 *   • StockMovement::unit_price   — jamais créée par aucune migration, et lue
 *     par un accesseur `total_value` qui ne pouvait donc rendre que 0 ;
 *   • EggMovement::batch_id, stock_id, user_id, unit — le modèle a été écrit
 *     contre un schéma qui n'est jamais venu ;
 *   • Building::is_active, Role::farm_id, Formula::description,
 *     FormulaItem::dosage_weight — des intentions restées sans suite.
 *
 * ─── CE QUE ÇA N'ÉTAIT PAS ───
 *
 * Aucune des neuf n'était un défaut vivant : rien ne les écrivait, aucun
 * formulaire ne les proposait, aucun écran ne les lisait. On les retire parce
 * qu'elles mentent sur ce que le modèle sait faire, pas parce qu'elles
 * cassaient quelque chose.
 *
 * ─── POURQUOI UN TEST PLUTÔT QU'UN NETTOYAGE ───
 *
 * Nettoyer une fois ne tient pas : la divergence revient dès qu'une colonne est
 * renommée ou qu'une migration prévue n'est pas écrite. C'est exactement ce qui
 * s'est produit pour `birth_date` (#295), déclarée partout et écrite nulle part.
 *
 * Ce test dérive la vérification du SCHÉMA RÉEL, modèle par modèle : il vaut
 * donc aussi pour les modèles à venir, sans qu'on ait à penser à l'y inscrire.
 */

test('tout champ déclaré — modifiable ou converti — existe bien en base', function () {
    $violations = [];
    $modelesVus = 0;

    foreach (glob(app_path('Models/*.php')) as $fichier) {
        $classe = 'App\\Models\\' . basename($fichier, '.php');

        if (! class_exists($classe)) {
            continue;
        }

        try {
            $modele = new $classe;
        } catch (\Throwable) {
            continue;   // modèle abstrait ou à constructeur particulier
        }

        if (! $modele instanceof Model) {
            continue;
        }

        $table = $modele->getTable();

        if (! Schema::hasTable($table)) {
            $violations[] = "{$classe} : table « {$table} » absente du schéma";
            continue;
        }

        $modelesVus++;
        $colonnes = Schema::getColumnListing($table);

        foreach ($modele->getFillable() as $champ) {
            if (! in_array($champ, $colonnes, true)) {
                $violations[] = "{$classe}::\$fillable['{$champ}'] — pas de colonne « {$champ} » dans « {$table} »";
            }
        }

        /*
         * Les CASTS aussi, et pour la même raison. Retirer un champ de
         * `$fillable` en laissant son cast ne fait que déplacer le mensonge —
         * c'est arrivé sur Building::is_active pendant ce nettoyage même.
         *
         * Un cast peut légitimement porter sur un attribut calculé ; ceux du
         * projet ne le font pas, et le jour où l'un le fera, cette liste est
         * l'endroit où le déclarer explicitement plutôt que de désarmer la
         * vérification.
         */
        foreach (array_keys($modele->getCasts()) as $attribut) {
            if ($attribut === $modele->getKeyName()) {
                continue;                       // le cast implicite de la clé
            }

            if (! in_array($attribut, $colonnes, true)) {
                $violations[] = "{$classe}::\$casts['{$attribut}'] — pas de colonne « {$attribut} » dans « {$table} »";
            }
        }
    }

    /*
     * Le balayage doit porter sur quelque chose : zéro modèle inspecté ferait
     * passer ce test sans rien vérifier.
     */
    expect($modelesVus)->toBeGreaterThan(40);

    expect($violations)->toBe([], "Champs modifiables sans colonne :\n" . implode("\n", $violations));
});
