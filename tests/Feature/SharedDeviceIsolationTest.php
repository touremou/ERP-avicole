<?php

/*
 * TÉLÉPHONE DE SERVICE PARTAGÉ — « MON activité », pas celle de l'appareil.
 *
 * Signalé depuis le terrain : « un employé connecté voit une liste d'activité
 * validé par d'autres qui ne sont pas ses activités ».
 *
 * Les deux tables locales de saisie — l'historique (`my_records`) et la file
 * d'envoi (`outbox`) — vivaient dans le navigateur SANS auteur, et rien ne les
 * purgeait au changement de compte. `AuthContext.logout()` documentait même ce
 * choix : « l'outbox appartient à l'appareil ». Sur un téléphone qui passe de
 * main en main, cela donnait DEUX défauts distincts :
 *
 *   1. VISIBILITÉ — le technicien suivant voyait l'historique du précédent, et
 *      pouvait « abandonner » ses saisies refusées. Or la règle de la maison est
 *      celle des tâches : hors admin, on ne voit et on n'agit que sur ce qui nous
 *      est affecté. L'historique n'y échappait pas.
 *
 *   2. ATTRIBUTION — plus grave et invisible : les saisies non poussées du
 *      premier partaient sous le JETON du second. Le serveur écrit toujours au
 *      nom du compte authentifié, donc il attribuait le travail au mauvais
 *      technicien — et les indicateurs par technicien (S2) créditaient la
 *      mauvaise personne, sans que rien ne signale l'erreur.
 *
 * Le correctif marque chaque saisie de son auteur, puis filtre l'affichage ET
 * l'envoi. Ces tests lisent les sources TypeScript : la logique est entièrement
 * côté client, il n'y a pas de route serveur à interroger.
 */

uses(Tests\TestCase::class);

function mobileSource(string $path): string
{
    return file_get_contents(base_path("mobile/{$path}"));
}

test('les deux tables de saisie locale portent un AUTEUR', function () {
    $db = mobileSource('src/offline/db.ts');

    // Le champ, sur les deux interfaces.
    expect($db)->toMatch('/interface OutboxEntry \{.*?user_id\?: number.*?\n\}/s')
        ->and($db)->toMatch('/interface MyRecord \{.*?user_id\?: number.*?\n\}/s');

    // Et il est INDEXÉ : filtrer par auteur ne doit pas balayer la table.
    expect($db)->toContain("outbox: 'op_uuid, status, created_at, user_id'")
        ->and($db)->toContain("my_records: 'uuid, type, sync_status, created_at, user_id'");
});

test('l’historique des versions Dexie n’est pas RÉÉCRIT', function () {
    // Une version déjà installée sur les téléphones du terrain est un fait
    // accompli : la modifier ferait divergere le schéma réel du schéma déclaré,
    // sans erreur visible. L'auteur doit donc arriver par une version NEUVE.
    $db = mobileSource('src/offline/db.ts');

    preg_match_all('/this\.version\((\d+)\)/', $db, $matches);
    $versions = array_map('intval', $matches[1]);

    expect($versions)->not->toBeEmpty()
        ->and(max($versions))->toBe(14)
        // Aucune version ne se déclare deux fois, et 1 → 14 sans trou.
        ->and(array_unique($versions))->toHaveCount(count($versions))
        ->and(range(1, 14))->toEqualCanonicalizing($versions);

    // La v14 rattache les lignes existantes, qui n'ont pas d'auteur connu.
    expect($db)->toMatch('/version\(14\).*?\.upgrade\(/s');
});

test('une saisie est marquée de son auteur au moment de la saisie', function () {
    $sync = mobileSource('src/offline/sync.ts');

    // L'auteur est lu de la session, pas déduit après coup.
    expect($sync)->toMatch('/enqueue\(/')
        ->and($sync)->toContain('(await session.me())?.user.id')
        // …et écrit dans LES DEUX tables : l'une nourrit l'affichage, l'autre
        // l'envoi. N'en marquer qu'une laisserait l'autre défaut en place.
        ->and(substr_count($sync, 'user_id: authorId'))->toBe(2);
});

