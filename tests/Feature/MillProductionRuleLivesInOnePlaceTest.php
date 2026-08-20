<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * UNE SECONDE RÈGLE DE PRODUCTION PROVENDERIE, QUE PERSONNE N'APPELAIT.
 *
 * `ProductionService` portait 106 lignes décrivant le cycle complet de la
 * provenderie — vérification des stocks, déstockage des matières premières,
 * usure machine, entrée du produit fini — et n'était appelé par rien. Aucune
 * injection, aucune instanciation, aucune route.
 *
 * Sa seule mention hors de lui-même était un COMMENTAIRE de
 * StockIntegrationService affirmant « MillProductionController (via
 * ProductionService) ». Une documentation qui désignait un chemin mort.
 *
 * ─── POURQUOI CE N'EST PAS ANODIN ───
 *
 * Le fichier s'ouvrait sur « BUG CORRIGÉ (B-26) » : syncMovement() appelé sans
 * unité tombait dans guessInputUnit(), qui devinait « Sac » pour tout nom
 * contenant chair/ponte/repro — et multipliait donc la quantité par 50.
 *
 * Qui lisait ce fichier pour comprendre la provenderie lisait une règle que
 * l'application n'applique pas. C'est la forme la plus coûteuse du code mort :
 * pas celle qui pèse, celle qui trompe.
 *
 * ─── CE QUE LE CHEMIN VIVANT FAIT, LUI ───
 *
 * `CompleteMillProduction` — le vrai — passe bien 'KG' explicitement, ET
 * vérifie la valeur de retour de syncMovement (ce que la copie morte ne
 * faisait pas). Il porte aussi les deux garde-fous que la copie annonçait, en
 * mieux : machine en panne, et stocks insuffisants agrégés en un seul message
 * plutôt qu'une exception au premier manquant.
 *
 * La copie était donc strictement inférieure à l'original. On la retire.
 */

test('la règle de production provenderie n’existe plus qu’à un endroit', function () {
    expect(file_exists(app_path('Services/ProductionService.php')))
        ->toBeFalse('Le doublon mort de la règle provenderie ne doit pas revenir.');

    expect(file_exists(app_path('Actions/MillProduction/CompleteMillProduction.php')))
        ->toBeTrue('Le chemin vivant, lui, doit rester.');
});

test('le chemin vivant passe l’unité explicitement', function () {
    /*
     * LA raison d'être du B-26, reportée sur le seul fichier qui compte
     * désormais : sans 'KG', guessInputUnit() devine « Sac » et multiplie la
     * production par 50.
     */
    $code = file_get_contents(app_path('Actions/MillProduction/CompleteMillProduction.php'));

    expect(str_contains($code, "'KG'"))
        ->toBeTrue('La provenderie produit en KG : l’unité doit rester explicite.');
});

test('la documentation ne désigne plus le chemin mort', function () {
    // Un commentaire qui nomme un appelant inexistant égare autant qu'un
    // mauvais code : il a survécu au fichier qu'il décrivait.
    $code = file_get_contents(app_path('Services/StockIntegrationService.php'));

    expect(str_contains($code, 'ProductionService'))->toBeFalse();
});
