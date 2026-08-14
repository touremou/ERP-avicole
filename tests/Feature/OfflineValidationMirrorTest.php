<?php

use App\Services\Sync\SyncService;

uses(Tests\TestCase::class);

/*
 * LE TERRAIN SAISIT, LA FILE ACCEPTE, LE SERVEUR REFUSE.
 *
 * C'est le pire mode d'échec du travail hors ligne. Le technicien enregistre sa
 * saisie, l'écran la confirme, et le refus n'apparaît qu'à la synchronisation — des
 * heures plus tard, au bac « À corriger », alors qu'il a quitté le bâtiment. Ce qu'il
 * avait sous les yeux n'est plus là pour corriger.
 *
 * `mobile/src/offline/opRules.ts` existe pour ça : il refuse AVANT la mise en file,
 * quand la correction ne coûte rien. Encore faut-il qu'il couvre toutes les
 * opérations.
 *
 * ─── LE TROU TROUVÉ, ET IL ÉTAIT DE MOI ───
 *
 * Sur 38 types d'opérations acceptés par le serveur, 37 avaient leur règle côté
 * terrain. La manquante : `batch.upsert` — l'écran de mise en lot hors ligne écrit au
 * lot #210, pour Kérouané. Une bande créée sans réseau partait donc en file sans
 * aucun contrôle commun.
 *
 * L'écran portait bien ses propres vérifications, mais un contrôle d'écran ne protège
 * que cet écran-là. La barrière commune est dans opRules, et c'est là qu'elle
 * manquait.
 *
 * ─── POURQUOI CE GARDE-FOU EST DÉRIVÉ ───
 *
 * Il compare les DEUX déclarations, sans liste écrite à la main : le serveur est la
 * source (SyncService::types()), et chaque type doit avoir son miroir. Une opération
 * ajoutée demain sans règle tombera ici — pas dans six mois, au bac « À corriger »
 * d'un technicien.
 */

test('chaque opération acceptée par le serveur a sa règle de validation côté terrain', function () {
    $rules = file_get_contents(base_path('mobile/src/offline/opRules.ts'));

    preg_match_all("/'([a-z_]+\.[a-z_]+)'\s*:\s*\(/", $rules, $m);
    $client = $m[1];

    $server = array_keys(SyncService::types());

    // Garde-fou du garde-fou : sans matière, le test passerait sans rien vérifier.
    expect(count($server))->toBeGreaterThan(20);

    $missing = array_values(array_diff($server, $client));

    expect($missing)->toBe([], "Opération(s) sans règle dans opRules.ts — le terrain saisira, et le serveur refusera des heures plus tard :\n  "
        . implode("\n  ", $missing));
});

test('aucune règle du terrain ne porte sur une opération INEXISTANTE', function () {
    // L'inverse : une règle orpheline donne l'illusion d'une couverture, et refuse
    // peut-être une saisie sur un type que le serveur ne connaît plus.
    $rules = file_get_contents(base_path('mobile/src/offline/opRules.ts'));

    preg_match_all("/'([a-z_]+\.[a-z_]+)'\s*:\s*\(/", $rules, $m);

    $orphans = array_values(array_diff($m[1], array_keys(SyncService::types())));

    expect($orphans)->toBe([], "Règle(s) portant sur une opération inconnue du serveur :\n  " . implode("\n  ", $orphans));
});

test('la règle de mise en lot exige ce que le serveur exige', function () {
    // Le miroir doit porter sur les MÊMES champs obligatoires. Un miroir plus
    // permissif laisse passer ce que le serveur refusera ; plus strict, il bloque
    // une saisie que le serveur aurait acceptée — et le technicien ne comprend pas.
    $rules = file_get_contents(base_path('mobile/src/offline/opRules.ts'));

    preg_match('/\'batch\.upsert\':.*?\n  \},/s', $rules, $m);
    $regle = $m[0] ?? '';

    expect($regle)->not->toBe('');

    // Les champs `required` du serveur (hors uuid et updated_at, techniques).
    foreach (['code', 'type', 'building_id', 'initial_quantity', 'arrival_date'] as $champ) {
        expect($regle)->toContain($champ);
    }
});

test('le statut d’un lot est borné aux valeurs que le serveur accepte', function () {
    // Le serveur impose in:Actif,Terminé. Un statut arbitraire ferait échouer la
    // synchro pour un motif illisible depuis le terrain.
    $rules = file_get_contents(base_path('mobile/src/offline/opRules.ts'));

    expect($rules)->toContain("['Actif', 'Terminé']");
});