test('« Mon activité » et « À corriger » sont filtrés sur le compte connecté', function () {
    $screen = mobileSource('src/features/mon-espace/MonEspaceScreen.tsx');

    // Un seul filtre, appliqué aux deux listes : deux implémentations
    // divergeraient (c'est le défaut qu'on passe la session à corriger).
    expect($screen)->toContain('row.user_id === me.user.id')
        ->and(substr_count($screen, 'const mine = '))->toBe(1)   // une seule définition…
        ->and(substr_count($screen, 'mine('))->toBe(2);          // …appliquée aux DEUX listes

    // Sans identité connue, on n'affiche RIEN plutôt que tout : le défaut par
    // défaut doit être la discrétion.
    expect($screen)->toContain('me?.user.id ? rows.filter');

    // La session arrive en asynchrone : l'écran doit RELIRE quand elle est
    // connue, sinon la liste reste vide au démarrage.
    expect($screen)->toContain('}, [me?.user.id])');
});

test('les saisies d’un autre compte ne partent pas sous MON jeton', function () {
    // Le cœur du défaut d'attribution. Le serveur écrit au nom du compte
    // authentifié : pousser la file de quelqu'un d'autre lui vole son travail.
    $sync = mobileSource('src/offline/sync.ts');

    expect($sync)->toContain('async function myPendingOperations')
        ->and($sync)->toContain('op.user_id === undefined || op.user_id === meId')
        // pushOutbox doit passer PAR CE FILTRE, et non relire l'outbox en direct.
        ->and($sync)->toMatch('/pushOutbox.*?await myPendingOperations\(\)/s')
        ->and($sync)->not->toContain("db.outbox.where('status').equals('pending').sortBy");
});

test('le changement de compte purge les miroirs, pas le travail de terrain', function () {
    $db = mobileSource('src/offline/db.ts');

    expect($db)->toContain('export async function clearPersonalData');

    // Ce qui part : les miroirs du serveur, retéléchargés au prochain pull.
    preg_match('/export async function clearPersonalData.*?\n\}/s', $db, $m);
    $body = $m[0];

    expect($body)->toContain('db.notifications.clear()')
        ->and($body)->toContain('db.tasks.clear()')
        // Ce qui RESTE : l'historique n'existe que là (rien ne le retélécharge)
        // et l'outbox peut porter des saisies non poussées. Les détruire serait
        // pire que le défaut : la confidentialité est déjà assurée par l'auteur.
        ->and($body)->not->toContain('my_records.clear()')
        ->and($body)->not->toContain('outbox.clear()')
        // Les écrans ouverts doivent se redessiner : une liste vidée en base mais
        // encore affichée n'aurait rien corrigé pour l'utilisateur.
        ->and($body)->toContain("'notifications:updated'")
        ->and($body)->toContain("'tasks:updated'");
});

test('le compte peut changer SANS déconnexion propre — c’est détecté', function () {
    // Batterie vide, application tuée : le téléphone de service change de main
    // sans que logout() ait tourné. Ne purger qu'à la déconnexion laisserait donc
    // le défaut entier dans le cas le plus courant.
    $auth = mobileSource('src/app/AuthContext.tsx');

    expect($auth)->toContain('clearPersonalData')
        // On relit QUI était là avant d'écraser la session.
        ->and($auth)->toMatch('/const previous = \(await getMeta<MeResponse>\(.me.\)\)\?\.user\.id/')
        ->and($auth)->toContain('if (previous && previous !== fresh.user.id)');

    // Et la déconnexion propre purge aussi.
    preg_match('/const logout = useCallback.*?\}, \[\]\)/s', $auth, $m);
    expect($m[0])->toContain('clearPersonalData()');
});

test('se reconnecter avec LE MÊME compte ne perd pas son écran', function () {
    // Le cas de loin le plus fréquent : le même technicien se reconnecte. Purger
    // à chaque login lui ferait retélécharger alertes et tâches pour rien, et
    // hors réseau il perdrait l'affichage jusqu'au retour du réseau.
    $auth = mobileSource('src/app/AuthContext.tsx');

    // La purge est conditionnée à un CHANGEMENT, pas au simple fait de se
    // connecter : `previous` doit exister ET différer.
    expect($auth)->toMatch('/if \(previous && previous !== fresh\.user\.id\) \{\s*await clearPersonalData\(\)\s*\}/');
});
